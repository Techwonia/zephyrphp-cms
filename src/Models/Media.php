<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_media')]
#[ORM\HasLifecycleCallbacks]
class Media extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $filename = '';

    #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
    protected string $originalName = '';

    #[ORM\Column(type: 'string', length: 500)]
    protected string $path = '';

    #[ORM\Column(name: 'mime_type', type: 'string', length: 100)]
    protected string $mimeType = '';

    #[ORM\Column(type: 'integer')]
    protected int $size = 0;

    #[ORM\Column(name: 'alt_text', type: 'string', length: 255, nullable: true)]
    protected ?string $altText = null;

    #[ORM\Column(name: 'thumbnail_path', type: 'string', length: 500, nullable: true)]
    protected ?string $thumbnailPath = null;

    #[ORM\Column(name: 'uploaded_by', type: 'integer', nullable: true)]
    protected ?int $uploadedBy = null;

    // --- Getters ---

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getAltText(): ?string { return $this->altText; }
    public function getThumbnailPath(): ?string { return $this->thumbnailPath; }

    public function getUploadedBy(): ?int
    {
        return $this->uploadedBy;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailPath ? '/' . ltrim($this->thumbnailPath, '/') : null;
    }

    public function getUrl(): string
    {
        return '/' . ltrim($this->path, '/');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function getHumanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }

    // --- Setters ---

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    public function setOriginalName(string $originalName): self
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;
        return $this;
    }

    public function setAltText(?string $altText): self { $this->altText = $altText; return $this; }
    public function setThumbnailPath(?string $thumbnailPath): self { $this->thumbnailPath = $thumbnailPath; return $this; }

    public function setUploadedBy(?int $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }
}
