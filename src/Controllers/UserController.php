<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\Hash;
use ZephyrPHP\Config\Config;

class UserController extends Controller
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

    private function getUserModel(): string
    {
        return Config::get('auth.providers.users.model', 'App\\Models\\User');
    }

    private function getRoleModel(): string
    {
        $userModel = $this->getUserModel();
        $namespace = substr($userModel, 0, strrpos($userModel, '\\'));
        return $namespace . '\\Role';
    }

    public function index(): string
    {
        $this->requireAdmin();

        $userModel = $this->getUserModel();
        $users = $userModel::findAll();

        return $this->render('cms::users/index', [
            'users' => $users,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requireAdmin();

        $roleModel = $this->getRoleModel();
        $roles = $roleModel::findAll();

        return $this->render('cms::users/create', [
            'roles' => $roles,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        $name = trim($this->input('name', ''));
        $email = trim($this->input('email', ''));
        $password = $this->input('password', '');
        $selectedRoles = $this->request->all()['roles'] ?? [];

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Name is required.';
        }
        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if (empty($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        // Check unique email
        if (empty($errors['email'])) {
            $userModel = $this->getUserModel();
            $existing = $userModel::findOneBy(['email' => $email]);
            if ($existing) {
                $errors['email'] = 'A user with this email already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'email' => $email]);
            $this->back();
            return;
        }

        $userModel = $this->getUserModel();
        $newUser = new $userModel();
        $newUser->setName($name);
        $newUser->setEmail($email);
        $newUser->setPassword(Hash::make($password));
        $newUser->save();

        // Assign roles
        if (!empty($selectedRoles)) {
            $roleModel = $this->getRoleModel();
            foreach ($selectedRoles as $roleId) {
                $role = $roleModel::find((int) $roleId);
                if ($role) {
                    $newUser->assignRole($role);
                }
            }
            $newUser->save();
        }

        $this->flash('success', 'User created successfully.');
        $this->redirect('/cms/users');
    }

    public function edit(int $id): string
    {
        $this->requireAdmin();

        $userModel = $this->getUserModel();
        $editUser = $userModel::find($id);

        if (!$editUser) {
            $this->flash('errors', ['user' => 'User not found.']);
            $this->redirect('/cms/users');
            return '';
        }

        $roleModel = $this->getRoleModel();
        $roles = $roleModel::findAll();

        $userRoleIds = [];
        foreach ($editUser->getRoles() as $role) {
            $userRoleIds[] = $role->getId();
        }

        return $this->render('cms::users/edit', [
            'editUser' => $editUser,
            'roles' => $roles,
            'userRoleIds' => $userRoleIds,
            'user' => Auth::user(),
        ]);
    }

    public function update(int $id): void
    {
        $this->requireAdmin();

        $userModel = $this->getUserModel();
        $editUser = $userModel::find($id);

        if (!$editUser) {
            $this->flash('errors', ['user' => 'User not found.']);
            $this->redirect('/cms/users');
            return;
        }

        $name = trim($this->input('name', ''));
        $email = trim($this->input('email', ''));
        $password = $this->input('password', '');
        $selectedRoles = $this->request->all()['roles'] ?? [];

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Name is required.';
        }
        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if (!empty($password) && strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        // Check unique email (exclude current user)
        if (empty($errors['email'])) {
            $existing = $userModel::findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $editUser->getId()) {
                $errors['email'] = 'A user with this email already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'email' => $email]);
            $this->back();
            return;
        }

        $editUser->setName($name);
        $editUser->setEmail($email);
        if (!empty($password)) {
            $editUser->setPassword(Hash::make($password));
        }

        // Sync roles
        $roleModel = $this->getRoleModel();
        $newRoles = [];
        foreach ($selectedRoles as $roleId) {
            $role = $roleModel::find((int) $roleId);
            if ($role) {
                $newRoles[] = $role;
            }
        }
        $editUser->syncRoles($newRoles);
        $editUser->save();

        $this->flash('success', 'User updated successfully.');
        $this->redirect('/cms/users');
    }

    public function destroy(int $id): void
    {
        $this->requireAdmin();

        // Prevent self-delete
        if (Auth::user()->getId() === $id) {
            $this->flash('errors', ['user' => 'You cannot delete your own account.']);
            $this->redirect('/cms/users');
            return;
        }

        $userModel = $this->getUserModel();
        $deleteUser = $userModel::find($id);

        if ($deleteUser) {
            $deleteUser->delete();
            $this->flash('success', 'User deleted successfully.');
        }

        $this->redirect('/cms/users');
    }
}
