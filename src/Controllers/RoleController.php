<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Cms\Services\PermissionService;

class RoleController extends Controller
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

    private function getRoleModel(): string
    {
        $userModel = Config::get('auth.providers.users.model', 'App\\Models\\User');
        $namespace = substr($userModel, 0, strrpos($userModel, '\\'));
        return $namespace . '\\Role';
    }

    public function index(): string
    {
        $this->requirePermission('roles.manage');

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

        return $this->render('cms::roles/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('roles.manage');

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
        $this->redirect('/cms/roles');
    }

    public function edit(int $id): string
    {
        $this->requirePermission('roles.manage');

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->flash('errors', ['role' => 'Role not found.']);
            $this->redirect('/cms/roles');
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

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->flash('errors', ['role' => 'Role not found.']);
            $this->redirect('/cms/roles');
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
        $this->redirect('/cms/roles');
    }

    public function destroy(int $id): void
    {
        $this->requirePermission('roles.manage');

        $roleModel = $this->getRoleModel();
        $role = $roleModel::find($id);

        if (!$role) {
            $this->redirect('/cms/roles');
            return;
        }

        // Prevent deleting the admin role
        if ($role->getSlug() === 'admin') {
            $this->flash('errors', ['role' => 'The admin role cannot be deleted.']);
            $this->redirect('/cms/roles');
            return;
        }

        $role->delete();
        $this->flash('success', 'Role deleted successfully.');
        $this->redirect('/cms/roles');
    }
}
