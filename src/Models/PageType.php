<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;

#[ORM\Entity]
#[ORM\Table(name: 'cms_page_types')]
#[ORM\HasLifecycleCallbacks]
class PageType extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected string $slug = '';

    #[ORM\Column(type: 'string', length: 255)]
    protected string $template = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(name: 'has_seo', type: 'boolean')]
    protected bool $hasSeo = true;

    #[ORM\Column(name: 'is_publishable', type: 'boolean')]
    protected bool $isPublishable = true;

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    #[ORM\Column(name: 'page_mode', type: 'string', length: 20)]
    protected string $pageMode = 'static';

    #[ORM\Column(type: 'string', length: 100)]
    protected string $layout = 'base';

    #[ORM\Column(name: 'url_prefix', type: 'string', length: 255, nullable: true)]
    protected ?string $urlPrefix = null;

    #[ORM\Column(name: 'items_per_page', type: 'integer')]
    protected int $itemsPerPage = 10;

    #[ORM\OneToMany(targetEntity: PageTypeField::class, mappedBy: 'pageType', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function hasSeo(): bool
    {
        return $this->hasSeo;
    }

    public function isPublishable(): bool
    {
        return $this->isPublishable;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getPageMode(): string
    {
        return $this->pageMode;
    }

    public function isDynamic(): bool
    {
        return $this->pageMode === 'dynamic';
    }

    public function isStatic(): bool
    {
        return $this->pageMode === 'static';
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function getUrlPrefix(): ?string
    {
        return $this->urlPrefix;
    }

    public function getItemsPerPage(): int
    {
        return $this->itemsPerPage;
    }

    public function getFields(): DoctrineCollection
    {
        return $this->fields;
    }

    public function getTableName(): string
    {
        return 'cms_pt_' . $this->slug;
    }

    public function getListableFields(): array
    {
        return $this->fields->filter(fn(PageTypeField $f) => $f->isListable())->toArray();
    }

    public function getSearchableFields(): array
    {
        return $this->fields->filter(fn(PageTypeField $f) => $f->isSearchable())->toArray();
    }

    public function getPublicUrl(?string $entrySlug = null): string
    {
        if ($this->urlPrefix) {
            $prefix = '/' . ltrim($this->urlPrefix, '/');
            return $entrySlug ? $prefix . '/' . $entrySlug : $prefix;
        }
        return $entrySlug ? '/page/' . $entrySlug : '/page';
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

    public function setTemplate(string $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setHasSeo(bool $hasSeo): self
    {
        $this->hasSeo = $hasSeo;
        return $this;
    }

    public function setIsPublishable(bool $isPublishable): self
    {
        $this->isPublishable = $isPublishable;
        return $this;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function setPageMode(string $pageMode): self
    {
        $this->pageMode = $pageMode;
        return $this;
    }

    public function setLayout(string $layout): self
    {
        $this->layout = $layout;
        return $this;
    }

    public function setUrlPrefix(?string $urlPrefix): self
    {
        $this->urlPrefix = $urlPrefix;
        return $this;
    }

    public function setItemsPerPage(int $itemsPerPage): self
    {
        $this->itemsPerPage = $itemsPerPage;
        return $this;
    }
}
