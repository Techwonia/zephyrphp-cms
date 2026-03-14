<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SeoService;
use ZephyrPHP\Database\Connection as ZephyrConnection;

class SchemaManager
{
    private Connection $connection;

    public function __construct()
    {
        $this->connection = ZephyrConnection::getInstance()->getConnection();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Drop a table by name (generic)
     */
    public function dropTable(string $tableName): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $sm->dropTable($tableName);
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
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

        // Primary key (INT auto-increment or UUID CHAR(36))
        if ($collection->isUuid()) {
            $table->addColumn('id', Types::STRING, ['length' => 36, 'fixed' => true]);
        } else {
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
        }
        $table->setPrimaryKey(['id']);

        // Slug column (auto-managed, like status/published_at)
        if ($collection->hasSlug()) {
            $table->addColumn('slug', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addUniqueIndex(['slug'], 'uniq_' . $collection->getTableName() . '_slug');
        }

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
            $table->addColumn('scheduled_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
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

        // Create FULLTEXT index on searchable text fields
        $this->ensureFulltextIndex($collection->getTableName(), $fields);
    }

    /**
     * Create or update FULLTEXT index on searchable text columns.
     * Groups all text/textarea/richtext/email/url fields that are searchable.
     */
    public function ensureFulltextIndex(string $tableName, array $fields): void
    {
        try {
            $textTypes = ['text', 'textarea', 'richtext', 'email', 'url'];
            $searchableColumns = [];

            foreach ($fields as $field) {
                if ($field instanceof Field && in_array($field->getType(), $textTypes) && $field->isSearchable()) {
                    $searchableColumns[] = $field->getSlug();
                }
            }

            if (empty($searchableColumns)) {
                return;
            }

            $indexName = 'ft_' . $tableName;

            // Drop existing FULLTEXT index if it exists
            try {
                $indexes = $this->connection->createSchemaManager()->listTableIndexes($tableName);
                if (isset($indexes[$indexName])) {
                    $this->connection->executeStatement("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
                }
            } catch (\Exception $e) {
                // Index may not exist
            }

            // Create new FULLTEXT index
            $cols = implode('`, `', $searchableColumns);
            $this->connection->executeStatement("ALTER TABLE `{$tableName}` ADD FULLTEXT INDEX `{$indexName}` (`{$cols}`)");
        } catch (\Exception $e) {
            // FULLTEXT may not be supported (e.g., non-InnoDB MySQL < 5.6)
        }
    }

    /**
     * Add SEO columns to a collection table
     */
    public function addSeoColumns(string $tableName): void
    {
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        foreach (SeoService::SEO_COLUMNS as $colName => $colDef) {
            if (!isset($columns[$colName])) {
                $this->connection->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `{$colName}` {$colDef}");
            }
        }
    }

    /**
     * Remove SEO columns from a collection table
     */
    public function removeSeoColumns(string $tableName): void
    {
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        foreach (array_keys(SeoService::SEO_COLUMNS) as $colName) {
            if (isset($columns[$colName])) {
                $this->connection->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`");
            }
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

        if ($field instanceof Field && $field->isUnique()) {
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
     * Drop a collection table entirely, including all related pivot tables and FK constraints.
     *
     * @param Collection $collection The collection being deleted
     */
    public function dropCollectionTableWithRelations(Collection $collection): void
    {
        $tableName = $collection->getTableName();

        // 1. Drop pivot tables and FK constraints for fields in THIS collection
        foreach ($collection->getFields() as $field) {
            if ($field->getType() !== 'relation') {
                continue;
            }
            $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
            if ($relationType === 'one_to_one') {
                $this->dropForeignKey($tableName, $field->getSlug());
            } else {
                $this->dropPivotTable($tableName, $field->getSlug());
            }
        }

        // 2. Drop pivot tables and FK constraints in OTHER collections that target THIS collection
        $allCollections = Collection::findAll();
        foreach ($allCollections as $otherCollection) {
            if ($otherCollection->getId() === $collection->getId()) {
                continue;
            }
            foreach ($otherCollection->getFields() as $field) {
                if ($field->getType() !== 'relation') {
                    continue;
                }
                $relSlug = $field->getOptions()['relation_collection'] ?? '';
                if ($relSlug !== $collection->getSlug()) {
                    continue;
                }
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType === 'one_to_one') {
                    $this->dropForeignKey($otherCollection->getTableName(), $field->getSlug());
                    // Set the column to NULL for all rows since the target is gone
                    try {
                        $this->connection->executeStatement(
                            "UPDATE `{$otherCollection->getTableName()}` SET `{$field->getSlug()}` = NULL"
                        );
                    } catch (\Exception $e) {
                        // Column may not exist yet
                    }
                } else {
                    $this->dropPivotTable($otherCollection->getTableName(), $field->getSlug());
                }
            }
        }

        // 3. Now safely drop the main table
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
            // Disable FK checks temporarily to ensure clean drop
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $sm->dropTable($tableName);
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Drop a collection table entirely (simple, no relation cleanup)
     */
    public function dropCollectionTable(string $tableName): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $sm->dropTable($tableName);
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
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
            'number' => 'INT',
            'relation' => $this->getRelationColumnType($field),
            'decimal' => 'DECIMAL(10,2)',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'json' => 'JSON',
            default => 'VARCHAR(255)',
        };

        $nullable = $field->isRequired() ? 'NOT NULL' : 'NULL';
        $default = '';

        $isNumericDefault = in_array($field->getType(), ['number', 'decimal', 'boolean']);
        // Relation columns pointing to UUID targets are strings, not numeric
        if ($field->getType() === 'relation' && $mysqlType === 'INT UNSIGNED') {
            $isNumericDefault = true;
        }

        if ($field->getDefaultValue() !== null && $field->getDefaultValue() !== '') {
            $defaultVal = $field->getDefaultValue();
            if ($isNumericDefault) {
                // Ensure numeric defaults are actually numeric to prevent injection
                if (is_numeric($defaultVal)) {
                    $default = "DEFAULT {$defaultVal}";
                }
            } else {
                // Escape single quotes to prevent SQL injection
                $escapedVal = str_replace("'", "''", $defaultVal);
                $default = "DEFAULT '{$escapedVal}'";
            }
        } elseif (!$field->isRequired()) {
            $default = 'DEFAULT NULL';
        }

        return "{$mysqlType} {$nullable} {$default}";
    }

    /**
     * Determine the column type for a relation field based on the target collection's primary key
     */
    private function getRelationColumnType(Field $field): string
    {
        $relSlug = $field->getOptions()['relation_collection'] ?? '';
        if (!empty($relSlug)) {
            $targetCollection = Collection::findOneBy(['slug' => $relSlug]);
            if ($targetCollection && $targetCollection->isUuid()) {
                return 'CHAR(36)';
            }
        }
        return 'INT UNSIGNED';
    }

    // ========================================================================
    // RELATION OPERATIONS
    // ========================================================================

    /**
     * Add a foreign key constraint for one-to-one relations.
     * Ensures the column is nullable (required for ON DELETE SET NULL).
     */
    public function addForeignKey(string $sourceTable, string $columnName, string $targetTable): void
    {
        // Match the target table's primary key type
        $targetType = $this->getPrimaryKeyColumnType($targetTable);
        $this->connection->executeStatement(
            "ALTER TABLE `{$sourceTable}` MODIFY COLUMN `{$columnName}` {$targetType} NULL DEFAULT NULL"
        );

        // Add index on the FK column for performance
        $idxName = "idx_{$sourceTable}_{$columnName}";
        try {
            $this->connection->executeStatement(
                "ALTER TABLE `{$sourceTable}` ADD INDEX `{$idxName}` (`{$columnName}`)"
            );
        } catch (\Exception $e) {
            // Index may already exist
        }

        $fkName = "fk_{$sourceTable}_{$columnName}";
        $sql = "ALTER TABLE `{$sourceTable}` ADD CONSTRAINT `{$fkName}` "
             . "FOREIGN KEY (`{$columnName}`) REFERENCES `{$targetTable}`(`id`) ON DELETE SET NULL ON UPDATE CASCADE";
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
        return "{$sourceTable}_to_{$fieldSlug}";
    }

    /**
     * Detect the primary key column type of a table (INT UNSIGNED or CHAR(36) for UUID)
     */
    public function getPrimaryKeyColumnType(string $tableName): string
    {
        try {
            $sm = $this->connection->createSchemaManager();
            $columns = $sm->listTableColumns($tableName);
            if (isset($columns['id'])) {
                $type = $columns['id']->getType()->getName();
                if ($type === 'string' || $type === 'guid') {
                    return 'CHAR(36)';
                }
            }
        } catch (\Exception $e) {
            // fallback
        }
        return 'INT UNSIGNED';
    }

    /**
     * Create a pivot table for one-to-many or many-to-many relations
     */
    public function createPivotTable(string $sourceTable, string $targetTable, string $fieldSlug): void
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        $srcType = $this->getPrimaryKeyColumnType($sourceTable);
        $tgtType = $this->getPrimaryKeyColumnType($targetTable);

        $sql = "CREATE TABLE `{$pivotTable}` ("
             . "`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, "
             . "`{$sourceTable}_id` {$srcType} NOT NULL, "
             . "`{$targetTable}_id` {$tgtType} NOT NULL, "
             . "INDEX `idx_{$pivotTable}_source` (`{$sourceTable}_id`), "
             . "INDEX `idx_{$pivotTable}_target` (`{$targetTable}_id`), "
             . "UNIQUE KEY `uniq_{$pivotTable}` (`{$sourceTable}_id`, `{$targetTable}_id`), "
             . "CONSTRAINT `fk_{$pivotTable}_source` FOREIGN KEY (`{$sourceTable}_id`) REFERENCES `{$sourceTable}`(`id`) ON DELETE CASCADE ON UPDATE CASCADE, "
             . "CONSTRAINT `fk_{$pivotTable}_target` FOREIGN KEY (`{$targetTable}_id`) REFERENCES `{$targetTable}`(`id`) ON DELETE CASCADE ON UPDATE CASCADE"
             . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci";

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
    public function getPivotRelations(string $sourceTable, string $fieldSlug, string $targetTable, int|string $sourceId): array
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return [];
        }

        $srcCol = "{$sourceTable}_id";
        $tgtCol = "{$targetTable}_id";

        $result = $this->connection->createQueryBuilder()
            ->select("`{$tgtCol}`")
            ->from($pivotTable)
            ->where("`{$srcCol}` = :sourceId")
            ->setParameter('sourceId', $sourceId)
            ->executeQuery();

        return array_column($result->fetchAllAssociative(), $tgtCol);
    }

    /**
     * Sync pivot table relations (delete old, insert new)
     */
    public function syncPivotRelations(string $sourceTable, string $fieldSlug, string $targetTable, int|string $sourceId, array $relatedIds): void
    {
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return;
        }

        $srcCol = "{$sourceTable}_id";
        $tgtCol = "{$targetTable}_id";

        // Remove existing relations
        $this->connection->delete($pivotTable, [$srcCol => $sourceId]);

        // Insert new relations
        foreach ($relatedIds as $relatedId) {
            $this->connection->insert($pivotTable, [
                $srcCol => $sourceId,
                $tgtCol => (int) $relatedId,
            ]);
        }
    }

    // ========================================================================
    // CONTENT OPERATIONS (DBAL QueryBuilder)
    // ========================================================================

    /**
     * Generate a UUID v4
     */
    public function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Insert an entry into a collection table
     */
    public function insertEntry(string $tableName, array $data, bool $useUuid = false): int|string
    {
        $data['created_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');

        if ($useUuid) {
            $data['id'] = $this->generateUuid();
            $this->connection->insert($tableName, $data);
            return $data['id'];
        }

        $this->connection->insert($tableName, $data);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update an entry in a collection table
     */
    public function updateEntry(string $tableName, int|string $id, array $data): void
    {
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $this->connection->update($tableName, $data, ['id' => $id]);
    }

    /**
     * Delete an entry from a collection table
     */
    public function deleteEntry(string $tableName, int|string $id): void
    {
        $this->connection->delete($tableName, ['id' => $id]);
    }

    /**
     * Find a single entry by ID
     */
    public function findEntry(string $tableName, int|string $id): ?array
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
        // Get actual column names from the table to whitelist against
        $validColumns = $this->getTableColumns($tableName);

        $qb = $this->connection->createQueryBuilder();
        $qb->select('*')->from($tableName);

        // Search — try FULLTEXT (MATCH AGAINST) first, fallback to LIKE
        if (!empty($options['search']) && !empty($options['searchFields'])) {
            $searchTerm = $options['search'];
            $validSearchFields = array_filter($options['searchFields'], fn($f) => in_array($f, $validColumns, true));

            $usedFulltext = false;
            if (!empty($validSearchFields)) {
                // Check if a FULLTEXT index exists for these columns
                $ftIndexName = 'ft_' . $tableName;
                try {
                    $ftColumns = implode('`, `', $validSearchFields);
                    // Try MATCH AGAINST — it will fail if no FULLTEXT index, caught below
                    $matchExpr = "MATCH(`{$ftColumns}`) AGAINST(:ft_search IN BOOLEAN MODE)";
                    // Verify the index exists by checking table indexes
                    $indexes = $this->connection->createSchemaManager()->listTableIndexes($tableName);
                    foreach ($indexes as $index) {
                        if (str_starts_with($index->getName(), 'ft_')) {
                            $indexCols = $index->getColumns();
                            // Use FULLTEXT if index covers at least some search fields
                            $covered = array_intersect($validSearchFields, $indexCols);
                            if (!empty($covered)) {
                                $ftCols = implode('`, `', $covered);
                                $qb->andWhere("MATCH(`{$ftCols}`) AGAINST(:ft_search IN BOOLEAN MODE)");
                                // Append wildcard for partial matching in boolean mode
                                $qb->setParameter('ft_search', '*' . $searchTerm . '*');
                                $usedFulltext = true;
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // FULLTEXT not available, fall through to LIKE
                }
            }

            // Fallback to LIKE search
            if (!$usedFulltext && !empty($validSearchFields)) {
                $orConditions = [];
                $paramIndex = 0;
                foreach ($validSearchFields as $field) {
                    $orConditions[] = "`{$field}` LIKE :search{$paramIndex}";
                    $qb->setParameter("search{$paramIndex}", '%' . $searchTerm . '%');
                    $paramIndex++;
                }
                if (!empty($orConditions)) {
                    $qb->andWhere('(' . implode(' OR ', $orConditions) . ')');
                }
            }
        }

        // Filters — only use columns that actually exist in the table
        if (!empty($options['filters'])) {
            foreach ($options['filters'] as $field => $value) {
                if (!in_array($field, $validColumns, true)) {
                    continue; // Skip invalid column names
                }
                $qb->andWhere("`{$field}` = :filter_{$field}")
                    ->setParameter("filter_{$field}", $value);
            }
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(*) as total');
        $total = (int) $countQb->executeQuery()->fetchOne();

        // Sort — whitelist sort_by against actual columns to prevent SQL injection
        $sortBy = $options['sort_by'] ?? 'id';
        if (!in_array($sortBy, $validColumns, true)) {
            $sortBy = 'id';
        }
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
     * Get the list of column names for a table (cached per request).
     */
    private array $columnCache = [];

    private function getTableColumns(string $tableName): array
    {
        if (isset($this->columnCache[$tableName])) {
            return $this->columnCache[$tableName];
        }

        try {
            $sm = $this->connection->createSchemaManager();
            $columns = $sm->listTableColumns($tableName);
            $this->columnCache[$tableName] = array_map(fn($col) => $col->getName(), $columns);
        } catch (\Exception $e) {
            $this->columnCache[$tableName] = ['id'];
        }

        return $this->columnCache[$tableName];
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
