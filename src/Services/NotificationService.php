<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Notification;
use ZephyrPHP\Cms\Models\NotificationPreference;

class NotificationService
{
    /**
     * Notification types and their labels.
     */
    public const TYPES = [
        'entry_published' => 'Entry Published',
        'form_submitted' => 'Form Submission',
        'user_registered' => 'User Registered',
        'scheduled_published' => 'Scheduled Entry Published',
    ];

    /**
     * Available channels.
     */
    public const CHANNELS = ['app', 'email'];

    /**
     * Send a notification (in-app + email based on user preferences).
     */
    public static function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $meta = null,
        ?string $emailTo = null,
        array $emailVariables = []
    ): void {
        $prefs = self::getUserPreferences($userId);

        // In-app notification
        if ($prefs->isEnabled($type, 'app')) {
            self::createNotification($userId, $type, $title, $body, $link, $meta);
        }

        // Email notification
        if ($prefs->isEnabled($type, 'email') && $emailTo) {
            $templateSlug = self::getTemplateSlugForType($type);
            if ($templateSlug) {
                MailService::getInstance()->sendTemplate($templateSlug, $emailTo, $emailVariables);
            }
        }
    }

    /**
     * Notify all admin users about an event.
     */
    public static function notifyAdmins(
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $meta = null,
        array $emailVariables = []
    ): void {
        try {
            if (!class_exists(\ZephyrPHP\Auth\Models\User::class)) {
                return;
            }
            $users = \ZephyrPHP\Auth\Models\User::findAll();
            foreach ($users as $user) {
                $email = method_exists($user, 'getEmail') ? $user->getEmail() : null;
                self::notify(
                    $user->getId(),
                    $type,
                    $title,
                    $body,
                    $link,
                    $meta,
                    $email,
                    $emailVariables
                );
            }
        } catch (\Throwable $e) {
            // Silently fail — notification is non-critical
        }
    }

    /**
     * Create an in-app notification record.
     */
    public static function createNotification(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $meta = null
    ): Notification {
        $notification = new Notification();
        $notification->setUserId($userId);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setLink($link);
        $notification->setMeta($meta);
        $notification->save();

        return $notification;
    }

    /**
     * Get unread count for a user.
     */
    public static function getUnreadCount(int $userId): int
    {
        return Notification::count(['userId' => $userId, 'isRead' => false]);
    }

    /**
     * Get notifications for a user (paginated).
     */
    public static function getForUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return Notification::findBy(
            ['userId' => $userId],
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );
    }

    /**
     * Mark a notification as read.
     */
    public static function markRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::find($notificationId);
        if (!$notification || $notification->getUserId() !== $userId) {
            return false;
        }

        $notification->setIsRead(true);
        $notification->save();
        return true;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public static function markAllRead(int $userId): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $conn->executeStatement(
                "UPDATE cms_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Get or create user notification preferences.
     */
    public static function getUserPreferences(int $userId): NotificationPreference
    {
        $pref = NotificationPreference::findOneBy(['userId' => $userId]);
        if ($pref) {
            return $pref;
        }

        // Return a new unsaved instance with defaults (all enabled)
        $pref = new NotificationPreference();
        $pref->setUserId($userId);
        $pref->setPreferences([]);
        return $pref;
    }

    /**
     * Save user notification preferences.
     */
    public static function savePreferences(int $userId, array $preferences): void
    {
        $pref = NotificationPreference::findOneBy(['userId' => $userId]);
        if (!$pref) {
            $pref = new NotificationPreference();
            $pref->setUserId($userId);
        }

        // Sanitize: only allow known types and channels
        $sanitized = [];
        foreach (self::TYPES as $type => $label) {
            foreach (self::CHANNELS as $channel) {
                $sanitized[$type][$channel] = (bool) ($preferences[$type][$channel] ?? true);
            }
        }

        $pref->setPreferences($sanitized);
        $pref->save();
    }

    /**
     * Map notification type to email template slug.
     */
    private static function getTemplateSlugForType(string $type): ?string
    {
        return match ($type) {
            'entry_published' => 'entry-published',
            'form_submitted' => 'form-submitted',
            'user_registered' => 'user-registered',
            'scheduled_published' => 'scheduled-published',
            default => null,
        };
    }
}
