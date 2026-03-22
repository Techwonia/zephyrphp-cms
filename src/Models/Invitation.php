<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_invitations')]
#[ORM\HasLifecycleCallbacks]
class Invitation extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $email = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $token = '';

    #[ORM\Column(name: 'role_id', type: 'integer', nullable: true)]
    protected ?int $roleId = null;

    #[ORM\Column(name: 'invited_by', type: 'integer', nullable: true)]
    protected ?int $invitedBy = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime')]
    protected \DateTime $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: 'datetime', nullable: true)]
    protected ?\DateTime $acceptedAt = null;

    public function __construct()
    {
        $this->expiresAt = new \DateTime('+72 hours');
    }

    // --- Getters ---

    public function getEmail(): string { return $this->email; }
    public function getToken(): string { return $this->token; }
    public function getRoleId(): ?int { return $this->roleId; }
    public function getInvitedBy(): ?int { return $this->invitedBy; }
    public function getExpiresAt(): \DateTime { return $this->expiresAt; }
    public function getAcceptedAt(): ?\DateTime { return $this->acceptedAt; }

    // --- Setters ---

    public function setEmail(string $email): self { $this->email = $email; return $this; }
    public function setToken(string $token): self { $this->token = $token; return $this; }
    public function setRoleId(?int $roleId): self { $this->roleId = $roleId; return $this; }
    public function setInvitedBy(?int $invitedBy): self { $this->invitedBy = $invitedBy; return $this; }
    public function setExpiresAt(\DateTime $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function setAcceptedAt(?\DateTime $acceptedAt): self { $this->acceptedAt = $acceptedAt; return $this; }

    /**
     * Check if invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTime();
    }

    /**
     * Check if invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    /**
     * Check if invitation is still pending (not expired, not accepted).
     */
    public function isPending(): bool
    {
        return !$this->isExpired() && !$this->isAccepted();
    }
}
