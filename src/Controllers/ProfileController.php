<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\Hash;
use ZephyrPHP\Security\Totp;
use ZephyrPHP\Security\Encryption;

class ProfileController extends Controller
{
    private function requireAuth(): void
    {
        if (!Auth::check()) {
            $this->redirect(login_url());
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
            if (strlen($newPassword) < 8) {
                $errors['new_password'] = 'New password must be at least 8 characters.';
            } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                $errors['new_password'] = 'Password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $newPassword)) {
                $errors['new_password'] = 'Password must contain at least one lowercase letter.';
            } elseif (!preg_match('/[0-9]/', $newPassword)) {
                $errors['new_password'] = 'Password must contain at least one number.';
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
        $this->redirect(admin_url('settings/profile'));
    }

    /**
     * Begin 2FA setup — generate secret and show setup page
     */
    public function enable2fa(): void
    {
        $this->requireAuth();

        $user = Auth::user();

        // If already enabled, redirect back
        if (method_exists($user, 'isTwoFactorEnabled') && $user->isTwoFactorEnabled()) {
            $this->flash('error', 'Two-factor authentication is already enabled.');
            $this->redirect(admin_url('settings/profile'));
            return;
        }

        // Generate a new secret and store temporarily in session
        $secret = Totp::generateSecret();
        $this->session->set('2fa_setup_secret', $secret);

        // Generate the otpauth URI
        $issuer = $_ENV['APP_NAME'] ?? 'ZephyrPHP';
        $uri = Totp::getUri($secret, $user->getEmail(), $issuer);

        $this->flash('2fa_setup', [
            'secret' => $secret,
            'uri' => $uri,
        ]);

        $this->redirect(admin_url('settings/profile/2fa/setup'));
    }

    /**
     * Show 2FA setup page (renders the template)
     */
    public function show2faSetup(): string
    {
        $this->requireAuth();

        $user = Auth::user();

        // Get setup data from session
        $secret = $this->session->get('2fa_setup_secret');
        $setupData = $this->session->getFlash('2fa_setup');

        if (!$secret) {
            $this->redirect(admin_url('settings/profile'));
            return '';
        }

        // Regenerate URI if flash data was consumed
        if (!$setupData) {
            $issuer = $_ENV['APP_NAME'] ?? 'ZephyrPHP';
            $setupData = [
                'secret' => $secret,
                'uri' => Totp::getUri($secret, $user->getEmail(), $issuer),
            ];
        }

        return $this->render('cms::settings/2fa-setup', [
            'user' => $user,
            'profile' => $user,
            'secret' => $setupData['secret'],
            'uri' => $setupData['uri'],
        ]);
    }

    /**
     * Confirm 2FA setup — verify the code and enable 2FA
     */
    public function confirm2fa(): void
    {
        $this->requireAuth();

        $user = Auth::user();
        $code = trim($this->input('code', ''));
        $secret = $this->session->get('2fa_setup_secret');

        if (!$secret) {
            $this->flash('error', 'Setup session expired. Please start 2FA setup again.');
            $this->redirect(admin_url('settings/profile'));
            return;
        }

        if (empty($code)) {
            $this->flash('errors', ['code' => 'Please enter the verification code from your authenticator app.']);
            $this->flash('2fa_setup', [
                'secret' => $secret,
                'uri' => Totp::getUri($secret, $user->getEmail(), $_ENV['APP_NAME'] ?? 'ZephyrPHP'),
            ]);
            $this->redirect(admin_url('settings/profile/2fa/setup'));
            return;
        }

        // Verify the code against the temporary secret
        if (!Totp::verify($secret, $code)) {
            $this->flash('errors', ['code' => 'Invalid verification code. Please try again.']);
            $this->flash('2fa_setup', [
                'secret' => $secret,
                'uri' => Totp::getUri($secret, $user->getEmail(), $_ENV['APP_NAME'] ?? 'ZephyrPHP'),
            ]);
            $this->redirect(admin_url('settings/profile/2fa/setup'));
            return;
        }

        // Code verified — enable 2FA
        // Encrypt and store the secret
        $encryptedSecret = Encryption::encrypt($secret);
        $user->setTwoFactorSecret($encryptedSecret);
        $user->setTwoFactorEnabled(true);

        // Generate recovery codes
        $recoveryCodes = Totp::generateRecoveryCodes(8);

        // Hash each code before storing
        $hashedCodes = array_map(fn(string $code) => Hash::make(strtolower($code)), $recoveryCodes);
        $user->setTwoFactorRecoveryCodes(json_encode($hashedCodes));

        $user->save();

        // Clean up session
        $this->session->remove('2fa_setup_secret');

        // Flash the recovery codes to show them once
        $this->flash('2fa_recovery_codes', $recoveryCodes);
        $this->flash('success', 'Two-factor authentication has been enabled successfully.');
        $this->redirect(admin_url('settings/profile'));
    }

    /**
     * Disable 2FA — requires current password
     */
    public function disable2fa(): void
    {
        $this->requireAuth();

        $user = Auth::user();
        $password = $this->input('current_password', '');

        if (empty($password)) {
            $this->flash('errors', ['2fa_password' => 'Please enter your current password to disable 2FA.']);
            $this->redirect(admin_url('settings/profile'));
            return;
        }

        if (!Hash::check($password, $user->getAuthPassword())) {
            $this->flash('errors', ['2fa_password' => 'Current password is incorrect.']);
            $this->redirect(admin_url('settings/profile'));
            return;
        }

        // Disable 2FA
        $user->setTwoFactorSecret(null);
        $user->setTwoFactorEnabled(false);
        $user->setTwoFactorRecoveryCodes(null);
        $user->save();

        $this->flash('success', 'Two-factor authentication has been disabled.');
        $this->redirect(admin_url('settings/profile'));
    }
}
