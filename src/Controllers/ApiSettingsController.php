<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\EnvFileManager;
use ZephyrPHP\Cms\Services\PermissionService;

class ApiSettingsController extends Controller
{
    use CmsAccessTrait;

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

        if (!EnvFileManager::updateAndApply($settings)) {
            $this->flash('errors', ['.env file not found or not writable.']);
            $this->redirect(admin_url('settings/api'));
            return;
        }

        $this->flash('success', 'API settings updated successfully.');
        $this->redirect(admin_url('settings/api'));
    }

}
