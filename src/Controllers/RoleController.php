<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;

class RoleController extends Controller
{
    use CmsAccessTrait;

    private function detectUserModel(): ?string
    {
        $model = Config::get('auth.providers.users.model');
        if ($model && class_exists($model)) {
            return $model;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $composerFile = $basePath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            $psr4 = $composer['autoload']['psr-4'] ?? [];
            foreach ($psr4 as $namespace => $path) {
                $userFile = $basePath . '/' . rtrim($path, '/') . '/Models/User.php';
                if (file_exists($userFile)) {
                    $detected = rtrim($namespace, '\\') . '\\Models\\User';
                    if (class_exists($detected)) {
                        return $detected;
                    }
                }
            }
        }

        return null;
    }

    private function getRoleModel(): ?string
    {
        $userModel = $this->detectUserModel();
        if (!$userModel) {
            return null;
        }
        $namespace = substr($userModel, 0, strrpos($userModel, '\\'));
        $roleClass = $namespace . '\\Role';
        return class_exists($roleClass) ? $roleClass : null;
    }

    private function ensureModelsExist(): bool
    {
        if (!$this->getRoleModel()) {
            $this->flash('errors', ['config' => 'Role model not found. Please ensure the auth module is installed and enabled, and that your Role model exists in your app/Models/ directory.']);
            $this->redirect(admin_url());
            return false;
        }
        return true;
    }

    public function index(): string
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return '';

        $roleModel = $this->getRoleModel();
        $roles = $roleModel::findAll();

        return $this->render('cms::roles/index', [
            'roles' => $roles,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return '';

        return $this->render('cms::roles/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return;

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = trim($this->input('description', ''));

        if (empty($slug) && !empty($name)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $slug = trim($slug, '-');
        }

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Role name is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Role slug is required.';
        }

        // Check unique slug
        if (empty($errors['slug'])) {
            $roleModel = $this->getRoleModel();
            $existing = $roleModel::findOneBy(['slug' => $slug]);
            if ($existing) {
                $errors['slug'] = 'A role with this slug already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'slug' => $slug, 'description' => $description]);
            $this->back();
            return;
        }

        $roleModel = $this->getRoleModel();
        $role = new $roleModel();
        $role->setName($name);
        $role->setSlug($slug);
        $role->setDescription($description ?: null);
        $role->save();

        $this->flash('success', 'Role created successfully.');
        $this->redirect(admin_url('roles'));
    }

    public function edit(int $id): string
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return '';

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->flash('errors', ['role' => 'Role not found.']);
            $this->redirect(admin_url('roles'));
            return '';
        }

        $allPermissions = PermissionService::allPermissions();
        $rolePermissions = PermissionService::getRolePermissions()[$role->getSlug()] ?? [];

        return $this->render('cms::roles/edit', [
            'editRole' => $role,
            'allPermissions' => $allPermissions,
            'rolePermissions' => $rolePermissions,
            'user' => Auth::user(),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return;

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->flash('errors', ['role' => 'Role not found.']);
            $this->redirect(admin_url('roles'));
            return;
        }

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = trim($this->input('description', ''));

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Role name is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Role slug is required.';
        }

        // Check unique slug (exclude current)
        if (empty($errors['slug'])) {
            $existing = $roleModel::findOneBy(['slug' => $slug]);
            if ($existing && $existing->getId() !== $role->getId()) {
                $errors['slug'] = 'A role with this slug already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'slug' => $slug, 'description' => $description]);
            $this->back();
            return;
        }

        $role->setName($name);
        $role->setSlug($slug);
        $role->setDescription($description ?: null);
        $role->save();

        // Save permissions
        $permissions = $this->input('permissions');
        if (is_array($permissions)) {
            PermissionService::saveRolePermissions($slug, $permissions);
        } else {
            PermissionService::saveRolePermissions($slug, []);
        }

        $this->flash('success', 'Role updated successfully.');
        $this->redirect(admin_url('roles'));
    }

    public function destroy(int $id): void
    {
        $this->requirePermission('roles.manage');
        if (!$this->ensureModelsExist()) return;

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->redirect(admin_url('roles'));
            return;
        }

        // Prevent deleting the admin role
        if ($role->getSlug() === 'admin') {
            $this->flash('errors', ['role' => 'The admin role cannot be deleted.']);
            $this->redirect(admin_url('roles'));
            return;
        }

        $role->delete();
        $this->flash('success', 'Role deleted successfully.');
        $this->redirect(admin_url('roles'));
    }
}
