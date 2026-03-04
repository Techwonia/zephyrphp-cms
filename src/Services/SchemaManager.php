<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Database\Connection as ZephyrConnection;

class SchemaManager
{
    private Connection $connection;

    public function __construct()
    {
        $this->connection = ZephyrConnection::getInstance()->getConnection();
    }

    // ========================================================================
    // FIELD TYPE MAPPING
    // ========================================================================

    public function getColumnType(Field $field): string
    {
        return match ($field->getType()) {
            'text', 'email', 'slug', 'select' => Types::STRING,
            'textarea' => Types::TEXT,
            'richtext' => Types::TEXT,
            'number' => Types::INTEGER,
            'decimal' => Types::DECIMAL,
            'boolean' => Types::BOOLEAN,
            'date' => Types::DATE_MUTABLE,
            'datetime' => Types::DATETIME_MUTABLE,
            'url', 'image', 'file' => Types::STRING,
            'relation' => Types::INTEGER,
            'json' => Types::JSON,
            default => Types::STRING,
        };
    }

    public function getColumnOptions(Field $field): array
    {
        $options = ['notnull' => $field->isRequired()];

        if ($field->getDefaultValue() !== null && $field->getDefaultValue() !== '') {
            $options['default'] = $field->getDefaultValue();
        }

        return match ($field->getType()) {
            'url', 'image', 'file' => array_merge($options, ['length' => 500]),
            'text', 'email', 'slug', 'select' => array_merge($options, ['length' => 255]),
            'decimal' => array_merge($options, ['precision' => 10, 'scale' => 2]),
            default => $options,
        };
    }

    // ========================================================================
    // TABLE OPERATIONS
    // ========================================================================

    /**
     * Create the dynamic table for a collection
     */
    public function createCollectionTable(Collection $collection, array $fields = []): void
    {
        $schema = new Schema();
        $table = $schema->createTable($collection->getTableName());

        // Primary key
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);

        // User-defined field columns
        foreach ($fields as $field) {
            $table->addColumn(
                $field->getSlug(),
                $this->getColumnType($field),
                $this->getColumnOptions($field)
            );

            if ($field->isUnique()) {
                $table->addUniqueIndex([$field->getSlug()]);
            }
        }

        // Publishable columns
        if ($collection->isPublishable()) {
            $table->addColumn('status', Types::STRING, ['length' => 20, 'default' => 'draft']);
            $table->addColumn('published_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        }

        // Audit columns
        $table->addColumn('created_by', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

        // Execute
        $platform = $this->connection->getDatabasePlatform();
        $queries = $schema->toSql($platform);
        foreach ($queries as $query) {
            $this->connection->executeStatement($query);
        }
    }

    /**
     * Add a column to a collection table
     */
    public function addColumn(string $tableName, Field $field): void
    {
        // Build ALTER TABLE manually for reliability
        $columnDef = $this->buildColumnDefinition($field);
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$field->getSlug()}` {$columnDef}";
        $this->connection->executeStatement($sql);

        if ($field->isUnique()) {
            $indexName = "uniq_{$tableName}_{$field->getSlug()}";
            $sql = "ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `{$indexName}` (`{$field->getSlug()}`)";
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * Modify a column in a collection table
     */
    public function modifyColumn(string $tableName, Field $field, ?string $oldSlug = null): void
    {
        $columnDef = $this->buildColumnDefinition($field);

        if ($oldSlug && $oldSlug !== $field->getSlug()) {
            // Rename + modify
            $sql = "ALTER TABLE `{$tableName}` CHANGE `{$oldSlug}` `{$field->getSlug()}` {$columnDef}";
        } else {
            $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$field->getSlug()}` {$columnDef}";
        }

        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a column from a collection table
     */
    public function dropColumn(string $tableName, string $columnName): void
    {
        $sql = "ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`";
        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a collection table entirely
     */
    public function dropCollectionTable(string $tableName): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
            $sm->dropTable($tableName);
        }
    }

    /**
     * Check if a table exists
     */
    public function tableExists(string $tableName): bool
    {
        $sm = $this->connection->createSchemaManager();
        return $sm->tablesExist([$tableName]);
    }

    /**
     * Build MySQL column definition string
     */
    private function buildColumnDefinition(Field $field): string
    {
        $mysqlType = match ($field->getType()) {
            'text', 'email', 'slug', 'select' => 'VARCHAR(255)',
            'url', 'image', 'file' => 'VARCHAR(500)',
            'textarea' => 'TEXT',
            'richtext' => 'LONGTEXT',
            'number', 'relation' => 'INT',
            'decimal' => 'DECIMAL(10,2)',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'json' => 'JSON',
            default => 'VARCHAR(255)',
        };

        $nullable = $field->isRequired() ? 'NOT NULL' : 'NULL';
        $default = '';

        $isNumericDefault = in_array($field->getType(), ['number', 'decimal', 'boolean', 'relation']);

        if ($field->getDefaultValue() !== null && $field->getDefaultValue() !== '') {
            $defaultVal = $field->getDefaultValue();
            if ($isNumericDefault) {
                $default = "DEFAULT {$defaultVal}";
            } else {
                $default = "DEFAULT '{$defaultVal}'";
            }
        } elseif (!$field->isRequired()) {
            $default = 'DEFAULT NULL';
        }

        return "{$mysqlType} {$nullable} {$default}";
    }

    // ========================================================================
    // RELATION OPERATIONS
    // ========================================================================

