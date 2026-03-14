<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\NotificationService;
use ZephyrPHP\Cms\Services\PermissionService;

class NotificationController extends Controller
{
    private function requireAccess(): ?int
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return null;
        }
        if (!PermissionService::can('cms.access')) {
            $this->redirect('/login');
            return null;
        }
        return Auth::user()?->getId();
    }

    /**
     * List notifications.
     */
    public function index(): string
    {
        $userId = $this->requireAccess();
        if (!$userId) return '';

        $page = max(1, (int) $this->input('page', '1'));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $notifications = NotificationService::getForUser($userId, $perPage, $offset);
        $unreadCount = NotificationService::getUnreadCount($userId);

        return $this->render('cms::notifications/index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'page' => $page,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(string $id): void
    {
        $userId = $this->requireAccess();
        if (!$userId) return;

        NotificationService::markRead((int) $id, $userId);

        // If AJAX, return JSON
        if ($this->isAjax()) {
            $this->json(['success' => true, 'unread' => NotificationService::getUnreadCount($userId)]);
            return;
        }

        $this->back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(): void
    {
        $userId = $this->requireAccess();
        if (!$userId) return;

        NotificationService::markAllRead($userId);

        if ($this->isAjax()) {
            $this->json(['success' => true, 'unread' => 0]);
            return;
        }

        $this->flash('success', 'All notifications marked as read.');
        $this->redirect('/cms/notifications');
    }

    /**
     * Get unread count (AJAX).
     */
    public function unreadCount(): void
    {
        $userId = $this->requireAccess();
        if (!$userId) {
            $this->json(['count' => 0]);
            return;
        }

        $this->json(['count' => NotificationService::getUnreadCount($userId)]);
    }

    /**
     * Show notification preferences.
     */
    public function preferences(): string
    {
        $userId = $this->requireAccess();
        if (!$userId) return '';

        $prefs = NotificationService::getUserPreferences($userId);

        return $this->render('cms::notifications/preferences', [
            'preferences' => $prefs->getPreferences(),
            'types' => NotificationService::TYPES,
            'channels' => NotificationService::CHANNELS,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Save notification preferences.
     */
    public function savePreferences(): void
    {
        $userId = $this->requireAccess();
        if (!$userId) return;

        $preferences = [];
        foreach (NotificationService::TYPES as $type => $label) {
            foreach (NotificationService::CHANNELS as $channel) {
                $preferences[$type][$channel] = $this->boolean("{$type}_{$channel}");
            }
        }

        NotificationService::savePreferences($userId, $preferences);

        $this->flash('success', 'Notification preferences saved.');
        $this->redirect('/cms/notifications/preferences');
    }

}
