<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

/**
 * Saved View — a reusable filter/sort preset for a collection's entry list.
 *
 * Examples: "Published posts", "Pending reviews", "Recent orders", "High priority".
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_saved_views')]
#[ORM\HasLifecycleCallbacks]
class SavedView extends Model
{
    #[ORM\Column(name: 'collection_slug', type: 'string', length: 100)]
    protected string $collectionSlug = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 100)]
    protected string $slug = '';

    #[ORM\Column(type: 'json')]
    protected array $filters = [];

    #[ORM\Column(name: 'sort_by', type: 'string', length: 100, nullable: true)]
    protected ?string $sortBy = null;

    #[ORM\Column(name: 'sort_dir', type: 'string', length: 4)]
    protected string $sortDir = 'DESC';

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    protected bool $isDefault = false;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    protected int $sortOrder = 0;

    // --- Getters ---

    public function getCollectionSlug(): string
    {
        return $this->collectionSlug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Filters format: [{"field": "status", "operator": "=", "value": "published"}, ...]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getSortBy(): ?string
    {
        return $this->sortBy;
    }

    public function getSortDir(): string
    {
        return $this->sortDir;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    // --- Setters ---

    public function setCollectionSlug(string $collectionSlug): self
    {
        $this->collectionSlug = $collectionSlug;
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

    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    public function setSortBy(?string $sortBy): self
    {
        $this->sortBy = $sortBy;
        return $this;
    }

    public function setSortDir(string $sortDir): self
    {
        $this->sortDir = in_array(strtoupper($sortDir), ['ASC', 'DESC']) ? strtoupper($sortDir) : 'DESC';
        return $this;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
