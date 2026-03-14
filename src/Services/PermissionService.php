<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Auth\Auth;

/**
 * CMS Permission Service.
 *
 * Defines granular permissions and checks them against the current user's role.
 * Permissions are stored in the `cms_permissions` table as role_slug => [permissions].
 *
 * Available permissions:
 *   - cms.access           Access the CMS dashboard
 *   - collections.view     View collections list
 *   - collections.create   Create new collections
 *   - collections.edit     Edit existing collections
 *   - collections.delete   Delete collections
 *   - entries.view         View entries in any collection
 *   - entries.create       Create entries
 *   - entries.edit         Edit entries
 *   - entries.delete       Delete entries
 *   - entries.publish      Publish/unpublish entries
 *   - media.view           View media library
 *   - media.upload         Upload files
 *   - media.delete         Delete media files
 *   - themes.view          View themes
 *   - themes.edit          Edit themes & customizer
 *   - themes.publish       Publish themes
 *   - users.view           View users list
 *   - users.manage         Create/edit/delete users
 *   - roles.manage         Create/edit/delete roles
 *   - settings.view        View settings
 *   - settings.edit        Edit system settings
 *   - api-keys.manage      Manage API keys
 *
 * Per-collection permissions (stored on each collection's `permissions` JSON column):
 *   When a collection has custom permissions, they override global entries.* checks.
 *   Actions: view, create, edit, delete, publish, submit
 *   Format: { "role_slug": ["view", "create", ...], ... }
 */
class PermissionService
{
    private static ?array $permissionsCache = null;

    /**
     * All available CMS permissions grouped by category.
     */
    public static function allPermissions(): array
    {
        return [
            'Dashboard' => [
                'cms.access' => 'Access CMS Dashboard',
            ],
            'Collections' => [
                'collections.view' => 'View Collections',
                'collections.create' => 'Create Collections',
                'collections.edit' => 'Edit Collections',
                'collections.delete' => 'Delete Collections',
            ],
            'Entries' => [
                'entries.view' => 'View Entries',
                'entries.create' => 'Create Entries',
                'entries.edit' => 'Edit Entries',
                'entries.delete' => 'Delete Entries',
                'entries.publish' => 'Publish/Unpublish Entries',
            ],
            'Media' => [
                'media.view' => 'View Media Library',
                'media.upload' => 'Upload Files',
                'media.delete' => 'Delete Media',
            ],
            'Themes' => [
                'themes.view' => 'View Themes',
                'themes.edit' => 'Edit Themes',
                'themes.publish' => 'Publish Themes',
            ],
            'Users' => [
                'users.view' => 'View Users',
                'users.manage' => 'Manage Users',
            ],
            'Roles' => [
                'roles.manage' => 'Manage Roles',
            ],
            'Settings' => [
                'settings.view' => 'View Settings',
                'settings.edit' => 'Edit Settings',
            ],
            'API' => [
                'api-keys.manage' => 'Manage API Keys',
            ],
        ];
    }

    /**
     * Check if the current user has a specific permission.
     * Admin role always has all permissions.
     */
    public static function can(string $permission): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $user = Auth::user();

        // Admin role has all permissions
        if ($user->hasRole('admin')) {
            return true;
        }

        // Check role-based permissions
        $rolePermissions = self::getRolePermissions();
        $userRoles = self::getUserRoles($user);

        foreach ($userRoles as $roleSlug) {
            $perms = $rolePermissions[$roleSlug] ?? [];
            if (in_array($permission, $perms) || in_array('*', $perms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current user can perform an action on a specific collection.
     *
     * If the collection has per-collection permissions configured, those are checked.
     * Otherwise falls back to the global entries.{action} permission.
     *
     * @param string $action One of: view, create, edit, delete, publish, submit
     * @param \ZephyrPHP\Cms\Models\Collection $collection
     */
    public static function canForCollection(string $action, \ZephyrPHP\Cms\Models\Collection $collection): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $user = Auth::user();

        // Admin role always has all permissions
        if ($user->hasRole('admin')) {
            return true;
        }

        // If collection has custom per-collection permissions, use those
        $collPerms = $collection->getPermissions();
        if (!empty($collPerms)) {
            $userRoles = self::getUserRoles($user);
            foreach ($userRoles as $roleSlug) {
                $roleActions = $collPerms[$roleSlug] ?? [];
                if (in_array($action, $roleActions) || in_array('*', $roleActions)) {
                    return true;
                }
            }
            return false;
        }

        // Fallback to global permission: entries.{action}
        $globalPerm = match ($action) {
            'view' => 'entries.view',
            'create' => 'entries.create',
            'edit' => 'entries.edit',
            'delete' => 'entries.delete',
            'publish' => 'entries.publish',
            'submit' => 'entries.create', // submit maps to create for global perms
            default => 'entries.view',
        };

        return self::can($globalPerm);
    }

    /**
     * Check permission or redirect with error.
     */
    public static function authorize(string $permission): bool
    {
        if (self::can($permission)) {
            return true;
        }

        http_response_code(403);
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions.']);
            exit;
        }

        $_SESSION['flash']['errors'] = ['auth' => 'You do not have permission to perform this action.'];
        header('Location: /cms');
        exit;
    }

    /**
     * Get user's role slugs.
     */
    private static function getUserRoles($user): array
    {
        $roles = [];
        try {
            $userRoles = $user->getRoles();
            if (is_iterable($userRoles)) {
                foreach ($userRoles as $role) {
                    $roles[] = is_object($role) ? $role->getSlug() : (string) $role;
                }
            }
        } catch (\Exception $e) {
            // Fallback
        }
        return $roles;
    }

    /**
     * Get all role permissions from the database.
     * Returns: ['role_slug' => ['permission1', 'permission2', ...], ...]
     */
    public static function getRolePermissions(): array
    {
        if (self::$permissionsCache !== null) {
            return self::$permissionsCache;
        }

        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $sm = $conn->createSchemaManager();

            if (!$sm->tablesExist(['cms_role_permissions'])) {
                self::$permissionsCache = [];
                return [];
            }

            $rows = $conn->createQueryBuilder()
                ->select('*')
                ->from('cms_role_permissions')
                ->executeQuery()
                ->fetchAllAssociative();

            $perms = [];
            foreach ($rows as $row) {
                $perms[$row['role_slug']] = json_decode($row['permissions'] ?? '[]', true) ?: [];
            }

            self::$permissionsCache = $perms;
            return $perms;
        } catch (\Exception $e) {
            self::$permissionsCache = [];
            return [];
        }
    }

    /**
     * Save permissions for a role.
     */
    public static function saveRolePermissions(string $roleSlug, array $permissions): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $exists = $conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('cms_role_permissions')
                ->where('role_slug = :slug')
                ->setParameter('slug', $roleSlug)
                ->executeQuery()
                ->fetchOne();

            if ((int) $exists > 0) {
                $conn->update('cms_role_permissions', [
                    'permissions' => json_encode(array_values($permissions)),
                ], ['role_slug' => $roleSlug]);
            } else {
                $conn->insert('cms_role_permissions', [
                    'role_slug' => $roleSlug,
                    'permissions' => json_encode(array_values($permissions)),
                ]);
            }

            self::$permissionsCache = null; // Clear cache
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
