<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class SessionManagerController extends Controller
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

    public function index(): string
    {
        $this->requirePermission('users.view');

        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

        // Get active sessions
        $sessions = $conn->fetchAllAssociative(
            'SELECT s.*, u.name as user_name, u.email as user_email
             FROM cms_sessions s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.last_activity > ?
             ORDER BY s.last_activity DESC',
            [date('Y-m-d H:i:s', time() - (int) env('SESSION_LIFETIME', 120) * 60)]
        );

        // Parse user agent info
        foreach ($sessions as &$session) {
            $session['browser'] = $this->parseBrowser($session['user_agent'] ?? '');
            $session['is_current'] = ($session['session_id'] === (session_id() ?: ''));
        }
        unset($session);

        // Login history
        $history = $conn->fetchAllAssociative(
            'SELECT * FROM cms_login_history ORDER BY created_at DESC LIMIT 50'
        );

        $stats = [
            'active_sessions' => count($sessions),
            'unique_users' => count(array_unique(array_column($sessions, 'user_id'))),
        ];

        return $this->render('cms::system/sessions', [
            'sessions' => $sessions,
            'history' => $history,
            'stats' => $stats,
            'user' => Auth::user(),
        ]);
    }

    public function terminate(string $id): void
    {
        $this->requirePermission('users.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $session = $conn->fetchAssociative(
            'SELECT * FROM cms_sessions WHERE id = ?',
            [(int) $id]
        );

        if (!$session) {
            $this->flash('errors', ['Session not found.']);
            $this->redirect('/cms/system/sessions');
            return;
        }

        // Don't allow terminating own session
        if ($session['session_id'] === (session_id() ?: '')) {
            $this->flash('errors', ['Cannot terminate your own session. Use logout instead.']);
            $this->redirect('/cms/system/sessions');
            return;
        }

        $conn->delete('cms_sessions', ['id' => (int) $id]);

        // Also destroy the session file if using file driver
        $driver = env('SESSION_DRIVER', 'file');
        if ($driver === 'file') {
            $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
            $sessFile = $basePath . '/storage/sessions/sess_' . $session['session_id'];
            if (file_exists($sessFile)) {
                unlink($sessFile);
            }
        }

        $this->logAction('session_terminated', $session['user_id'], $session['ip_address']);
        $this->flash('success', 'Session terminated.');
        $this->redirect('/cms/system/sessions');
    }

    public function terminateAll(): void
    {
        $this->requirePermission('users.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $currentSessionId = session_id() ?: '';

        // Delete all sessions except current
        $conn->executeStatement(
            'DELETE FROM cms_sessions WHERE session_id != ?',
            [$currentSessionId]
        );

        // Clean session files if using file driver
        $driver = env('SESSION_DRIVER', 'file');
        if ($driver === 'file') {
            $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
            $sessDir = $basePath . '/storage/sessions';
            if (is_dir($sessDir)) {
                foreach (glob($sessDir . '/sess_*') as $file) {
                    $fileSessionId = str_replace('sess_', '', basename($file));
                    if ($fileSessionId !== $currentSessionId) {
                        unlink($file);
                    }
                }
            }
        }

        $this->flash('success', 'All other sessions terminated.');
        $this->redirect('/cms/system/sessions');
    }

    public function terminateUser(string $userId): void
    {
        $this->requirePermission('users.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $currentSessionId = session_id() ?: '';

        $conn->executeStatement(
            'DELETE FROM cms_sessions WHERE user_id = ? AND session_id != ?',
            [(int) $userId, $currentSessionId]
        );

        $this->flash('success', 'All sessions for this user terminated.');
        $this->redirect('/cms/system/sessions');
    }

    private function ensureTable(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $sm = $conn->createSchemaManager();

        if (!$sm->tablesExist(['cms_sessions'])) {
            $conn->executeStatement('
                CREATE TABLE cms_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(128) NOT NULL,
                    user_id INT NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    last_activity DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_session_id (session_id),
                    INDEX idx_user_id (user_id),
                    INDEX idx_last_activity (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        if (!$sm->tablesExist(['cms_login_history'])) {
            $conn->executeStatement('
                CREATE TABLE cms_login_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NULL,
                    user_name VARCHAR(255) NULL,
                    user_email VARCHAR(255) NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    action VARCHAR(50) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "success",
                    details TEXT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_user_id (user_id),
                    INDEX idx_action (action),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        // Record current session if not already tracked
        $this->trackCurrentSession($conn);
    }

    private function trackCurrentSession($conn): void
    {
        $sessionId = session_id();
        if (!$sessionId) {
            return;
        }

        $user = Auth::user();
        if (!$user) {
            return;
        }

        $existing = $conn->fetchAssociative(
            'SELECT id FROM cms_sessions WHERE session_id = ?',
            [$sessionId]
        );

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $conn->update('cms_sessions', [
                'last_activity' => $now,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ], ['id' => $existing['id']]);
        } else {
            $conn->insert('cms_sessions', [
                'session_id' => $sessionId,
                'user_id' => is_array($user) ? ($user['id'] ?? null) : ($user->id ?? null),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'last_activity' => $now,
                'created_at' => $now,
            ]);
        }
    }

    private function logAction(string $action, ?int $userId, ?string $ip): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $admin = Auth::user();
            $conn->insert('cms_login_history', [
                'user_id' => $userId,
                'user_name' => is_array($admin) ? ($admin['name'] ?? '') : ($admin->name ?? ''),
                'user_email' => is_array($admin) ? ($admin['email'] ?? '') : ($admin->email ?? ''),
                'ip_address' => $ip ?? $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'action' => $action,
                'status' => 'success',
                'details' => 'Admin action by user #' . (is_array($admin) ? ($admin['id'] ?? 0) : ($admin->id ?? 0)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Silent fail for logging
        }
    }

    private function parseBrowser(string $ua): string
    {
        if (empty($ua)) {
            return 'Unknown';
        }

        $browsers = [
            'Edge' => '/Edg\/([0-9.]+)/',
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Safari\/([0-9.]+)/',
            'Opera' => '/OPR\/([0-9.]+)/',
        ];

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                $version = explode('.', $m[1])[0];
                $os = $this->parseOS($ua);
                return "{$name} {$version} ({$os})";
            }
        }

        return 'Unknown Browser';
    }

    private function parseOS(string $ua): string
    {
        $osMap = [
            'Windows' => '/Windows NT/',
            'macOS' => '/Macintosh/',
            'Linux' => '/Linux/',
            'Android' => '/Android/',
            'iOS' => '/iPhone|iPad/',
        ];

        foreach ($osMap as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Unknown OS';
    }
}
