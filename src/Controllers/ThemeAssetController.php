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
     * AJAX: List all assets in a theme's public/themes/{slug}/ directory.
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

        $assetsDir = $this->themeManager->getThemeAssetsPath($slug);

        $assets = ['css' => [], 'js' => [], 'fonts' => []];

        if (!is_dir($assetsDir)) {
            echo json_encode(['assets' => $assets]);
            return;
        }

        $categories = [
            'css' => ['css', 'map'],
            'js' => ['js', 'map'],
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
                'path' => $relativePath,
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

        $assetsBasePath = $this->themeManager->getThemeAssetsPath($slug);
        $category = $_POST['category'] ?? 'css';

        // Validate category — images use Media, not theme assets
        $allowedCategories = ['css', 'js', 'fonts'];
        if (!in_array($category, $allowedCategories, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset category. Use Media for images.']);
            return;
        }

        if (empty($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing server temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            ];
            $code = $_FILES['asset_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            http_response_code(400);
            echo json_encode(['error' => $errorMessages[$code] ?? 'Upload error (code: ' . $code . ')']);
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
            'fonts' => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
        ];

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts[$category] ?? [], true)) {
            http_response_code(400);
            echo json_encode(['error' => "File type .{$ext} is not allowed for {$category}"]);
            return;
        }

        // Validate MIME type for fonts
        if ($category === 'fonts') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedMimes = ['font/woff', 'font/woff2', 'font/ttf', 'font/otf', 'font/sfnt',
                'application/font-woff', 'application/font-woff2', 'application/x-font-ttf',
                'application/x-font-opentype', 'application/vnd.ms-fontobject',
                'application/octet-stream', 'font/collection'];
            if (!in_array($mime, $allowedMimes, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid font file (detected: ' . $mime . ')']);
                return;
            }
        }

        // Max file size: 5MB for theme assets
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Maximum size is 5MB.']);
            return;
        }

        $targetDir = $assetsBasePath . '/' . $category;
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create assets directory']);
                return;
            }
        }

        // Verify target is within public themes directory
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $realPublic = realpath($basePath . '/public/themes');
        $realTarget = realpath($targetDir);
        if (!$realPublic || !$realTarget || !str_starts_with($realTarget, $realPublic . DIRECTORY_SEPARATOR)) {
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
                    'path' => "{$category}/{$safeName}",
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

        if (empty($filePath)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset path']);
            return;
        }

        // Block path traversal
        if (str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $assetsPath = realpath($this->themeManager->getThemeAssetsPath($slug));
        if (!$assetsPath) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme assets directory not found']);
            return;
        }

        $fullPath = $assetsPath . '/' . $filePath;
        $realPath = realpath($fullPath);

        if (!$realPath || !str_starts_with($realPath, $assetsPath . DIRECTORY_SEPARATOR)) {
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
