<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\ApiKey;
use ZephyrPHP\Cms\Services\PermissionService;

class ApiKeyController extends Controller
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
        $this->requirePermission('api-keys.manage');

        $keys = ApiKey::findBy([], ['createdAt' => 'DESC']);

        return $this->render('cms::api-keys/index', [
            'keys' => $keys,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('api-keys.manage');

        return $this->render('cms::api-keys/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('api-keys.manage');

        $name = trim($this->input('name', ''));
        if (empty($name)) {
            $this->flash('errors', ['name' => 'Name is required.']);
            $this->back();
            return;
        }

        $rawKey = ApiKey::generateKey();

        $permissions = null;
        $permType = $this->input('permission_type', 'full');
        if ($permType === 'readonly') {
            $permissions = ['read'];
        } elseif ($permType === 'readwrite') {
            $permissions = ['read', 'write'];
        }

        $expiresAt = null;
        $expiry = $this->input('expires_at');
        if (!empty($expiry)) {
            $expiresAt = new \DateTime($expiry);
        }

        $apiKey = new ApiKey();
        $apiKey->setName($name);
        $apiKey->setKey(hash('sha256', $rawKey));
        $apiKey->setPermissions($permissions);
        $apiKey->setIsActive(true);
        $apiKey->setExpiresAt($expiresAt);
        $apiKey->setCreatedBy(Auth::user()?->getId());
        $apiKey->save();

        // Flash the raw key so user can copy it (shown only once)
        $this->flash('success', 'API key created. Copy it now — it won\'t be shown again.');
        $this->flash('new_api_key', $rawKey);
        $this->redirect('/cms/api-keys');
    }

    public function toggleStatus(int $id): void
    {
        $this->requirePermission('api-keys.manage');

        $key = ApiKey::find($id);
        if (!$key) {
            $this->flash('errors', ['key' => 'API key not found.']);
            $this->redirect('/cms/api-keys');
            return;
        }

        $key->setIsActive(!$key->isActive());
        $key->save();

        $this->flash('success', 'API key ' . ($key->isActive() ? 'activated' : 'deactivated') . '.');
        $this->redirect('/cms/api-keys');
    }

    public function destroy(int $id): void
    {
        $this->requirePermission('api-keys.manage');

        $key = ApiKey::find($id);
        if (!$key) {
            $this->flash('errors', ['key' => 'API key not found.']);
            $this->redirect('/cms/api-keys');
            return;
        }

        $key->delete();

        $this->flash('success', 'API key deleted.');
        $this->redirect('/cms/api-keys');
    }
}
