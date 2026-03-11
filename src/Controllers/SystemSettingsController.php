<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class SystemSettingsController extends Controller
{
    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect('/login');
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

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
            'AUTH_HOME' => env('AUTH_HOME', '/'),
            'SESSION_LIFETIME' => env('SESSION_LIFETIME', '120'),
            'SESSION_DRIVER' => env('SESSION_DRIVER', 'file'),
        ];

        $cmsSettings = [
            'CMS_THEME' => env('CMS_THEME', 'default'),
            'VIEWS_PATH' => env('VIEWS_PATH', 'pages'),
        ];

        $extensions = get_loaded_extensions();
        sort($extensions);

        return $this->render('cms::settings/system', [
            'systemInfo' => $systemInfo,
            'appSettings' => $appSettings,
            'authSettings' => $authSettings,
            'cmsSettings' => $cmsSettings,
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
            // Auth & Session
            'AUTH_HOME' => trim($this->input('AUTH_HOME', '/')),
            'SESSION_LIFETIME' => trim($this->input('SESSION_LIFETIME', '120')),
            'SESSION_DRIVER' => trim($this->input('SESSION_DRIVER', 'file')),
            // CMS
            'CMS_THEME' => trim($this->input('CMS_THEME', 'default')),
            'VIEWS_PATH' => trim($this->input('VIEWS_PATH', 'pages')),
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
        $this->redirect('/cms/settings/system');
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
