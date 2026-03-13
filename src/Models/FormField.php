<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'fb_form_fields')]
#[ORM\HasLifecycleCallbacks]
class FormField extends Model
{
    #[ORM\ManyToOne(targetEntity: Form::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(name: 'form_id', nullable: false, onDelete: 'CASCADE')]
    protected Form $form;

    #[ORM\Column(name: 'step_id', type: 'integer', nullable: true)]
    protected ?int $stepId = null;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $slug = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $label = '';

    #[ORM\Column(type: 'string', length: 50)]
    protected string $type = 'text';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $placeholder = null;

    #[ORM\Column(name: 'default_value', type: 'string', length: 500, nullable: true)]
    protected ?string $defaultValue = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    protected ?string $validation = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $options = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    protected int $sortOrder = 0;

    #[ORM\Column(name: 'is_required', type: 'boolean')]
    protected bool $isRequired = false;

    /**
     * Display-only field types that don't submit data.
     */
    private const DISPLAY_ONLY_TYPES = ['heading', 'paragraph', 'divider'];

    // --- Getters ---

    public function getForm(): Form
    {
        return $this->form;
    }

    public function getStepId(): ?int
    {
        return $this->stepId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function getValidation(): ?string
    {
        return $this->validation;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function getChoices(): array
    {
        return $this->options['choices'] ?? [];
    }

    public function getWidth(): string
    {
        return $this->options['width'] ?? 'col-12';
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isDisplayOnly(): bool
    {
        return in_array($this->type, self::DISPLAY_ONLY_TYPES, true);
    }

    // --- Setters ---

    public function setForm(Form $form): self
    {
        $this->form = $form;
        return $this;
    }

    public function setStepId(?int $stepId): self
    {
        $this->stepId = $stepId;
        return $this;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setPlaceholder(?string $placeholder): self
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function setDefaultValue(?string $defaultValue): self
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function setValidation(?string $validation): self
    {
        $this->validation = $validation;
        return $this;
    }

    public function setOptions(?array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function setIsRequired(bool $isRequired): self
    {
        $this->isRequired = $isRequired;
        return $this;
    }
}
