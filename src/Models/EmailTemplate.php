<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_email_templates')]
#[ORM\HasLifecycleCallbacks]
class EmailTemplate extends Model
{
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected string $slug = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $subject = '';

    #[ORM\Column(name: 'body_twig', type: 'text')]
    protected string $bodyTwig = '';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive = true;

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = $subject; return $this; }

    public function getBodyTwig(): string { return $this->bodyTwig; }
    public function setBodyTwig(string $bodyTwig): self { $this->bodyTwig = $bodyTwig; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
}
