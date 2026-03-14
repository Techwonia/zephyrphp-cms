<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_notification_preferences')]
#[ORM\HasLifecycleCallbacks]
class NotificationPreference extends Model
{
    #[ORM\Column(name: 'user_id', type: 'integer', unique: true)]
    protected int $userId;

    #[ORM\Column(type: 'json')]
    protected array $preferences = [];

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $userId): self { $this->userId = $userId; return $this; }

    public function getPreferences(): array { return $this->preferences; }
    public function setPreferences(array $preferences): self { $this->preferences = $preferences; return $this; }

    /**
     * Check if a notification type is enabled for a channel.
     */
    public function isEnabled(string $type, string $channel = 'app'): bool
    {
        return (bool) ($this->preferences[$type][$channel] ?? true);
    }
}
