<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class ApiSettingsController extends Controller
{
    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $settings = [
            'API_PREFIX' => env('API_PREFIX', 'api'),
            'API_VERSION' => env('API_VERSION', 'v1'),
            'API_VERSION_STRATEGY' => env('API_VERSION_STRATEGY', 'uri'),
            'API_RATE_LIMIT' => env('API_RATE_LIMIT', 'true'),
            'API_RATE_LIMIT_DEFAULT' => env('API_RATE_LIMIT_DEFAULT', '60'),
            'API_RATE_LIMIT_AUTH' => env('API_RATE_LIMIT_AUTH', '120'),
            'API_RATE_LIMIT_GUEST' => env('API_RATE_LIMIT_GUEST', '30'),
            'API_RATE_LIMIT_BY' => env('API_RATE_LIMIT_BY', 'ip'),
            'API_CORS_ENABLED' => env('API_CORS_ENABLED', 'true'),
            'API_CORS_ORIGINS' => env('API_CORS_ORIGINS', '*'),
            'API_PER_PAGE' => env('API_PER_PAGE', '15'),
            'API_MAX_PER_PAGE' => env('API_MAX_PER_PAGE', '100'),
            'API_DOCS_ENABLED' => env('API_DOCS_ENABLED', 'true'),
            'API_MAX_REQUEST_SIZE' => env('API_MAX_REQUEST_SIZE', '10485760'),
        ];

        return $this->render('cms::settings/api', [
            'settings' => $settings,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [
            'API_PREFIX' => preg_replace('/[^a-z0-9\-_]/', '', trim($this->input('API_PREFIX', 'api'))),
            'API_VERSION' => trim($this->input('API_VERSION', 'v1')),
            'API_VERSION_STRATEGY' => trim($this->input('API_VERSION_STRATEGY', 'uri')),
            'API_RATE_LIMIT' => $this->input('API_RATE_LIMIT', 'false'),
            'API_RATE_LIMIT_DEFAULT' => (string) max(1, (int) $this->input('API_RATE_LIMIT_DEFAULT', '60')),
            'API_RATE_LIMIT_AUTH' => (string) max(1, (int) $this->input('API_RATE_LIMIT_AUTH', '120')),
            'API_RATE_LIMIT_GUEST' => (string) max(1, (int) $this->input('API_RATE_LIMIT_GUEST', '30')),
            'API_RATE_LIMIT_BY' => trim($this->input('API_RATE_LIMIT_BY', 'ip')),
            'API_CORS_ENABLED' => $this->input('API_CORS_ENABLED', 'false'),
            'API_CORS_ORIGINS' => trim($this->input('API_CORS_ORIGINS', '*')),
            'API_PER_PAGE' => (string) max(1, min(500, (int) $this->input('API_PER_PAGE', '15'))),
            'API_MAX_PER_PAGE' => (string) max(1, min(1000, (int) $this->input('API_MAX_PER_PAGE', '100'))),
            'API_DOCS_ENABLED' => $this->input('API_DOCS_ENABLED', 'false'),
            'API_MAX_REQUEST_SIZE' => (string) max(1024, (int) $this->input('API_MAX_REQUEST_SIZE', '10485760')),
        ];

        $envPath = $this->getEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            $this->flash('errors', ['.env file not found or not writable.']);
            $this->redirect('/cms/settings/api');
            return;
        }

        $this->updateEnvFile($envPath, $settings);

        foreach ($settings as $key => $value) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        $this->flash('success', 'API settings updated successfully.');
        $this->redirect('/cms/settings/api');
    }

    private function getEnvPath(): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $envPath = $basePath . '/.env';
        if (file_exists($envPath)) {
            return $envPath;
        }
        $parentEnv = dirname($basePath) . '/.env';
        return file_exists($parentEnv) ? $parentEnv : null;
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
        file_put_contents($envPath, $content, LOCK_EX);
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
