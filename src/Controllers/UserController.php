<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\Hash;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\MailService;
use ZephyrPHP\Cms\Models\Invitation;

class UserController extends Controller
{
    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect(login_url());
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect(login_url());
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect(admin_url());
        }
    }

    private function getUserModel(): ?string
    {
        // 1. Check auth config
        $model = Config::get('auth.providers.users.model');
        if ($model && class_exists($model)) {
            return $model;
        }

        // 2. Auto-detect from composer.json PSR-4 mapping
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
        $userModel = $this->getUserModel();
        if (!$userModel) {
            return null;
        }
        $namespace = substr($userModel, 0, strrpos($userModel, '\\'));
        $roleClass = $namespace . '\\Role';
        return class_exists($roleClass) ? $roleClass : null;
    }

    private function ensureModelsExist(): bool
    {
        if (!$this->getUserModel()) {
            $this->flash('errors', ['config' => 'User model not found. Please ensure the auth module is installed and enabled, and that your User model exists in your app/Models/ directory.']);
            $this->redirect(admin_url());
            return false;
        }
        return true;
    }

    public function index(): string
    {
        $this->requirePermission('users.view');
        if (!$this->ensureModelsExist()) return '';

        $userModel = $this->getUserModel();
        $users = $userModel::findAll();

        // Load pending invitations
        $invitations = Invitation::findBy(['acceptedAt' => null], ['createdAt' => 'DESC']);

        // Resolve inviter names and role names
        $roleModel = $this->getRoleModel();
        $invitationData = [];
        foreach ($invitations as $inv) {
            $inviterName = null;
            if ($inv->getInvitedBy()) {
                $inviter = $userModel::find($inv->getInvitedBy());
                $inviterName = $inviter ? $inviter->getName() : null;
            }
            $roleName = null;
            if ($inv->getRoleId() && $roleModel) {
                $role = $roleModel::find($inv->getRoleId());
                $roleName = $role ? $role->getName() : null;
            }
            $invitationData[] = [
                'invitation' => $inv,
                'inviterName' => $inviterName,
                'roleName' => $roleName,
            ];
        }

        return $this->render('cms::users/index', [
            'users' => $users,
            'user' => Auth::user(),
            'invitations' => $invitationData,
            'roles' => $roleModel ? $roleModel::findAll() : [],
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('users.manage');
        if (!$this->ensureModelsExist()) return '';

        $roleModel = $this->getRoleModel();
        $roles = $roleModel::findAll();

        return $this->render('cms::users/create', [
            'roles' => $roles,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('users.manage');
        if (!$this->ensureModelsExist()) return;

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
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors['password'] = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must contain at least one number.';
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
        $this->redirect(admin_url('users'));
    }

    public function edit(int $id): string
    {
        $this->requirePermission('users.manage');
        if (!$this->ensureModelsExist()) return '';

        $userModel = $this->getUserModel();
        $editUser = $userModel::find($id);

        if (!$editUser) {
            $this->flash('errors', ['user' => 'User not found.']);
            $this->redirect(admin_url('users'));
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
        $this->requirePermission('users.manage');
        if (!$this->ensureModelsExist()) return;

        $userModel = $this->getUserModel();
        $editUser = $userModel::find($id);

        if (!$editUser) {
            $this->flash('errors', ['user' => 'User not found.']);
            $this->redirect(admin_url('users'));
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
        if (!empty($password)) {
            if (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $errors['password'] = 'Password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $password)) {
                $errors['password'] = 'Password must contain at least one lowercase letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $errors['password'] = 'Password must contain at least one number.';
            }
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
        $this->redirect(admin_url('users'));
    }

    public function destroy(int $id): void
    {
        $this->requirePermission('users.manage');

        // Prevent self-delete
        if (Auth::user()->getId() === $id) {
            $this->flash('errors', ['user' => 'You cannot delete your own account.']);
            $this->redirect(admin_url('users'));
            return;
        }

        $userModel = $this->getUserModel();
        $deleteUser = $userModel::find($id);

        if ($deleteUser) {
            $deleteUser->delete();
            $this->flash('success', 'User deleted successfully.');
        }

        $this->redirect(admin_url('users'));
    }

    // =========================================================================
    // User Invitations
    // =========================================================================

    public function invite(): void
    {
        $this->requirePermission('users.create');

        $email = strtolower(trim($this->input('email', '')));
        $roleId = $this->input('role_id');

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('errors', ['email' => 'Invalid email address.']);
            $this->redirect(admin_url('users'));
            return;
        }

        // Check if user already exists
        $userModel = $this->getUserModel();
        if ($userModel) {
            $existing = $userModel::findOneBy(['email' => $email]);
            if ($existing) {
                $this->flash('errors', ['email' => 'A user with this email already exists.']);
                $this->redirect(admin_url('users'));
                return;
            }
        }

        // Check if a pending (non-expired, non-accepted) invite already exists
        $existingInvites = Invitation::findBy(['email' => $email, 'acceptedAt' => null]);
        foreach ($existingInvites as $inv) {
            if ($inv->isPending()) {
                $this->flash('errors', ['email' => 'A pending invitation already exists for this email.']);
                $this->redirect(admin_url('users'));
                return;
            }
        }

        // Generate token — store hashed, send plain
        $plainToken = Hash::randomToken(32);
        $hashedToken = Hash::make($plainToken);

        // Create invitation record
        $invitation = new Invitation();
        $invitation->setEmail($email);
        $invitation->setToken($hashedToken);
        $invitation->setRoleId($roleId ? (int) $roleId : null);
        $invitation->setInvitedBy(Auth::user() ? Auth::user()->getId() : null);
        $invitation->setExpiresAt(new \DateTime('+72 hours'));
        $invitation->save();

        // Send invite email
        $this->sendInvitationEmail($email, $plainToken);

        $this->flash('success', 'Invitation sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.');
        $this->redirect(admin_url('users'));
    }

    public function resendInvite(string $id): void
    {
        $this->requirePermission('users.create');

        $invitation = Invitation::find((int) $id);
        if (!$invitation || $invitation->isAccepted()) {
            $this->flash('errors', ['invite' => 'Invitation not found or already accepted.']);
            $this->redirect(admin_url('users'));
            return;
        }

        // Generate a new token and reset expiry
        $plainToken = Hash::randomToken(32);
        $invitation->setToken(Hash::make($plainToken));
        $invitation->setExpiresAt(new \DateTime('+72 hours'));
        $invitation->save();

        // Send email
        $this->sendInvitationEmail($invitation->getEmail(), $plainToken);

        $this->flash('success', 'Invitation resent to ' . htmlspecialchars($invitation->getEmail(), ENT_QUOTES, 'UTF-8') . '.');
        $this->redirect(admin_url('users'));
    }

    public function cancelInvite(string $id): void
    {
        $this->requirePermission('users.create');

        $invitation = Invitation::find((int) $id);
        if ($invitation) {
            $invitation->delete();
            $this->flash('success', 'Invitation cancelled.');
        }

        $this->redirect(admin_url('users'));
    }

    /**
     * Send the invitation email.
     */
    private function sendInvitationEmail(string $email, string $plainToken): void
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $basePath = $_ENV['BASE_PATH'] ?? '';
        $acceptUrl = $appUrl . $basePath . '/zephyrphp/auth/invite/accept?token=' . urlencode($plainToken) . '&email=' . urlencode($email);
        $appName = $_ENV['APP_NAME'] ?? 'ZephyrPHP';

        $mail = MailService::getInstance();

        // Try template first
        $sent = $mail->sendTemplate('invitation', $email, [
            'accept_url' => $acceptUrl,
            'app_name' => $appName,
            'expire_hours' => 72,
        ]);

        // Fallback: raw HTML email
        if (!$sent) {
            $subject = "You've been invited to {$appName}";
            $body = $this->buildInvitationEmailHtml($acceptUrl, $appName);
            $mail->send($email, $subject, $body);
        }
    }

    /**
     * Build fallback HTML for invitation email.
     */
    private function buildInvitationEmailHtml(string $acceptUrl, string $appName): string
    {
        $escapedUrl = htmlspecialchars($acceptUrl, ENT_QUOTES, 'UTF-8');
        $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f4f5; padding: 40px 20px;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; border: 1px solid #e4e4e7;">
        <h2 style="margin: 0 0 16px; font-size: 1.25rem; color: #18181b;">You've Been Invited</h2>
        <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px;">
            You've been invited to join <strong>{$escapedAppName}</strong>. Click the button below to create your account.
            This invitation expires in 72 hours.
        </p>
        <a href="{$escapedUrl}" style="display: inline-block; padding: 12px 24px; background: #06b6d4; color: #000; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 0.9rem;">Accept Invitation</a>
        <p style="color: #a1a1aa; font-size: 0.8rem; margin: 24px 0 0; line-height: 1.5;">
            If you did not expect this invitation, you can safely ignore this email.<br>
            If the button doesn't work, copy and paste this URL into your browser:<br>
            <span style="color: #52525b; word-break: break-all;">{$escapedUrl}</span>
        </p>
    </div>
</body>
</html>
HTML;
    }
}
