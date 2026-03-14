<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

/**
 * Activity Log — system-wide audit trail for CMS actions.
 *
 * Tracks who did what, when, and to which resource.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_activity_log')]
#[ORM\HasLifecycleCallbacks]
class ActivityLog extends Model
{
    #[ORM\Column(type: 'string', length: 50)]
    protected string $action = '';

    #[ORM\Column(name: 'resource_type', type: 'string', length: 50)]
    protected string $resourceType = '';

    #[ORM\Column(name: 'resource_id', type: 'string', length: 100, nullable: true)]
    protected ?string $resourceId = null;

    #[ORM\Column(name: 'resource_label', type: 'string', length: 255, nullable: true)]
    protected ?string $resourceLabel = null;

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    protected ?int $userId = null;

    #[ORM\Column(name: 'user_name', type: 'string', length: 255, nullable: true)]
    protected ?string $userName = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    protected ?string $ipAddress = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $meta = null;

    // --- Getters ---

    public function getAction(): string
    {
        return $this->action;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getResourceLabel(): ?string
    {
        return $this->resourceLabel;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    // --- Setters ---

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function setResourceType(string $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function setResourceId(?string $resourceId): self
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function setResourceLabel(?string $resourceLabel): self
    {
        $this->resourceLabel = $resourceLabel;
        return $this;
    }

    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function setUserName(?string $userName): self
    {
        $this->userName = $userName;
        return $this;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }
}