    /**
     * Add a foreign key constraint for one-to-one relations
     */
    public function addForeignKey(string $sourceTable, string $columnName, string $targetTable): void
    {
        $fkName = "fk_{$sourceTable}_{$columnName}";
        $sql = "ALTER TABLE `{$sourceTable}` ADD CONSTRAINT `{$fkName}` "
             . "FOREIGN KEY (`{$columnName}`) REFERENCES `{$targetTable}`(`id`) ON DELETE SET NULL";
        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a foreign key constraint
     */
    public function dropForeignKey(string $sourceTable, string $columnName): void
    {
        $fkName = "fk_{$sourceTable}_{$columnName}";
        try {
            $sql = "ALTER TABLE `{$sourceTable}` DROP FOREIGN KEY `{$fkName}`";
            $this->connection->executeStatement($sql);
        } catch (\Exception $e) {
            // FK may not exist, safe to ignore
        }
    }

    /**
     * Get pivot table name for a relation field
     */
    public function getPivotTableName(string $sourceTable, string $fieldSlug): string
    {
        return "{$sourceTable}_{$fieldSlug}_rel";
    }

    /**
     * Create a pivot table for one-to-many or many-to-many relations
     */
    public function createPivotTable(string $sourceTable, string $targetTable, string $fieldSlug): void
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        $sql = "CREATE TABLE `{$pivotTable}` ("
             . "`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, "
             . "`source_id` INT UNSIGNED NOT NULL, "
             . "`related_id` INT UNSIGNED NOT NULL, "
             . "UNIQUE KEY `uniq_relation` (`source_id`, `related_id`), "
             . "CONSTRAINT `fk_{$pivotTable}_source` FOREIGN KEY (`source_id`) REFERENCES `{$sourceTable}`(`id`) ON DELETE CASCADE, "
             . "CONSTRAINT `fk_{$pivotTable}_related` FOREIGN KEY (`related_id`) REFERENCES `{$targetTable}`(`id`) ON DELETE CASCADE"
             . ")";

        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a pivot table
     */
    public function dropPivotTable(string $sourceTable, string $fieldSlug): void
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);
        $this->connection->executeStatement("DROP TABLE IF EXISTS `{$pivotTable}`");
    }

    /**
     * Get related entry IDs from a pivot table
     */
    public function getPivotRelations(string $sourceTable, string $fieldSlug, int $sourceId): array
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return [];
        }

        $result = $this->connection->createQueryBuilder()
            ->select('related_id')
            ->from($pivotTable)
            ->where('source_id = :sourceId')
            ->setParameter('sourceId', $sourceId)
            ->executeQuery();

        return array_column($result->fetchAllAssociative(), 'related_id');
    }

    /**
     * Sync pivot table relations (delete old, insert new)
     */
    public function syncPivotRelations(string $sourceTable, string $fieldSlug, int $sourceId, array $relatedIds): void
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return;
        }

        // Remove existing relations
        $this->connection->delete($pivotTable, ['source_id' => $sourceId]);

        // Insert new relations
        foreach ($relatedIds as $relatedId) {
            $this->connection->insert($pivotTable, [
                'source_id' => $sourceId,
                'related_id' => (int) $relatedId,
            ]);
        }
    }

    // ========================================================================
    // CONTENT OPERATIONS (DBAL QueryBuilder)
    // ========================================================================

    /**
     * Insert an entry into a collection table
     */
    public function insertEntry(string $tableName, array $data): int
    {
        $data['created_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');

        $this->connection->insert($tableName, $data);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update an entry in a collection table
     */
    public function updateEntry(string $tableName, int $id, array $data): void
    {
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $this->connection->update($tableName, $data, ['id' => $id]);
    }

    /**
     * Delete an entry from a collection table
     */
    public function deleteEntry(string $tableName, int $id): void
    {
        $this->connection->delete($tableName, ['id' => $id]);
    }

    /**
     * Find a single entry by ID
     */
    public function findEntry(string $tableName, int $id): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $result = $qb->select('*')
            ->from($tableName)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery();

        $row = $result->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List entries with search, sort, and pagination
     */
    public function listEntries(string $tableName, array $options = []): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('*')->from($tableName);

        // Search
        if (!empty($options['search']) && !empty($options['searchFields'])) {
            $orConditions = [];
            foreach ($options['searchFields'] as $i => $field) {
                $orConditions[] = "`{$field}` LIKE :search{$i}";
                $qb->setParameter("search{$i}", '%' . $options['search'] . '%');
            }
            if (!empty($orConditions)) {
                $qb->andWhere('(' . implode(' OR ', $orConditions) . ')');
            }
        }

        // Filters
        if (!empty($options['filters'])) {
            foreach ($options['filters'] as $field => $value) {
                $qb->andWhere("`{$field}` = :filter_{$field}")
                    ->setParameter("filter_{$field}", $value);
            }
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(*) as total');
        $total = (int) $countQb->executeQuery()->fetchOne();

        // Sort
        $sortBy = $options['sort_by'] ?? 'id';
        $sortDir = strtoupper($options['sort_dir'] ?? 'DESC');
        if (!in_array($sortDir, ['ASC', 'DESC'])) {
            $sortDir = 'DESC';
        }
        $qb->orderBy("`{$sortBy}`", $sortDir);

        // Paginate
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, (int) ($options['per_page'] ?? 20));
        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $items = $qb->executeQuery()->fetchAllAssociative();

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Count entries in a collection table
     */
    public function countEntries(string $tableName): int
    {
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        $result = $this->connection->createQueryBuilder()
            ->select('COUNT(*) as total')
            ->from($tableName)
            ->executeQuery();

        return (int) $result->fetchOne();
    }
}
