<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;

class SystemSettingsController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $systemInfo = [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        $appSettings = [
            'APP_NAME' => env('APP_NAME', ''),
            'APP_ENV' => env('APP_ENV', env('ENV', 'dev')),
            'APP_DEBUG' => env('APP_DEBUG', 'true'),
            'APP_URL' => env('APP_URL', ''),
            'APP_TIMEZONE' => env('APP_TIMEZONE', 'UTC'),
        ];

        $authSettings = [
            'SESSION_LIFETIME' => env('SESSION_LIFETIME', '120'),
        ];

        $securitySettings = [
            'CSP_ENABLED' => env('CSP_ENABLED', 'true'),
            'CSP_LEVEL' => env('CSP_LEVEL', 'moderate'),
            'CSP_REPORT_URI' => env('CSP_REPORT_URI', ''),
            'CSP_SCRIPT_SRC' => env('CSP_SCRIPT_SRC', ''),
            'CSP_STYLE_SRC' => env('CSP_STYLE_SRC', ''),
            'CSP_IMG_SRC' => env('CSP_IMG_SRC', ''),
            'CSP_FONT_SRC' => env('CSP_FONT_SRC', ''),
            'CSP_CONNECT_SRC' => env('CSP_CONNECT_SRC', ''),
            'CSP_FRAME_SRC' => env('CSP_FRAME_SRC', ''),
            'CSP_MEDIA_SRC' => env('CSP_MEDIA_SRC', ''),
            'HSTS_MAX_AGE' => env('HSTS_MAX_AGE', '31536000'),
            'HSTS_PRELOAD' => env('HSTS_PRELOAD', 'false'),
            'USE_ISOLATION_HEADERS' => env('USE_ISOLATION_HEADERS', 'false'),
        ];

        $extensions = get_loaded_extensions();
        sort($extensions);

        return $this->render('cms::settings/system', [
            'systemInfo' => $systemInfo,
            'appSettings' => $appSettings,
            'authSettings' => $authSettings,
            'securitySettings' => $securitySettings,
            'extensions' => $extensions,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [
            // Application
            'APP_NAME' => trim($this->input('APP_NAME', '')),
            'APP_ENV' => trim($this->input('APP_ENV', 'dev')),
            'APP_DEBUG' => $this->input('APP_DEBUG', 'false'),
            'APP_URL' => trim($this->input('APP_URL', '')),
            'APP_TIMEZONE' => trim($this->input('APP_TIMEZONE', 'UTC')),
            // Session
            'SESSION_LIFETIME' => trim($this->input('SESSION_LIFETIME', '120')),
            // Security & CSP
            'CSP_ENABLED' => $this->input('CSP_ENABLED', 'true'),
            'CSP_LEVEL' => trim($this->input('CSP_LEVEL', 'moderate')),
            'CSP_REPORT_URI' => trim($this->input('CSP_REPORT_URI', '')),
            'CSP_SCRIPT_SRC' => trim($this->input('CSP_SCRIPT_SRC', '')),
            'CSP_STYLE_SRC' => trim($this->input('CSP_STYLE_SRC', '')),
            'CSP_IMG_SRC' => trim($this->input('CSP_IMG_SRC', '')),
            'CSP_FONT_SRC' => trim($this->input('CSP_FONT_SRC', '')),
            'CSP_CONNECT_SRC' => trim($this->input('CSP_CONNECT_SRC', '')),
            'CSP_FRAME_SRC' => trim($this->input('CSP_FRAME_SRC', '')),
            'CSP_MEDIA_SRC' => trim($this->input('CSP_MEDIA_SRC', '')),
            'HSTS_MAX_AGE' => trim($this->input('HSTS_MAX_AGE', '31536000')),
            'HSTS_PRELOAD' => $this->input('HSTS_PRELOAD', 'false'),
            'USE_ISOLATION_HEADERS' => $this->input('USE_ISOLATION_HEADERS', 'false'),
        ];

        $envPath = $this->getEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            $this->flash('errors', ['env' => '.env file not found or not writable.']);
            $this->back();
            return;
        }

        $this->updateEnvFile($envPath, $settings);

        foreach ($settings as $key => $value) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        $this->flash('success', 'System settings updated successfully.');
        $this->redirect(admin_url('settings/system'));
    }

    private function getEnvPath(): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $envPath = $basePath . '/.env';

        if (file_exists($envPath)) {
            return $envPath;
        }

        $parentEnv = dirname($basePath) . '/.env';
        if (file_exists($parentEnv)) {
            return $parentEnv;
        }

        return null;
    }

    private function updateEnvFile(string $envPath, array $settings): void
    {
        $content = file_get_contents($envPath);

        foreach ($settings as $key => $value) {
            $escaped = $this->escapeEnvValue($value);

            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $content);
            } else {
                $content = rtrim($content) . "\n{$key}={$escaped}\n";
            }
        }

        file_put_contents($envPath, $content);
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
