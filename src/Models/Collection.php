<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;

#[ORM\Entity]
#[ORM\Table(name: 'cms_collections')]
#[ORM\HasLifecycleCallbacks]
class Collection extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected string $slug = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    protected ?string $icon = null;

    #[ORM\Column(name: 'is_api_enabled', type: 'boolean')]
    protected bool $isApiEnabled = false;

    #[ORM\Column(name: 'is_publishable', type: 'boolean')]
    protected bool $isPublishable = false;

    #[ORM\Column(name: 'primary_key_type', type: 'string', length: 10)]
    protected string $primaryKeyType = 'integer';

    #[ORM\Column(name: 'has_slug', type: 'boolean')]
    protected bool $hasSlug = false;

    #[ORM\Column(name: 'slug_source_field', type: 'string', length: 100, nullable: true)]
    protected ?string $slugSourceField = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    protected int $sortOrder = 0;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'collection', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    protected DoctrineCollection $fields;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isApiEnabled(): bool
    {
        return $this->isApiEnabled;
    }

    public function isPublishable(): bool
    {
        return $this->isPublishable;
    }

    public function getPrimaryKeyType(): string
    {
        return $this->primaryKeyType;
    }

    public function isUuid(): bool
    {
        return $this->primaryKeyType === 'uuid';
    }

    public function hasSlug(): bool
    {
        return $this->hasSlug;
    }

    public function getSlugSourceField(): ?string
    {
        return $this->slugSourceField;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getFields(): DoctrineCollection
    {
        return $this->fields;
    }

    /**
     * Get the dynamic table name for this collection
     */
    public function getTableName(): string
    {
        return 'cms_' . $this->slug;
    }

    /**
     * Get fields that should be shown in list view
     */
    public function getListableFields(): array
    {
        return $this->fields->filter(fn(Field $f) => $f->isListable())->toArray();
    }

    /**
     * Get fields that are searchable
     */
    public function getSearchableFields(): array
    {
        return $this->fields->filter(fn(Field $f) => $f->isSearchable())->toArray();
    }

    /**
     * Get fields that are sortable
     */
    public function getSortableFields(): array
    {
        return $this->fields->filter(fn(Field $f) => $f->isSortable())->toArray();
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

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function setIsApiEnabled(bool $isApiEnabled): self
    {
        $this->isApiEnabled = $isApiEnabled;
        return $this;
    }

    public function setIsPublishable(bool $isPublishable): self
    {
        $this->isPublishable = $isPublishable;
        return $this;
    }

    public function setPrimaryKeyType(string $type): self
    {
        $this->primaryKeyType = in_array($type, ['integer', 'uuid']) ? $type : 'integer';
        return $this;
    }

    public function setHasSlug(bool $hasSlug): self
    {
        $this->hasSlug = $hasSlug;
        return $this;
    }

    public function setSlugSourceField(?string $slugSourceField): self
    {
        $this->slugSourceField = $slugSourceField;
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
