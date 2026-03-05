<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_page_type_fields')]
#[ORM\HasLifecycleCallbacks]
class PageTypeField extends Model
{
    #[ORM\ManyToOne(targetEntity: PageType::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(name: 'page_type_id', nullable: false, onDelete: 'CASCADE')]
    protected PageType $pageType;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 100)]
    protected string $slug = '';

    #[ORM\Column(type: 'string', length: 50)]
    protected string $type = 'text';

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $options = null;

    #[ORM\Column(name: 'is_required', type: 'boolean')]
    protected bool $isRequired = false;

    #[ORM\Column(name: 'is_searchable', type: 'boolean')]
    protected bool $isSearchable = false;

    #[ORM\Column(name: 'is_listable', type: 'boolean')]
    protected bool $isListable = true;

    #[ORM\Column(name: 'default_value', type: 'text', nullable: true)]
    protected ?string $defaultValue = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    protected int $sortOrder = 0;

    // --- Getters ---

    public function getPageType(): PageType
    {
        return $this->pageType;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function isListable(): bool
    {
        return $this->isListable;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    // --- Setters ---

    public function setPageType(PageType $pageType): self
    {
        $this->pageType = $pageType;
        return $this;
    }

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

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setOptions(?array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function setIsRequired(bool $isRequired): self
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    public function setIsSearchable(bool $isSearchable): self
    {
        $this->isSearchable = $isSearchable;
        return $this;
    }

    public function setIsListable(bool $isListable): self
    {
        $this->isListable = $isListable;
        return $this;
    }

    public function setDefaultValue(?string $defaultValue): self
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
