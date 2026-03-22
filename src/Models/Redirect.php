<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_redirects')]
#[ORM\HasLifecycleCallbacks]
class Redirect extends Model
{
    #[ORM\Column(name: 'from_path', type: 'string', length: 2048)]
    protected string $fromPath = '';

    #[ORM\Column(name: 'to_url', type: 'string', length: 2048)]
    protected string $toUrl = '';

    #[ORM\Column(name: 'status_code', type: 'integer')]
    protected int $statusCode = 301;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive = true;

    #[ORM\Column(name: 'hit_count', type: 'integer')]
    protected int $hitCount = 0;

    #[ORM\Column(name: 'last_hit_at', type: 'datetime', nullable: true)]
    protected ?\DateTime $lastHitAt = null;

    // --- Getters ---

    public function getFromPath(): string { return $this->fromPath; }
    public function getToUrl(): string { return $this->toUrl; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function isActive(): bool { return $this->isActive; }
    public function getHitCount(): int { return $this->hitCount; }
    public function getLastHitAt(): ?\DateTime { return $this->lastHitAt; }

    // --- Setters ---

    public function setFromPath(string $fromPath): self { $this->fromPath = $fromPath; return $this; }
    public function setToUrl(string $toUrl): self { $this->toUrl = $toUrl; return $this; }
    public function setStatusCode(int $statusCode): self { $this->statusCode = $statusCode; return $this; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function setHitCount(int $hitCount): self { $this->hitCount = $hitCount; return $this; }
    public function setLastHitAt(?\DateTime $lastHitAt): self { $this->lastHitAt = $lastHitAt; return $this; }
}
