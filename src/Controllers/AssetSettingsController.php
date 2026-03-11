<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

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
            'collections' => $config['collections'] ?? [],
            'preload' => $config['preload'] ?? [],
            'preconnect' => $config['preconnect'] ?? [],
            'assetsPrefix' => $config['assets_prefix'] ?? 'assets',
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
     * AJAX: Save asset collections configuration.
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

        // Update collections
        if (isset($input['collections']) && is_array($input['collections'])) {
            $collections = [];
            foreach ($input['collections'] as $name => $assets) {
                $safeName = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($name)));
                if (empty($safeName)) continue;

                $safeAssets = [];
                foreach ((array) $assets as $asset) {
                    if (empty($asset['path'])) continue;
                    $entry = ['path' => trim($asset['path'])];
                    if (isset($asset['priority'])) {
                        $entry['priority'] = max(0, (int) $asset['priority']);
                    }
                    if (!empty($asset['head'])) {
                        $entry['head'] = true;
                    }
                    $safeAssets[] = $entry;
                }
                if (!empty($safeAssets)) {
                    $collections[$safeName] = $safeAssets;
                }
            }
            $config['collections'] = $collections;
        }

        // Update preload
        if (isset($input['preload']) && is_array($input['preload'])) {
            $preload = [];
            foreach ($input['preload'] as $item) {
                if (empty($item['path']) || empty($item['as'])) continue;
                $allowedAs = ['script', 'style', 'font', 'image', 'fetch'];
                if (!in_array($item['as'], $allowedAs, true)) continue;
                $preload[] = ['path' => trim($item['path']), 'as' => $item['as']];
            }
            $config['preload'] = $preload;
        }

        // Update preconnect
        if (isset($input['preconnect']) && is_array($input['preconnect'])) {
            $preconnect = [];
            foreach ($input['preconnect'] as $item) {
                if (empty($item['url'])) continue;
                $url = trim($item['url']);
                if (!preg_match('#^https?://#', $url)) continue;
                $preconnect[] = [
                    'url' => $url,
                    'crossorigin' => !empty($item['crossorigin']),
                ];
            }
            $config['preconnect'] = $preconnect;
        }

        // Update simple settings
        if (isset($input['assets_prefix'])) {
            $config['assets_prefix'] = preg_replace('/[^a-z0-9_\/-]/', '', strtolower(trim($input['assets_prefix']))) ?: 'assets';
        }
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

        // Max file size: 5MB
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

        // Verify target is within public/assets
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

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = realpath($basePath . '/public');
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
        return ['assets_prefix' => 'assets', 'version_strategy' => 'timestamp', 'collections' => []];
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

    private function getPublicAssetsPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/public/assets';
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
