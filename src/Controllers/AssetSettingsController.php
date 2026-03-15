<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Asset\Asset;

class AssetSettingsController extends Controller
{
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

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $config = $this->loadAssetsConfig();
        $publicAssets = $this->scanPublicAssets();

        return $this->render('cms::settings/assets', [
            'versionStrategy' => $config['version_strategy'] ?? 'timestamp',
            'globalVersion' => $config['global_version'] ?? '1.0.0',
            'cdnUrl' => $config['cdn_url'] ?? '',
            'cdnEnabled' => $config['cdn_enabled'] ?? false,
            'minify' => $config['minify'] ?? false,
            'manifest' => $config['manifest'] ?? '',
            'publicAssets' => $publicAssets,
            'user' => Auth::user(),
        ]);
    }

    /**
     * AJAX: Save asset settings.
     */
    public function update(): void
    {
        $this->requirePermission('settings.edit');
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON input']);
            return;
        }

        $config = $this->loadAssetsConfig();

        if (isset($input['version_strategy'])) {
            $allowed = ['timestamp', 'hash', 'manifest', 'global', 'none'];
            if (in_array($input['version_strategy'], $allowed, true)) {
                $config['version_strategy'] = $input['version_strategy'];
            }
        }
        if (isset($input['global_version'])) {
            $config['global_version'] = preg_replace('/[^a-z0-9._-]/', '', trim($input['global_version'])) ?: '1.0.0';
        }
        if (isset($input['cdn_url'])) {
            $cdn = trim($input['cdn_url']);
            $config['cdn_url'] = ($cdn && preg_match('#^https?://#', $cdn)) ? $cdn : null;
            $config['cdn_enabled'] = !empty($cdn);
        }
        if (isset($input['minify'])) {
            $config['minify'] = (bool) $input['minify'];
        }
        if (isset($input['manifest'])) {
            $config['manifest'] = trim($input['manifest']) ?: null;
        }

        if ($this->saveAssetsConfig($config)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save config/assets.php']);
        }
    }

    /**
     * AJAX: Minify all CSS and JS files in public/assets/.
     */
    public function minify(): void
    {
        $this->requirePermission('settings.edit');
        header('Content-Type: application/json');

        if (!class_exists(\MatthiasMullie\Minify\CSS::class)) {
            http_response_code(500);
            echo json_encode(['error' => 'Minifier not installed. Run: composer require matthiasmullie/minify']);
            return;
        }

        $assetsDir = $this->getPublicAssetsPath();
        if (!is_dir($assetsDir)) {
            echo json_encode(['success' => true, 'minified' => 0, 'skipped' => 0]);
            return;
        }

        $minified = 0;
        $skipped = 0;
        $errors = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $ext = strtolower($file->getExtension());
            $filePath = $file->getPathname();
            $fileName = $file->getFilename();

            // Skip already minified files and source maps
            if (str_ends_with($fileName, '.min.css') || str_ends_with($fileName, '.min.js') || $ext === 'map') {
                $skipped++;
                continue;
            }

            if ($ext !== 'css' && $ext !== 'js') continue;

            $minPath = preg_replace('/\.' . $ext . '$/', '.min.' . $ext, $filePath);

            try {
                $source = file_get_contents($filePath);
                if ($source === false || trim($source) === '') {
                    $skipped++;
                    continue;
                }

                if ($ext === 'css') {
                    $minifier = new \MatthiasMullie\Minify\CSS($source);
                } else {
                    $minifier = new \MatthiasMullie\Minify\JS($source);
                }

                $result = $minifier->minify();

                if (file_put_contents($minPath, $result) !== false) {
                    $minified++;
                } else {
                    $errors[] = $fileName;
                }
            } catch (\Throwable $e) {
                $errors[] = $fileName . ': ' . $e->getMessage();
            }
        }

        // Also minify theme assets
        $themesDir = $this->getPublicPath() . '/themes';
        if (is_dir($themesDir)) {
            $themeIterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($themesDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($themeIterator as $file) {
                if (!$file->isFile()) continue;

                $ext = strtolower($file->getExtension());
                $filePath = $file->getPathname();
                $fileName = $file->getFilename();

                if (str_ends_with($fileName, '.min.css') || str_ends_with($fileName, '.min.js') || $ext === 'map') {
                    $skipped++;
                    continue;
                }

                if ($ext !== 'css' && $ext !== 'js') continue;

                $minPath = preg_replace('/\.' . $ext . '$/', '.min.' . $ext, $filePath);

                try {
                    $source = file_get_contents($filePath);
                    if ($source === false || trim($source) === '') {
                        $skipped++;
                        continue;
                    }

                    if ($ext === 'css') {
                        $minifier = new \MatthiasMullie\Minify\CSS($source);
                    } else {
                        $minifier = new \MatthiasMullie\Minify\JS($source);
                    }

                    $result = $minifier->minify();

                    if (file_put_contents($minPath, $result) !== false) {
                        $minified++;
                    } else {
                        $errors[] = $fileName;
                    }
                } catch (\Throwable $e) {
                    $errors[] = $fileName . ': ' . $e->getMessage();
                }
            }
        }

        $response = ['success' => true, 'minified' => $minified, 'skipped' => $skipped];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        echo json_encode($response);
    }

    /**
     * AJAX: Upload a file to public/assets/{category}/.
     */
    public function upload(): void
    {
        $this->requirePermission('settings.edit');
        header('Content-Type: application/json');

        $category = $_POST['category'] ?? 'css';
        $allowedCategories = ['css', 'js', 'fonts'];
        if (!in_array($category, $allowedCategories, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset category']);
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
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $originalName);
        if (empty($safeName) || $safeName === '-') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }

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

        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Maximum size is 5MB.']);
            return;
        }

        $assetsDir = $this->getPublicAssetsPath();
        $targetDir = $assetsDir . '/' . $category;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $realAssets = realpath($assetsDir);
        $realTarget = realpath($targetDir);
        if (!$realAssets || !$realTarget || !str_starts_with($realTarget, $realAssets)) {
            http_response_code(403);
            echo json_encode(['error' => 'Path traversal detected']);
            return;
        }

        $targetPath = $targetDir . '/' . $safeName;

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
     * AJAX: Delete a file from public/assets/.
     */
    public function deleteFile(): void
    {
        $this->requirePermission('settings.edit');
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $filePath = $input['path'] ?? '';

        if (empty($filePath) || !str_starts_with($filePath, 'assets/')) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid asset path']);
            return;
        }

        if (str_contains($filePath, '..')) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $publicPath = realpath($this->getPublicPath());
        if (!$publicPath) {
            http_response_code(404);
            echo json_encode(['error' => 'Public directory not found']);
            return;
        }

        $fullPath = $publicPath . '/' . $filePath;
        $realPath = realpath($fullPath);

        if (!$realPath || !str_starts_with($realPath, $publicPath . DIRECTORY_SEPARATOR . 'assets')) {
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
     * AJAX: List all files in public/assets/.
     */
    public function listFiles(): void
    {
        $this->requirePermission('settings.view');
        header('Content-Type: application/json');

        echo json_encode(['assets' => $this->scanPublicAssets()]);
    }

    private function scanPublicAssets(): array
    {
        $assetsDir = $this->getPublicAssetsPath();
        $assets = ['css' => [], 'js' => [], 'fonts' => []];

        if (!is_dir($assetsDir)) {
            return $assets;
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

            foreach ($categories as $cat => $exts) {
                if (in_array($ext, $exts, true)) {
                    $assets[$cat][] = [
                        'name' => $file->getFilename(),
                        'path' => 'assets/' . $relativePath,
                        'size' => $this->formatSize($file->getSize()),
                        'modified' => date('Y-m-d H:i', $file->getMTime()),
                    ];
                    break;
                }
            }
        }

        return $assets;
    }

    private function loadAssetsConfig(): array
    {
        $path = $this->getConfigPath();
        if (file_exists($path)) {
            return (array) (require $path);
        }
        return ['version_strategy' => 'timestamp'];
    }

    private function saveAssetsConfig(array $config): bool
    {
        $path = $this->getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $php = "<?php\n\nreturn " . $this->exportArray($config, 0) . ";\n";

        return file_put_contents($path, $php) !== false;
    }

    private function exportArray(array $array, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $closingIndent = str_repeat('    ', $depth);
        $isSequential = array_is_list($array);
        $lines = [];

        foreach ($array as $key => $value) {
            $keyPart = $isSequential ? '' : $this->exportValue($key) . ' => ';

            if (is_array($value)) {
                $lines[] = $indent . $keyPart . $this->exportArray($value, $depth + 1);
            } else {
                $lines[] = $indent . $keyPart . $this->exportValue($value);
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n" . implode(",\n", $lines) . ",\n{$closingIndent}]";
    }

    private function exportValue(mixed $value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string) $value;

        if (is_string($value) && preg_match('/^env\(/', $value)) {
            return $value;
        }

        return "'" . addcslashes((string) $value, "'\\") . "'";
    }

    private function getConfigPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/config/assets.php';
    }

    private function getPublicPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/public';
    }

    private function getPublicAssetsPath(): string
    {
        return $this->getPublicPath() . '/assets';
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
