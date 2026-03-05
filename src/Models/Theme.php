<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_themes')]
#[ORM\HasLifecycleCallbacks]
class Theme extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected string $slug = '';

    #[ORM\Column(type: 'string', length: 20)]
    protected string $status = 'draft';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    // --- Getters ---

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    // --- Setters ---

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }
}
