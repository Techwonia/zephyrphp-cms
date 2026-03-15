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

        return $this->render('cms::themes/code-editor', [
            'theme' => $theme,
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
        $allowedPrefixes = ['layouts/', 'templates/', 'snippets/', 'sections/', 'config/', 'controllers/', 'assets/'];
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
        return in_array($ext, ['twig', 'json', 'css', 'js', 'html', 'txt', 'svg'], true);
    }
}
