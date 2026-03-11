<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ThemeManager;

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

        // Get theme assets info
        $themeManager = new ThemeManager();
        $liveTheme = $themeManager->getEffectiveTheme();
        $themeAssets = $this->scanThemeAssets($themeManager, $liveTheme);
        $publicThemePath = $themeManager->getPublicThemeAssetsPath();
        $isPublished = is_dir($publicThemePath) && (count(scandir($publicThemePath)) > 2);

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
            'liveTheme' => $liveTheme,
            'themeAssets' => $themeAssets,
            'themeAssetsPublished' => $isPublished,
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

        // Check if it's an env() call pattern
        if (is_string($value) && preg_match('/^env\(/', $value)) {
            return $value;
        }

        return "'" . addcslashes((string) $value, "'\\") . "'";
    }

    /**
     * AJAX: Re-publish live theme assets to /public/theme/.
     */
    public function republish(): void
    {
        $this->requirePermission('settings.edit');
        header('Content-Type: application/json');

        $themeManager = new ThemeManager();
        $liveTheme = $themeManager->getEffectiveTheme();

        if ($themeManager->publishAssets($liveTheme)) {
            echo json_encode(['success' => true, 'theme' => $liveTheme]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to publish theme assets']);
        }
    }

    private function scanThemeAssets(ThemeManager $themeManager, string $slug): array
    {
        $themePath = $themeManager->getThemePath($slug);
        $assetsDir = $themePath . '/assets';
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
                        'path' => 'theme/' . $relativePath, // public URL path
                        'themePath' => 'assets/' . $relativePath, // path inside theme
                    ];
                    break;
                }
            }
        }

        return $assets;
    }

    private function getConfigPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/config/assets.php';
    }
}
