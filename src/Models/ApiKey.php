<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_api_keys')]
#[ORM\HasLifecycleCallbacks]
class ApiKey extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(name: 'key', type: 'string', length: 64, unique: true)]
    protected string $key = '';

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $permissions = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive = true;

    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    protected ?\DateTime $expiresAt = null;

    #[ORM\Column(name: 'last_used_at', type: 'datetime', nullable: true)]
    protected ?\DateTime $lastUsedAt = null;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    // --- Getters ---

    public function getName(): string { return $this->name; }
    public function getKey(): string { return $this->key; }
    public function getPermissions(): ?array { return $this->permissions; }
    public function isActive(): bool { return $this->isActive; }
    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }
    public function getLastUsedAt(): ?\DateTime { return $this->lastUsedAt; }
    public function getCreatedBy(): ?int { return $this->createdBy; }

    /**
     * Check if this key has a specific permission.
     * Permissions: ['read', 'write', 'delete'] or null (all permissions).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->permissions === null) {
            return true; // null = all permissions
        }
        return in_array($permission, $this->permissions);
    }

    /**
     * Check if key has access to a specific collection.
     * Collections list: null = all collections.
     */
    public function hasCollectionAccess(string $collectionSlug): bool
    {
        $collections = $this->permissions['collections'] ?? null;
        if ($collections === null) {
            return true;
        }
        return in_array($collectionSlug, $collections);
    }

    // --- Setters ---

    public function setName(string $name): self { $this->name = $name; return $this; }
    public function setKey(string $key): self { $this->key = $key; return $this; }
    public function setPermissions(?array $permissions): self { $this->permissions = $permissions; return $this; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function setExpiresAt(?\DateTime $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function setLastUsedAt(?\DateTime $lastUsedAt): self { $this->lastUsedAt = $lastUsedAt; return $this; }
    public function setCreatedBy(?int $createdBy): self { $this->createdBy = $createdBy; return $this; }

    /**
     * Generate a new random API key.
     * Returns the raw key (store the hash).
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
