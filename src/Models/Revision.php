<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_revisions')]
#[ORM\HasLifecycleCallbacks]
class Revision extends Model
{
    #[ORM\Column(name: 'table_name', type: 'string', length: 255)]
    protected string $tableName = '';

    #[ORM\Column(name: 'entry_id', type: 'string', length: 50)]
    protected string $entryId = '';

    #[ORM\Column(name: 'data', type: 'json')]
    protected array $data = [];

    #[ORM\Column(name: 'changed_fields', type: 'json', nullable: true)]
    protected ?array $changedFields = null;

    #[ORM\Column(name: 'action', type: 'string', length: 20)]
    protected string $action = 'update'; // create, update, delete

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    protected ?int $userId = null;

    #[ORM\Column(name: 'user_name', type: 'string', length: 255, nullable: true)]
    protected ?string $userName = null;

    // --- Getters ---

    public function getTableName(): string { return $this->tableName; }
    public function getEntryId(): string { return $this->entryId; }
    public function getData(): array { return $this->data; }
    public function getChangedFields(): ?array { return $this->changedFields; }
    public function getAction(): string { return $this->action; }
    public function getUserId(): ?int { return $this->userId; }
    public function getUserName(): ?string { return $this->userName; }

    // --- Setters ---

    public function setTableName(string $tableName): self { $this->tableName = $tableName; return $this; }
    public function setEntryId(string $entryId): self { $this->entryId = $entryId; return $this; }
    public function setData(array $data): self { $this->data = $data; return $this; }
    public function setChangedFields(?array $changedFields): self { $this->changedFields = $changedFields; return $this; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function setUserId(?int $userId): self { $this->userId = $userId; return $this; }
    public function setUserName(?string $userName): self { $this->userName = $userName; return $this; }

    /**
     * Create a revision entry for an action.
     */
    public static function record(string $tableName, string|int $entryId, array $data, string $action = 'update', ?array $changedFields = null): void
    {
        try {
            $revision = new self();
            $revision->setTableName($tableName);
            $revision->setEntryId((string) $entryId);
            $revision->setData($data);
            $revision->setAction($action);
            $revision->setChangedFields($changedFields);

            if (class_exists(\ZephyrPHP\Auth\Auth::class) && \ZephyrPHP\Auth\Auth::check()) {
                $user = \ZephyrPHP\Auth\Auth::user();
                $revision->setUserId($user->getId());
                $revision->setUserName($user->getName() ?? $user->getEmail() ?? '');
            }

            $revision->save();
        } catch (\Exception $e) {
            // Silently fail — versioning should never break the main operation
        }
    }

    /**
     * Get revision history for an entry, newest first.
     */
    public static function getHistory(string $tableName, string|int $entryId, int $limit = 50): array
    {
        return self::findBy(
            ['tableName' => $tableName, 'entryId' => (string) $entryId],
            ['createdAt' => 'DESC'],
            $limit
        );
    }
}
