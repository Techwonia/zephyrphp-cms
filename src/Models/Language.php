<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_languages')]
#[ORM\HasLifecycleCallbacks]
class Language extends Model
{
    #[ORM\Column(type: 'string', length: 10, unique: true)]
    protected string $code = '';

    #[ORM\Column(type: 'string', length: 100)]
    protected string $name = '';

    #[ORM\Column(name: 'native_name', type: 'string', length: 100)]
    protected string $nativeName = '';

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    protected bool $isDefault = false;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive = true;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    protected int $sortOrder = 0;

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getNativeName(): string { return $this->nativeName; }
    public function setNativeName(string $nativeName): self { $this->nativeName = $nativeName; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): self { $this->isDefault = $isDefault; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }
}
