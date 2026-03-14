<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_translations')]
#[ORM\HasLifecycleCallbacks]
class Translation extends Model
{
    #[ORM\Column(name: 'table_name', type: 'string', length: 255)]
    protected string $tableName = '';

    #[ORM\Column(name: 'entry_id', type: 'string', length: 50)]
    protected string $entryId = '';

    #[ORM\Column(type: 'string', length: 10)]
    protected string $locale = '';

    #[ORM\Column(name: 'field_slug', type: 'string', length: 100)]
    protected string $fieldSlug = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $value = null;

    public function getTableName(): string { return $this->tableName; }
    public function setTableName(string $tableName): self { $this->tableName = $tableName; return $this; }

    public function getEntryId(): string { return $this->entryId; }
    public function setEntryId(string $entryId): self { $this->entryId = $entryId; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): self { $this->locale = $locale; return $this; }

    public function getFieldSlug(): string { return $this->fieldSlug; }
    public function setFieldSlug(string $fieldSlug): self { $this->fieldSlug = $fieldSlug; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self { $this->value = $value; return $this; }
}
