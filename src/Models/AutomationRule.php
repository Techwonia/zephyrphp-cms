<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_automation_rules')]
#[ORM\HasLifecycleCallbacks]
class AutomationRule extends Model
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(name: 'collection_slug', type: 'string', length: 255)]
    protected string $collectionSlug = '';

    #[ORM\Column(name: 'trigger_type', type: 'string', length: 50)]
    protected string $triggerType = 'schedule';

    #[ORM\Column(type: 'text')]
    protected string $conditions = '[]';

    #[ORM\Column(type: 'text')]
    protected string $actions = '[]';

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    protected ?string $schedule = null;

    #[ORM\Column(name: 'last_run_at', type: 'datetime', nullable: true)]
    protected ?\DateTime $lastRunAt = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive = true;

    // --- Getters ---

    public function getName(): string { return $this->name; }
    public function getCollectionSlug(): string { return $this->collectionSlug; }
    public function getTriggerType(): string { return $this->triggerType; }

    public function getConditions(): array
    {
        $decoded = json_decode($this->conditions, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getActions(): array
    {
        $decoded = json_decode($this->actions, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getConditionsJson(): string { return $this->conditions; }
    public function getActionsJson(): string { return $this->actions; }
    public function getSchedule(): ?string { return $this->schedule; }
    public function getLastRunAt(): ?\DateTime { return $this->lastRunAt; }
    public function isActive(): bool { return $this->isActive; }

    // --- Setters ---

    public function setName(string $name): self { $this->name = $name; return $this; }
    public function setCollectionSlug(string $collectionSlug): self { $this->collectionSlug = $collectionSlug; return $this; }
    public function setTriggerType(string $triggerType): self { $this->triggerType = $triggerType; return $this; }

    public function setConditions(array $conditions): self
    {
        $this->conditions = json_encode($conditions, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    public function setConditionsRaw(string $json): self
    {
        // Validate it's proper JSON
        $decoded = json_decode($json, true);
        $this->conditions = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '[]';
        return $this;
    }

    public function setActions(array $actions): self
    {
        $this->actions = json_encode($actions, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    public function setActionsRaw(string $json): self
    {
        $decoded = json_decode($json, true);
        $this->actions = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '[]';
        return $this;
    }

    public function setSchedule(?string $schedule): self { $this->schedule = $schedule; return $this; }
    public function setLastRunAt(?\DateTime $lastRunAt): self { $this->lastRunAt = $lastRunAt; return $this; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
}
