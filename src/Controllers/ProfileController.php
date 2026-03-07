<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\Hash;

class ProfileController extends Controller
{
    private function requireAuth(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
        }
    }

    public function index(): string
    {
        $this->requireAuth();

        return $this->render('cms::settings/profile', [
            'user' => Auth::user(),
            'profile' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requireAuth();

        $currentUser = Auth::user();
        $name = trim($this->input('name', ''));
        $email = trim($this->input('email', ''));
        $currentPassword = $this->input('current_password', '');
        $newPassword = $this->input('new_password', '');
        $confirmPassword = $this->input('confirm_password', '');

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Name is required.';
        }
        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        // Check unique email (exclude current user)
        if (empty($errors['email']) && $email !== $currentUser->getEmail()) {
            $userModel = get_class($currentUser);
            $existing = $userModel::findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $currentUser->getId()) {
                $errors['email'] = 'A user with this email already exists.';
            }
        }

        // Password change validation
        if (!empty($newPassword) || !empty($confirmPassword)) {
            if (empty($currentPassword)) {
                $errors['current_password'] = 'Current password is required to set a new password.';
            } elseif (!Hash::check($currentPassword, $currentUser->getAuthPassword())) {
                $errors['current_password'] = 'Current password is incorrect.';
            }
            if (strlen($newPassword) < 6) {
                $errors['new_password'] = 'New password must be at least 6 characters.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors['confirm_password'] = 'Passwords do not match.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'email' => $email]);
            $this->back();
            return;
        }

        $currentUser->setName($name);
        $currentUser->setEmail($email);

        if (!empty($newPassword)) {
            $currentUser->setPassword(Hash::make($newPassword));
        }

        $currentUser->save();

        $this->flash('success', 'Profile updated successfully.');
        $this->redirect('/cms/settings/profile');
    }
}
