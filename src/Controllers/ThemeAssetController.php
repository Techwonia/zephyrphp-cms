<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\PermissionService;

class ThemeAssetController extends Controller
{
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
        $this->themeManager = new ThemeManager();
    }

    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied.']);
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    /**
     * AJAX: List all assets in a theme's assets/ directory.
     */
    public function list(string $slug): void
    {
        $this->requirePermission('themes.view');
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $themePath = $this->themeManager->getThemePath($slug);
        $assetsDir = $themePath . '/assets';

        $assets = ['css' => [], 'js' => [], 'images' => [], 'fonts' => []];

        if (!is_dir($assetsDir)) {
            echo json_encode(['assets' => $assets]);
            return;
        }

        $categories = [
            'css' => ['css'],
            'js' => ['js'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico'],
            'fonts' => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
            $fileInfo = [
                'path' => 'assets/' . $relativePath,
                'name' => $file->getFilename(),
                'size' => $this->formatSize($file->getSize()),
                'modified' => date('Y-m-d H:i', $file->getMTime()),
            ];

            $categorized = false;
            foreach ($categories as $cat => $exts) {
                if (in_array($ext, $exts, true)) {
                    $assets[$cat][] = $fileInfo;
                    $categorized = true;
                    break;
                }
            }
            if (!$categorized) {
                $assets['other'][] = $fileInfo;
            }
        }

        echo json_encode(['assets' => $assets]);
    }

    /**
     * Handle asset file upload to theme's assets/ directory.
     */
    public function upload(string $slug): void
    {
        $this->requirePermission('themes.edit');
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $themePath = $this->themeManager->getThemePath($slug);
        $category = $_POST['category'] ?? 'images';

        // Validate category
        $allowedCategories = ['css', 'js', 'images', 'fonts'];
        if (!in_array($category, $allowedCategories, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset category']);
            return;
        }

        if (empty($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error']);
            return;
        }

        $file = $_FILES['asset_file'];
        $originalName = basename($file['name']);

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $originalName);
        if (empty($safeName) || $safeName === '-') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }

        // Validate extension by category
        $allowedExts = [
            'css' => ['css', 'map'],
            'js' => ['js', 'map'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico'],
            'fonts' => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
        ];

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts[$category] ?? [], true)) {
            http_response_code(400);
            echo json_encode(['error' => "File type .{$ext} is not allowed for {$category}"]);
            return;
        }

        // Validate MIME type for images
        if ($category === 'images') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
            if (!in_array($mime, $allowedMimes, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid image file']);
                return;
            }
        }

        // Max file size: 10MB
        if ($file['size'] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Maximum size is 10MB.']);
            return;
        }

        $targetDir = $themePath . '/assets/' . $category;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Verify target is within theme
        $realTheme = realpath($themePath);
        $realTarget = realpath($targetDir);
        if (!$realTheme || !$realTarget || !str_starts_with($realTarget, $realTheme)) {
            http_response_code(403);
            echo json_encode(['error' => 'Path traversal detected']);
            return;
        }

        $targetPath = $targetDir . '/' . $safeName;

        // Don't overwrite without explicit flag
        if (file_exists($targetPath) && empty($_POST['overwrite'])) {
            http_response_code(409);
            echo json_encode(['error' => 'File already exists. Set overwrite=1 to replace.']);
            return;
        }

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode([
                'success' => true,
                'file' => [
                    'path' => "assets/{$category}/{$safeName}",
                    'name' => $safeName,
                    'size' => $this->formatSize(filesize($targetPath)),
                ],
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to move uploaded file']);
        }
    }

    /**
     * AJAX: Delete an asset file from a theme.
     */
    public function delete(string $slug): void
    {
        $this->requirePermission('themes.edit');
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $filePath = $input['path'] ?? '';

        if (empty($filePath) || !str_starts_with($filePath, 'assets/')) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset path']);
            return;
        }

        // Block path traversal
        if (str_contains($filePath, '..')) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $themePath = realpath($this->themeManager->getThemePath($slug));
        if (!$themePath) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme directory not found']);
            return;
        }

        $fullPath = $themePath . '/' . $filePath;
        $realPath = realpath($fullPath);

        if (!$realPath || !str_starts_with($realPath, $themePath . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        if (!file_exists($realPath) || !is_file($realPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }

        if (unlink($realPath)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete file']);
        }
    }

    /**
     * Serve a theme asset file (for themes not in public/).
     */
    public function serve(string $slug, string $path): void
    {
        $themePath = realpath($this->themeManager->getThemePath($slug));
        if (!$themePath) {
            http_response_code(404);
            return;
        }

        // Block path traversal
        if (str_contains($path, '..')) {
            http_response_code(403);
            return;
        }

        $fullPath = $themePath . '/assets/' . $path;
        $realPath = realpath($fullPath);

        if (!$realPath || !str_starts_with($realPath, $themePath . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            http_response_code(404);
            return;
        }

        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'map' => 'application/json',
        ];

        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: public, max-age=31536000');
        readfile($realPath);
        exit;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
