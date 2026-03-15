<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\PermissionService;

class ThemeCodeEditorController extends Controller
{
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
        $this->themeManager = new ThemeManager();
    }

    private function requireAdmin(): bool
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return false;
        }
        if (!PermissionService::can('themes.edit')) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
            return false;
        }
        return true;
    }

    /**
     * Code editor page for a theme.
     */
    public function index(string $slug): string
    {
        if (!$this->requireAdmin()) return '';

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return '';
        }

        $pages = $this->themeManager->getPages($slug);

        return $this->render('cms::themes/code-editor', [
            'theme' => $theme,
            'pages' => $pages,
            'user' => Auth::user(),
        ]);
    }

    /**
     * AJAX: List all editable files in the theme, grouped by directory.
     */
    public function listFiles(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        // Get the standard file tree from ThemeManager
        $files = $this->themeManager->listFiles($slug);

        // Add asset files (css, js) from the assets directory
        $themePath = $this->themeManager->getThemePath($slug);
        $assetsDir = $themePath . '/assets';
        if (is_dir($assetsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    if (in_array($ext, ['css', 'js', 'svg', 'json', 'txt', 'html'], true)) {
                        $relative = 'assets/' . str_replace('\\', '/', $iterator->getSubPathName());
                        $files['assets'][] = $relative;
                    }
                }
            }
        }

        // Scan public assets directory (css, js, images, fonts)
        $publicAssetsDir = $this->themeManager->getThemeAssetsPath($slug);
        if (is_dir($publicAssetsDir)) {
            $publicSubdirs = ['css', 'js', 'images', 'fonts'];
            foreach ($publicSubdirs as $subdir) {
                $subdirPath = $publicAssetsDir . '/' . $subdir;
                if (!is_dir($subdirPath)) continue;

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($subdirPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relative = 'public/' . $subdir . '/' . str_replace('\\', '/', $iterator->getSubPathName());
                        $files['public'][] = $relative;
                    }
                }
            }
        }

        // Add root files if they exist
        $rootFiles = ['theme.json', 'pages.json'];
        foreach ($rootFiles as $rootFile) {
            if (file_exists($themePath . '/' . $rootFile)) {
                $files['root'][] = $rootFile;
            }
        }

        echo json_encode(['files' => $files]);
    }

    /**
     * AJAX: Read a single file's content.
     */
    public function readFile(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $path = $this->input('path', '');
        $error = $this->validateFilePath($path);
        if ($error) {
            http_response_code(400);
            echo json_encode(['error' => $error]);
            return;
        }

        // Root files (theme.json, pages.json)
        if ($this->isRootFile($path)) {
            $themePath = realpath($this->themeManager->getThemePath($slug));
            if (!$themePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Theme directory not found']);
                return;
            }

            $fullPath = $themePath . DIRECTORY_SEPARATOR . $path;
            $realPath = realpath($fullPath);
            if (!$realPath || !str_starts_with($realPath, $themePath . DIRECTORY_SEPARATOR)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (!file_exists($realPath)) {
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                return;
            }

            // Max file size check: 1MB
            if (filesize($realPath) > 1048576) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large (max 1MB)']);
                return;
            }

            $content = file_get_contents($realPath);
            echo json_encode(['content' => $content, 'path' => $path]);
            return;
        }

        // Public assets files — resolve against public assets path
        if (str_starts_with($path, 'public/')) {
            $relativePath = substr($path, 7); // strip 'public/'
            $assetsBasePath = realpath($this->themeManager->getThemeAssetsPath($slug));
            if (!$assetsBasePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Public assets directory not found']);
                return;
            }

            $fullPath = $assetsBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $realPath = realpath($fullPath);
            if (!$realPath || !str_starts_with($realPath, $assetsBasePath . DIRECTORY_SEPARATOR)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (!file_exists($realPath)) {
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                return;
            }

            if (filesize($realPath) > 1048576) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large (max 1MB)']);
                return;
            }

            $content = file_get_contents($realPath);
            echo json_encode(['content' => $content, 'path' => $path]);
            return;
        }

        // Prefixed files — use ThemeManager
        $content = $this->themeManager->readFile($path, $slug);
        if ($content === null) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found or access denied']);
            return;
        }

        // Max file size check: 1MB
        if (strlen($content) > 1048576) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large (max 1MB)']);
            return;
        }

        echo json_encode(['content' => $content, 'path' => $path]);
    }

    /**
     * AJAX: Save (overwrite) a file.
     */
    public function saveFile(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['path']) || !isset($input['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body — path and content required']);
            return;
        }

        $path = $input['path'];
        $content = $input['content'];

        $error = $this->validateFilePath($path);
        if ($error) {
            http_response_code(400);
            echo json_encode(['error' => $error]);
            return;
        }

        if (!$this->isAllowedExtension($path)) {
            http_response_code(400);
            echo json_encode(['error' => 'File extension not allowed']);
            return;
        }

        // Public assets files
        if (str_starts_with($path, 'public/')) {
            $relativePath = substr($path, 7); // strip 'public/'
            $assetsBasePath = realpath($this->themeManager->getThemeAssetsPath($slug));
            if (!$assetsBasePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Public assets directory not found']);
                return;
            }

            $fullPath = $assetsBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            // Path traversal protection
            $realDir = realpath(dirname($fullPath));
            if (!$realDir || !str_starts_with($realDir, $assetsBasePath)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (file_put_contents($fullPath, $content) === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to write file']);
                return;
            }

            echo json_encode(['success' => true]);
            return;
        }

        // Root files
        if ($this->isRootFile($path)) {
            $themePath = realpath($this->themeManager->getThemePath($slug));
            if (!$themePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Theme directory not found']);
                return;
            }

            $fullPath = $themePath . DIRECTORY_SEPARATOR . $path;

            // Path traversal protection: ensure the target is within the theme
            $realDir = realpath(dirname($fullPath));
            if (!$realDir || !str_starts_with($realDir, $themePath)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (file_put_contents($fullPath, $content) === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to write file']);
                return;
            }

            echo json_encode(['success' => true]);
            return;
        }

        // Prefixed files
        if (!$this->themeManager->writeFile($path, $content, $slug)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write file']);
            return;
        }

        echo json_encode(['success' => true]);
    }

    /**
     * AJAX: Create a new file (must not already exist).
     */
    public function createFile(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['path'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body — path required']);
            return;
        }

        $path = $input['path'];
        $content = $input['content'] ?? '';

        $error = $this->validateFilePath($path);
        if ($error) {
            http_response_code(400);
            echo json_encode(['error' => $error]);
            return;
        }

        if (!$this->isAllowedExtension($path)) {
            http_response_code(400);
            echo json_encode(['error' => 'File extension not allowed']);
            return;
        }

        // Public assets files
        if (str_starts_with($path, 'public/')) {
            $relativePath = substr($path, 7); // strip 'public/'
            $assetsBasePath = realpath($this->themeManager->getThemeAssetsPath($slug));
            if (!$assetsBasePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Public assets directory not found']);
                return;
            }

            $fullPath = $assetsBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (file_exists($fullPath)) {
                http_response_code(409);
                echo json_encode(['error' => 'File already exists']);
                return;
            }

            // Ensure parent directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $realDir = realpath($dir);
            if (!$realDir || !str_starts_with($realDir, $assetsBasePath)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (file_put_contents($fullPath, $content) === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create file']);
                return;
            }

            echo json_encode(['success' => true]);
            return;
        }

        // Check file doesn't already exist
        $themePath = realpath($this->themeManager->getThemePath($slug));
        if (!$themePath) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme directory not found']);
            return;
        }

        $fullPath = $themePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (file_exists($fullPath)) {
            http_response_code(409);
            echo json_encode(['error' => 'File already exists']);
            return;
        }

        // Root files
        if ($this->isRootFile($path)) {
            $realDir = realpath(dirname($fullPath));
            if (!$realDir || !str_starts_with($realDir, $themePath)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            if (file_put_contents($fullPath, $content) === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create file']);
                return;
            }

            echo json_encode(['success' => true]);
            return;
        }

        // Prefixed files
        if (!$this->themeManager->writeFile($path, $content, $slug)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create file']);
            return;
        }

        echo json_encode(['success' => true]);
    }

    /**
     * AJAX: Delete a file.
     */
    public function deleteFile(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['path'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body — path required']);
            return;
        }

        $path = $input['path'];

        $error = $this->validateFilePath($path);
        if ($error) {
            http_response_code(400);
            echo json_encode(['error' => $error]);
            return;
        }

        // Block deletion of critical files
        $criticalFiles = [
            'theme.json',
            'pages.json',
            'config/settings_data.json',
            'config/settings_schema.json',
            'layouts/base.twig',
        ];
        if (in_array($path, $criticalFiles, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot delete critical theme file']);
            return;
        }

        $themePath = realpath($this->themeManager->getThemePath($slug));
        if (!$themePath) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme directory not found']);
            return;
        }

        $fullPath = $themePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realPath = realpath($fullPath);

        if (!$realPath || !str_starts_with($realPath, $themePath . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        if (!file_exists($realPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }

        if (!unlink($realPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete file']);
            return;
        }

        echo json_encode(['success' => true]);
    }

    /**
     * AJAX: Create a new folder within the theme.
     */
    public function createFolder(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['path'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body — path required']);
            return;
        }

        $path = $input['path'];

        if (empty($path) || str_contains($path, '..')) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path — directory traversal not allowed']);
            return;
        }

        // Must start with an allowed prefix
        $allowedPrefixes = ['layouts/', 'templates/', 'sections/', 'config/', 'controllers/', 'assets/', 'public/'];
        $isAllowedPrefix = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isAllowedPrefix = true;
                break;
            }
        }

        if (!$isAllowedPrefix) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path — must be in an allowed directory']);
            return;
        }

        // Resolve base path depending on public/ prefix
        if (str_starts_with($path, 'public/')) {
            $relativePath = substr($path, 7); // strip 'public/'
            $basePath = realpath($this->themeManager->getThemeAssetsPath($slug));
            if (!$basePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Public assets directory not found']);
                return;
            }
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        } else {
            $basePath = realpath($this->themeManager->getThemePath($slug));
            if (!$basePath) {
                http_response_code(404);
                echo json_encode(['error' => 'Theme directory not found']);
                return;
            }
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        if (is_dir($fullPath)) {
            http_response_code(409);
            echo json_encode(['error' => 'Folder already exists']);
            return;
        }

        if (!mkdir($fullPath, 0755, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create folder']);
            return;
        }

        // Verify the created directory is within the allowed base
        $realCreated = realpath($fullPath);
        if (!$realCreated || !str_starts_with($realCreated, $basePath)) {
            // Roll back: remove the directory if it escaped the base
            @rmdir($fullPath);
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        echo json_encode(['success' => true]);
    }

    /**
     * AJAX: Rename/move a file within the theme.
     */
    public function renameFile(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['oldPath']) || !isset($input['newPath'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body — oldPath and newPath required']);
            return;
        }

        $oldPath = $input['oldPath'];
        $newPath = $input['newPath'];

        // Validate both paths
        $oldError = $this->validateFilePath($oldPath);
        if ($oldError) {
            http_response_code(400);
            echo json_encode(['error' => 'Old path: ' . $oldError]);
            return;
        }

        $newError = $this->validateFilePath($newPath);
        if ($newError) {
            http_response_code(400);
            echo json_encode(['error' => 'New path: ' . $newError]);
            return;
        }

        // Resolve old and new full paths
        $oldFullPath = $this->resolveFilePathForRename($slug, $oldPath);
        $newFullPath = $this->resolveFilePathForRename($slug, $newPath);

        if (!$oldFullPath || !$newFullPath) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme directory not found']);
            return;
        }

        // Verify old file exists
        if (!file_exists($oldFullPath['full'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Source file not found']);
            return;
        }

        // Path traversal protection on old path
        $realOld = realpath($oldFullPath['full']);
        if (!$realOld || !str_starts_with($realOld, $oldFullPath['base'] . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        // Ensure new file doesn't already exist
        if (file_exists($newFullPath['full'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Destination file already exists']);
            return;
        }

        // Ensure destination directory exists
        $destDir = dirname($newFullPath['full']);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Path traversal protection on new path's parent
        $realDestDir = realpath($destDir);
        if (!$realDestDir || !str_starts_with($realDestDir, $newFullPath['base'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        if (!rename($realOld, $newFullPath['full'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to rename file']);
            return;
        }

        echo json_encode(['success' => true]);
    }

    /**
     * Resolve a file path to its full filesystem path and base directory for rename operations.
     */
    private function resolveFilePathForRename(string $slug, string $path): ?array
    {
        if (str_starts_with($path, 'public/')) {
            $relativePath = substr($path, 7);
            $basePath = realpath($this->themeManager->getThemeAssetsPath($slug));
            if (!$basePath) return null;
            return [
                'base' => $basePath,
                'full' => $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
            ];
        }

        $basePath = realpath($this->themeManager->getThemePath($slug));
        if (!$basePath) return null;
        return [
            'base' => $basePath,
            'full' => $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
        ];
    }

    // --- Private helpers ---

    /**
     * Validate a file path for safety.
     */
    private function validateFilePath(string $path): ?string
    {
        if (empty($path)) {
            return 'Path is required';
        }

        if (str_contains($path, '..')) {
            return 'Invalid path — directory traversal not allowed';
        }

        // Must start with an allowed prefix or be a known root file
        $allowedPrefixes = ['layouts/', 'templates/', 'snippets/', 'sections/', 'config/', 'controllers/', 'assets/', 'public/'];
        $isAllowedPrefix = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isAllowedPrefix = true;
                break;
            }
        }

        if (!$isAllowedPrefix && !$this->isRootFile($path)) {
            return 'Invalid path — must be in an allowed directory or be theme.json/pages.json';
        }

        return null;
    }

    /**
     * Check if a path is a known root file.
     */
    private function isRootFile(string $path): bool
    {
        return in_array($path, ['theme.json', 'pages.json'], true);
    }

    /**
     * Check if the file extension is allowed for editing.
     */
    private function isAllowedExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['twig', 'json', 'css', 'js', 'html', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot'], true);
    }
}
