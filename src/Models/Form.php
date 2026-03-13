<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;

#[ORM\Entity]
#[ORM\Table(name: 'fb_forms')]
#[ORM\HasLifecycleCallbacks]
class Form extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    protected string $slug = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: 'string', length: 20)]
    protected string $status = 'draft';

    #[ORM\Column(name: 'is_multi_step', type: 'boolean')]
    protected bool $isMultiStep = false;

    #[ORM\Column(name: 'template_slug', type: 'string', length: 100, nullable: true)]
    protected ?string $templateSlug = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $settings = null;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    #[ORM\OneToMany(targetEntity: FormField::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    protected DoctrineCollection $fields;

    #[ORM\OneToMany(targetEntity: FormStep::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stepNumber' => 'ASC'])]
    protected DoctrineCollection $steps;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
        $this->steps = new ArrayCollection();
    }

    // --- Getters ---

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isMultiStep(): bool
    {
        return $this->isMultiStep;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getTemplateSlug(): ?string
    {
        return $this->templateSlug;
    }

    public function getSettings(): array
    {
        return $this->settings ?? [];
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->getSettings()[$key] ?? $default;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getFields(): DoctrineCollection
    {
        return $this->fields;
    }

    public function getSteps(): DoctrineCollection
    {
        return $this->steps;
    }

    /**
     * Get fields grouped by step for multi-step rendering.
     */
    public function getFieldsByStep(): array
    {
        $grouped = [];
        foreach ($this->fields as $field) {
            $stepId = $field->getStepId();
            $grouped[$stepId ?? 0][] = $field;
        }
        return $grouped;
    }

    /**
     * Get submittable fields (excludes display-only types).
     */
    public function getSubmittableFields(): array
    {
        return $this->fields->filter(fn(FormField $f) => !$f->isDisplayOnly())->toArray();
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

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = in_array($status, ['draft', 'active', 'archived']) ? $status : 'draft';
        return $this;
    }

    public function setIsMultiStep(bool $isMultiStep): self
    {
        $this->isMultiStep = $isMultiStep;
        return $this;
    }

    public function setTemplateSlug(?string $templateSlug): self
    {
        $this->templateSlug = $templateSlug;
        return $this;
    }

    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    public function setSetting(string $key, mixed $value): self
    {
        $settings = $this->getSettings();
        $settings[$key] = $value;
        $this->settings = $settings;
        return $this;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
