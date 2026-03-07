<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;

class SystemSettingsController extends Controller
{
    private function requireAdmin(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!Auth::user()->hasRole('admin')) {
            $this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requireAdmin();

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

        $extensions = get_loaded_extensions();
        sort($extensions);

        return $this->render('cms::settings/system', [
            'systemInfo' => $systemInfo,
            'appSettings' => $appSettings,
            'extensions' => $extensions,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requireAdmin();

        $settings = [
            'APP_NAME' => trim($this->input('APP_NAME', '')),
            'APP_ENV' => trim($this->input('APP_ENV', 'dev')),
            'APP_DEBUG' => $this->input('APP_DEBUG', 'false'),
            'APP_URL' => trim($this->input('APP_URL', '')),
            'APP_TIMEZONE' => trim($this->input('APP_TIMEZONE', 'UTC')),
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
