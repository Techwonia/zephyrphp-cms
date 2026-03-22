<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

/**
 * Content Template — a reusable set of field values that can pre-fill
 * new entries in a collection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_content_templates')]
#[ORM\HasLifecycleCallbacks]
class ContentTemplate extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(name: 'collection_slug', type: 'string', length: 255)]
    protected string $collectionSlug = '';

    #[ORM\Column(type: 'json')]
    protected array $data = [];

    #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
    protected ?int $createdBy = null;

    // --- Getters ---

    public function getName(): string
    {
        return $this->name;
    }

    public function getCollectionSlug(): string
    {
        return $this->collectionSlug;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    // --- Setters ---

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setCollectionSlug(string $collectionSlug): self
    {
        $this->collectionSlug = $collectionSlug;
        return $this;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
