<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_workflow_transitions')]
#[ORM\HasLifecycleCallbacks]
class WorkflowTransition extends Model
{
    #[ORM\Column(name: 'table_name', type: 'string', length: 255)]
    protected string $tableName = '';

    #[ORM\Column(name: 'entry_id', type: 'string', length: 50)]
    protected string $entryId = '';

    #[ORM\Column(name: 'from_stage', type: 'string', length: 50)]
    protected string $fromStage = '';

    #[ORM\Column(name: 'to_stage', type: 'string', length: 50)]
    protected string $toStage = '';

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    protected ?int $userId = null;

    #[ORM\Column(name: 'user_name', type: 'string', length: 255, nullable: true)]
    protected ?string $userName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $comment = null;

    #[ORM\Column(type: 'string', length: 20)]
    protected string $action = 'advance';

    public function getTableName(): string { return $this->tableName; }
    public function setTableName(string $tableName): self { $this->tableName = $tableName; return $this; }

    public function getEntryId(): string { return $this->entryId; }
    public function setEntryId(string $entryId): self { $this->entryId = $entryId; return $this; }

    public function getFromStage(): string { return $this->fromStage; }
    public function setFromStage(string $fromStage): self { $this->fromStage = $fromStage; return $this; }

    public function getToStage(): string { return $this->toStage; }
    public function setToStage(string $toStage): self { $this->toStage = $toStage; return $this; }

    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $userId): self { $this->userId = $userId; return $this; }

    public function getUserName(): ?string { return $this->userName; }
    public function setUserName(?string $userName): self { $this->userName = $userName; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
}
