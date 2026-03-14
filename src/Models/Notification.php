<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_notifications')]
#[ORM\HasLifecycleCallbacks]
class Notification extends Model
{
    #[ORM\Column(name: 'user_id', type: 'integer')]
    protected int $userId;

    #[ORM\Column(type: 'string', length: 50)]
    protected string $type = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $body = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    protected ?string $link = null;

    #[ORM\Column(name: 'is_read', type: 'boolean')]
    protected bool $isRead = false;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $meta = null;

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $userId): self { $this->userId = $userId; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body; return $this; }

    public function getLink(): ?string { return $this->link; }
    public function setLink(?string $link): self { $this->link = $link; return $this; }

    public function isRead(): bool { return $this->isRead; }
    public function setIsRead(bool $isRead): self { $this->isRead = $isRead; return $this; }

    public function getMeta(): ?array { return $this->meta; }
    public function setMeta(?array $meta): self { $this->meta = $meta; return $this; }
}
