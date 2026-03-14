<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Cms\Services\PermissionService;

class PermissionBuilderController extends Controller
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

    /**
     * Permission overview: all roles, all permissions, matrix view.
     */
    public function index(): string
    {
        $this->requirePermission('roles.manage');

        $roleModel = $this->getRoleModel();
        $roles = $roleModel::findAll();

        $allPermissions = PermissionService::allPermissions();
        $rolePermissions = PermissionService::getRolePermissions();

        // Get custom permissions
        $customPermissions = $this->getCustomPermissions();

        // Get collections for per-collection permissions
        $collections = [];
        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['cms_collections'])) {
                $collections = $conn->fetchAllAssociative('SELECT id, name, slug, permissions FROM cms_collections ORDER BY name');
                foreach ($collections as &$col) {
                    $col['permissions'] = json_decode($col['permissions'] ?? '{}', true) ?: [];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        $collectionActions = ['view', 'create', 'edit', 'delete', 'publish', 'submit'];

        return $this->render('cms::roles/permission-builder', [
            'roles' => $roles,
            'allPermissions' => $allPermissions,
            'customPermissions' => $customPermissions,
            'rolePermissions' => $rolePermissions,
            'collections' => $collections,
            'collectionActions' => $collectionActions,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Save the full permission matrix for all roles.
     */
    public function saveMatrix(): void
    {
        $this->requirePermission('roles.manage');

        $matrix = $this->input('permissions', []);
        if (!is_array($matrix)) {
            $this->flash('errors', ['Invalid data.']);
            $this->redirect('/cms/permissions');
            return;
        }

        foreach ($matrix as $roleSlug => $permissions) {
            // Validate role slug (alphanumeric + hyphens)
            if (!preg_match('/^[a-z0-9\-_]+$/', $roleSlug)) continue;
            if ($roleSlug === 'admin') continue; // Admin always has all

            $perms = is_array($permissions) ? array_values($permissions) : [];
            // Sanitize permission keys
            $perms = array_filter($perms, fn($p) => preg_match('/^[a-zA-Z0-9_\.\-]+$/', $p));
            PermissionService::saveRolePermissions($roleSlug, $perms);
        }

        $this->flash('success', 'Permission matrix saved.');
        $this->redirect('/cms/permissions');
    }

    /**
     * Save per-collection permissions.
     */
    public function saveCollectionPermissions(): void
    {
        $this->requirePermission('roles.manage');

        $collectionPerms = $this->input('collection_permissions', []);
        if (!is_array($collectionPerms)) {
            $this->flash('errors', ['Invalid data.']);
            $this->redirect('/cms/permissions');
            return;
        }

        try {
            $conn = \ZephyrPHP\Database\DB::connection();

            foreach ($collectionPerms as $collectionId => $roleActions) {
                $collectionId = (int) $collectionId;
                if ($collectionId <= 0) continue;

                $permissions = [];
                if (is_array($roleActions)) {
                    foreach ($roleActions as $roleSlug => $actions) {
                        if (!preg_match('/^[a-z0-9\-_]+$/', $roleSlug)) continue;
                        $validActions = array_filter(
                            is_array($actions) ? $actions : [],
                            fn($a) => in_array($a, ['view', 'create', 'edit', 'delete', 'publish', 'submit'])
                        );
                        if (!empty($validActions)) {
                            $permissions[$roleSlug] = array_values($validActions);
                        }
                    }
                }

                $conn->update('cms_collections', [
                    'permissions' => json_encode($permissions),
                ], ['id' => $collectionId]);
            }

            $this->flash('success', 'Collection permissions saved.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to save collection permissions: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/permissions');
    }

    /**
     * Add a custom permission.
     */
    public function addCustomPermission(): void
    {
        $this->requirePermission('roles.manage');

        $key = trim($this->input('permission_key', ''));
        $label = trim($this->input('permission_label', ''));
        $group = trim($this->input('permission_group', 'Custom'));

        if ($key === '' || $label === '') {
            $this->flash('errors', ['Permission key and label are required.']);
            $this->redirect('/cms/permissions');
            return;
        }

        // Validate key format: lowercase, dots, hyphens
        if (!preg_match('/^[a-z][a-z0-9_\.\-]*$/', $key)) {
            $this->flash('errors', ['Permission key must start with a letter and contain only lowercase letters, numbers, dots, hyphens, and underscores.']);
            $this->redirect('/cms/permissions');
            return;
        }

        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $group = htmlspecialchars($group, ENT_QUOTES, 'UTF-8');

        // Check if already exists in built-in
        $builtIn = PermissionService::allPermissions();
        foreach ($builtIn as $perms) {
            if (isset($perms[$key])) {
                $this->flash('errors', ['This permission key already exists as a built-in permission.']);
                $this->redirect('/cms/permissions');
                return;
            }
        }

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $this->ensureCustomPermissionsTable($conn);

            // Check uniqueness
            $exists = $conn->fetchOne('SELECT COUNT(*) FROM cms_custom_permissions WHERE permission_key = ?', [$key]);
            if ((int) $exists > 0) {
                $this->flash('errors', ['This permission key already exists.']);
                $this->redirect('/cms/permissions');
                return;
            }

            $conn->insert('cms_custom_permissions', [
                'permission_key' => $key,
                'label' => $label,
                'group_name' => $group,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->flash('success', 'Custom permission added: ' . $key);
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to add permission: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/permissions');
    }

    /**
     * Delete a custom permission.
     */
    public function deleteCustomPermission(string $id): void
    {
        $this->requirePermission('roles.manage');

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $conn->delete('cms_custom_permissions', ['id' => (int) $id]);
            $this->flash('success', 'Custom permission deleted.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to delete permission.']);
        }

        $this->redirect('/cms/permissions');
    }

    // ─── Private helpers ────────────────────────────────────────

    private function getRoleModel(): string
    {
        $userModel = Config::get('auth.providers.users.model', 'App\\Models\\User');
        $namespace = substr($userModel, 0, strrpos($userModel, '\\'));
        return $namespace . '\\Role';
    }

    private function getCustomPermissions(): array
    {
        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $this->ensureCustomPermissionsTable($conn);
            return $conn->fetchAllAssociative('SELECT * FROM cms_custom_permissions ORDER BY group_name, permission_key');
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function ensureCustomPermissionsTable($conn): void
    {
        $sm = $conn->createSchemaManager();
        if (!$sm->tablesExist(['cms_custom_permissions'])) {
            $conn->executeStatement("
                CREATE TABLE cms_custom_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    permission_key VARCHAR(100) NOT NULL UNIQUE,
                    label VARCHAR(255) NOT NULL,
                    group_name VARCHAR(100) DEFAULT 'Custom',
                    created_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
}
