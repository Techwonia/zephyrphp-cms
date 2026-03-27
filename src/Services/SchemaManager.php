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

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var array<string, bool> Per-request cache for tableExists() */
    private array $tableExistsCache = [];

    /** @var array<string, array> Per-request cache for listTableColumns() */
    private array $columnsCache = [];

    /**
     * SQL reserved words that cannot be used as identifiers.
     */
    private const RESERVED_WORDS = [
        'add', 'all', 'alter', 'and', 'as', 'asc', 'between', 'by', 'case', 'check',
        'column', 'constraint', 'create', 'cross', 'current', 'database', 'default',
        'delete', 'desc', 'distinct', 'drop', 'else', 'exists', 'false', 'foreign',
        'from', 'full', 'grant', 'group', 'having', 'in', 'index', 'inner', 'insert',
        'into', 'is', 'join', 'key', 'left', 'like', 'limit', 'not', 'null', 'on',
        'or', 'order', 'outer', 'primary', 'references', 'right', 'select', 'set',
        'table', 'then', 'to', 'true', 'union', 'unique', 'update', 'using', 'values',
        'when', 'where', 'with',
    ];

    public function __construct()
    {
        $this->connection = ZephyrConnection::getInstance()->getConnection();
    }

    /**
     * Get or create the singleton instance.
     * Reuses the same connection and caches across the request.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Invalidate the tableExists/columns cache for a specific table.
     */
    public function invalidateTableCache(string $tableName): void
    {
        unset($this->tableExistsCache[$tableName], $this->columnsCache[$tableName]);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    // ========================================================================
    // IDENTIFIER VALIDATION (SQL injection prevention)
    // ========================================================================

    /**
     * Validate and sanitize a SQL identifier (table name, column name, index name).
     * Only allows alphanumeric characters and underscores. Max 64 characters (MySQL limit).
     *
     * @throws \InvalidArgumentException If the identifier is invalid
     */
    public static function validateIdentifier(string $identifier, string $context = 'identifier'): string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new \InvalidArgumentException("SQL {$context} cannot be empty.");
        }

        if (strlen($identifier) > 64) {
            throw new \InvalidArgumentException("SQL {$context} exceeds maximum length of 64 characters: '{$identifier}'");
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                "Invalid SQL {$context}: '{$identifier}'. Only letters, digits, and underscores are allowed, and it must start with a letter or underscore."
            );
        }

        if (in_array(strtolower($identifier), self::RESERVED_WORDS, true)) {
            throw new \InvalidArgumentException("SQL {$context} '{$identifier}' is a reserved word.");
        }

        return $identifier;
    }

    /**
     * Validate a table name. Applies validateIdentifier rules.
     */
    private function safeTable(string $tableName): string
    {
        return self::validateIdentifier($tableName, 'table name');
    }

    /**
     * Validate a column name. Applies validateIdentifier rules.
     */
    private function safeColumn(string $columnName): string
    {
        return self::validateIdentifier($columnName, 'column name');
    }

    /**
     * Validate an index name. Applies validateIdentifier rules.
     */
    private function safeIndex(string $indexName): string
    {
        return self::validateIdentifier($indexName, 'index name');
    }

    /**
     * Get the table name for a collection slug. Validates the result.
     */
    public function getTableName(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_]/', '', $slug);
        $prefix = \ZephyrPHP\Config\Config::get('cms.content_prefix', 'app_');
        $tableName = $prefix . $slug;
        return $this->safeTable($tableName);
    }

    /**
     * Drop a table by name (generic)
     */
    public function dropTable(string $tableName): void
    {
        $tableName = $this->safeTable($tableName);
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
            'text', 'email', 'slug', 'select', 'color', 'password' => Types::STRING,
            'textarea', 'markdown' => Types::TEXT,
            'richtext' => Types::TEXT,
            'number' => Types::INTEGER,
            'decimal' => Types::DECIMAL,
            'boolean' => Types::BOOLEAN,
            'date' => Types::DATE_MUTABLE,
            'datetime' => Types::DATETIME_MUTABLE,
            'image', 'file', 'media' => Types::INTEGER,
            'url' => Types::STRING,
            'tags' => Types::TEXT,
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
            'image', 'file', 'media' => $options,
            'url' => array_merge($options, ['length' => 500]),
            'text', 'email', 'slug', 'select' => array_merge($options, ['length' => 255]),
            'password' => array_merge($options, ['length' => 255]),
            'color' => array_merge($options, ['length' => 7]),
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

        // Hierarchy (parent-child)
        if ($collection->hasHierarchy()) {
            if ($collection->isUuid()) {
                $table->addColumn('parent_id', Types::STRING, ['length' => 36, 'fixed' => true, 'notnull' => false]);
            } else {
                $table->addColumn('parent_id', Types::INTEGER, ['unsigned' => true, 'notnull' => false]);
            }
            $table->addIndex(['parent_id'], 'idx_' . $collection->getTableName() . '_parent');
        }

        // Audit columns
        $table->addColumn('created_by', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

        // Soft delete column
        $table->addColumn('deleted_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

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
            $tableName = $this->safeTable($tableName);
            $textTypes = ['text', 'textarea', 'richtext', 'email', 'url'];
            $searchableColumns = [];

            foreach ($fields as $field) {
                if ($field instanceof Field && in_array($field->getType(), $textTypes) && $field->isSearchable()) {
                    $searchableColumns[] = $this->safeColumn($field->getSlug());
                }
            }

            if (empty($searchableColumns)) {
                return;
            }

            $indexName = $this->safeIndex('ft_' . $tableName);

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
        $tableName = $this->safeTable($tableName);
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        foreach (SeoService::SEO_COLUMNS as $colName => $colDef) {
            $colName = $this->safeColumn($colName);
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
        $tableName = $this->safeTable($tableName);
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        foreach (array_keys(SeoService::SEO_COLUMNS) as $colName) {
            $colName = $this->safeColumn($colName);
            if (isset($columns[$colName])) {
                $this->connection->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`");
            }
        }
    }

    /**
     * Add hierarchy (parent_id) column to a collection table.
     */
    public function addHierarchyColumn(string $tableName, bool $isUuid = false): void
    {
        $tableName = $this->safeTable($tableName);
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        if (!isset($columns['parent_id'])) {
            $colType = $isUuid ? 'CHAR(36)' : 'INT UNSIGNED';
            $this->connection->executeStatement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `parent_id` {$colType} NULL DEFAULT NULL"
            );
            $indexName = $this->safeIndex("idx_{$tableName}_parent");
            try {
                $this->connection->executeStatement(
                    "ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` (`parent_id`)"
                );
            } catch (\Exception $e) {
                // Index may already exist
            }
        }
    }

    /**
     * Remove hierarchy (parent_id) column from a collection table.
     */
    public function removeHierarchyColumn(string $tableName): void
    {
        $tableName = $this->safeTable($tableName);
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        if (isset($columns['parent_id'])) {
            $indexName = $this->safeIndex("idx_{$tableName}_parent");
            try {
                $this->connection->executeStatement("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
            } catch (\Exception $e) {
                // Index may not exist
            }
            $this->connection->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `parent_id`");
        }
    }

    /**
     * Add a column to a collection table
     */
    public function addColumn(string $tableName, Field $field): void
    {
        $tableName = $this->safeTable($tableName);
        $colName = $this->safeColumn($field->getSlug());
        $columnDef = $this->buildColumnDefinition($field);
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$colName}` {$columnDef}";
        $this->connection->executeStatement($sql);

        if ($field instanceof Field && $field->isUnique()) {
            $indexName = $this->safeIndex("uniq_{$tableName}_{$colName}");
            $sql = "ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `{$indexName}` (`{$colName}`)";
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * Modify a column in a collection table
     */
    public function modifyColumn(string $tableName, Field $field, ?string $oldSlug = null): void
    {
        $tableName = $this->safeTable($tableName);
        $colName = $this->safeColumn($field->getSlug());
        $columnDef = $this->buildColumnDefinition($field);

        if ($oldSlug && $oldSlug !== $field->getSlug()) {
            $oldCol = $this->safeColumn($oldSlug);
            $sql = "ALTER TABLE `{$tableName}` CHANGE `{$oldCol}` `{$colName}` {$columnDef}";
        } else {
            $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$colName}` {$columnDef}";
        }

        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a column from a collection table
     */
    public function dropColumn(string $tableName, string $columnName): void
    {
        $tableName = $this->safeTable($tableName);
        $columnName = $this->safeColumn($columnName);
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
        $tableName = $this->safeTable($collection->getTableName());

        // 1. Drop pivot tables and FK constraints for fields in THIS collection
        foreach ($collection->getFields() as $field) {
            $type = $field->getType();
            $options = $field->getOptions() ?? [];

            if ($type === 'relation') {
                $relationType = $options['relation_type'] ?? 'one_to_one';
                if ($relationType === 'one_to_one') {
                    $this->dropForeignKey($tableName, $field->getSlug());
                } else {
                    $this->dropPivotTable($tableName, $field->getSlug());
                }
            } elseif (in_array($type, ['image', 'file'])) {
                if (!empty($options['multiple'])) {
                    $this->dropPivotTable($tableName, $field->getSlug());
                } else {
                    $this->dropForeignKey($tableName, $field->getSlug());
                }
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
                $otherTable = $this->safeTable($otherCollection->getTableName());
                $otherCol = $this->safeColumn($field->getSlug());
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType === 'one_to_one') {
                    $this->dropForeignKey($otherTable, $otherCol);
                    // Set the column to NULL for all rows since the target is gone
                    try {
                        $this->connection->executeStatement(
                            "UPDATE `{$otherTable}` SET `{$otherCol}` = NULL"
                        );
                    } catch (\Exception $e) {
                        // Column may not exist yet
                    }
                } else {
                    $this->dropPivotTable($otherTable, $field->getSlug());
                }
            }
        }

        // 3. Now safely drop the main table
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
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
        $tableName = $this->safeTable($tableName);
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
        $tableName = $this->safeTable($tableName);
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }
        $sm = $this->connection->createSchemaManager();
        $result = $sm->tablesExist([$tableName]);
        $this->tableExistsCache[$tableName] = $result;
        return $result;
    }

    /**
     * Build MySQL column definition string
     */
    private function buildColumnDefinition(Field $field): string
    {
        $mysqlType = match ($field->getType()) {
            'text', 'email', 'slug', 'select' => 'VARCHAR(255)',
            'image', 'file' => 'INT UNSIGNED',
            'url' => 'VARCHAR(500)',
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

        $isNumericDefault = in_array($field->getType(), ['number', 'decimal', 'boolean', 'image', 'file']);
        // Relation columns pointing to UUID targets are strings, not numeric
        if ($field->getType() === 'relation' && $mysqlType === 'INT UNSIGNED') {
            $isNumericDefault = true;
        }

        if ($field->getDefaultValue() !== null && $field->getDefaultValue() !== '') {
            $defaultVal = $field->getDefaultValue();
            if ($isNumericDefault) {
                // Strict numeric validation — cast to prevent any injection
                if (is_numeric($defaultVal)) {
                    $default = "DEFAULT " . (str_contains($defaultVal, '.') ? (float) $defaultVal : (int) $defaultVal);
                }
            } else {
                // Use PDO quoting for proper escaping instead of manual str_replace
                $quoted = $this->connection->quote($defaultVal);
                $default = "DEFAULT {$quoted}";
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
        $sourceTable = $this->safeTable($sourceTable);
        $columnName = $this->safeColumn($columnName);
        $targetTable = $this->safeTable($targetTable);

        // Match the target table's primary key type
        $targetType = $this->getPrimaryKeyColumnType($targetTable);
        $this->connection->executeStatement(
            "ALTER TABLE `{$sourceTable}` MODIFY COLUMN `{$columnName}` {$targetType} NULL DEFAULT NULL"
        );

        // Add index on the FK column for performance
        $idxName = $this->safeIndex("idx_{$sourceTable}_{$columnName}");
        try {
            $this->connection->executeStatement(
                "ALTER TABLE `{$sourceTable}` ADD INDEX `{$idxName}` (`{$columnName}`)"
            );
        } catch (\Exception $e) {
            // Index may already exist
        }

        $fkName = $this->safeIndex("fk_{$sourceTable}_{$columnName}");
        $sql = "ALTER TABLE `{$sourceTable}` ADD CONSTRAINT `{$fkName}` "
             . "FOREIGN KEY (`{$columnName}`) REFERENCES `{$targetTable}`(`id`) ON DELETE SET NULL ON UPDATE CASCADE";
        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a foreign key constraint
     */
    public function dropForeignKey(string $sourceTable, string $columnName): void
    {
        $sourceTable = $this->safeTable($sourceTable);
        $columnName = $this->safeColumn($columnName);
        $fkName = $this->safeIndex("fk_{$sourceTable}_{$columnName}");
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
        $sourceTable = $this->safeTable($sourceTable);
        $fieldSlug = $this->safeColumn($fieldSlug);
        return "{$sourceTable}_to_{$fieldSlug}";
    }

    /**
     * Detect the primary key column type of a table (INT UNSIGNED or CHAR(36) for UUID)
     */
    public function getPrimaryKeyColumnType(string $tableName): string
    {
        $tableName = $this->safeTable($tableName);
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
        $sourceTable = $this->safeTable($sourceTable);
        $targetTable = $this->safeTable($targetTable);
        $fieldSlug = $this->safeColumn($fieldSlug);
        $pivotTable = $this->safeTable("{$sourceTable}_to_{$fieldSlug}");

        $srcType = $this->getPrimaryKeyColumnType($sourceTable);
        $tgtType = $this->getPrimaryKeyColumnType($targetTable);

        $srcIdCol = $this->safeColumn("{$sourceTable}_id");
        $tgtIdCol = $this->safeColumn("{$targetTable}_id");
        $idxSource = $this->safeIndex("idx_{$pivotTable}_source");
        $idxTarget = $this->safeIndex("idx_{$pivotTable}_target");
        $uniqKey = $this->safeIndex("uniq_{$pivotTable}");
        $fkSource = $this->safeIndex("fk_{$pivotTable}_source");
        $fkTarget = $this->safeIndex("fk_{$pivotTable}_target");

        $sql = "CREATE TABLE `{$pivotTable}` ("
             . "`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, "
             . "`{$srcIdCol}` {$srcType} NOT NULL, "
             . "`{$tgtIdCol}` {$tgtType} NOT NULL, "
             . "INDEX `{$idxSource}` (`{$srcIdCol}`), "
             . "INDEX `{$idxTarget}` (`{$tgtIdCol}`), "
             . "UNIQUE KEY `{$uniqKey}` (`{$srcIdCol}`, `{$tgtIdCol}`), "
             . "CONSTRAINT `{$fkSource}` FOREIGN KEY (`{$srcIdCol}`) REFERENCES `{$sourceTable}`(`id`) ON DELETE CASCADE ON UPDATE CASCADE, "
             . "CONSTRAINT `{$fkTarget}` FOREIGN KEY (`{$tgtIdCol}`) REFERENCES `{$targetTable}`(`id`) ON DELETE CASCADE ON UPDATE CASCADE"
             . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci";

        $this->connection->executeStatement($sql);
    }

    /**
     * Drop a pivot table
     */
    public function dropPivotTable(string $sourceTable, string $fieldSlug): void
    {
        $pivotTable = $this->safeTable($this->getPivotTableName($sourceTable, $fieldSlug));
        $this->connection->executeStatement("DROP TABLE IF EXISTS `{$pivotTable}`");
    }

    /**
     * Get related entry IDs from a pivot table
     */
    public function getPivotRelations(string $sourceTable, string $fieldSlug, string $targetTable, int|string $sourceId): array
    {
        $sourceTable = $this->safeTable($sourceTable);
        $targetTable = $this->safeTable($targetTable);
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return [];
        }

        $srcCol = $this->safeColumn("{$sourceTable}_id");
        $tgtCol = $this->safeColumn("{$targetTable}_id");

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
        $sourceTable = $this->safeTable($sourceTable);
        $targetTable = $this->safeTable($targetTable);
        $pivotTable = $this->getPivotTableName($sourceTable, $fieldSlug);

        if (!$this->tableExists($pivotTable)) {
            return;
        }

        $srcCol = $this->safeColumn("{$sourceTable}_id");
        $tgtCol = $this->safeColumn("{$targetTable}_id");

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
        $tableName = $this->safeTable($tableName);
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
        $tableName = $this->safeTable($tableName);
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $this->connection->update($tableName, $data, ['id' => $id]);
    }

    /**
     * Delete an entry from a collection table (hard delete).
     */
    public function deleteEntry(string $tableName, int|string $id): void
    {
        $tableName = $this->safeTable($tableName);
        $this->connection->delete($tableName, ['id' => $id]);
    }

    /**
     * Soft-delete an entry by setting deleted_at timestamp.
     */
    public function softDeleteEntry(string $tableName, int|string $id): void
    {
        $tableName = $this->safeTable($tableName);
        $this->connection->executeStatement(
            "UPDATE `{$tableName}` SET `deleted_at` = :now WHERE `id` = :id",
            ['now' => (new \DateTime())->format('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Restore a soft-deleted entry by clearing deleted_at.
     */
    public function restoreEntry(string $tableName, int|string $id): void
    {
        $tableName = $this->safeTable($tableName);
        $this->connection->executeStatement(
            "UPDATE `{$tableName}` SET `deleted_at` = NULL WHERE `id` = :id",
            ['id' => $id]
        );
    }

    /**
     * Permanently delete an entry (hard delete). Alias of deleteEntry.
     */
    public function forceDeleteEntry(string $tableName, int|string $id): void
    {
        $this->deleteEntry($tableName, $id);
    }

    /**
     * Add `deleted_at` column to an existing collection table if it does not exist.
     */
    public function ensureDeletedAtColumn(string $tableName): void
    {
        $tableName = $this->safeTable($tableName);
        $sm = $this->connection->createSchemaManager();
        $columns = $sm->listTableColumns($tableName);

        if (!isset($columns['deleted_at'])) {
            $this->connection->executeStatement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL"
            );
        }
    }

    /**
     * Find a single entry by ID
     */
    public function findEntry(string $tableName, int|string $id): ?array
    {
        $tableName = $this->safeTable($tableName);
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
        $tableName = $this->safeTable($tableName);
        // Get actual column names from the table to whitelist against
        $validColumns = $this->getTableColumns($tableName);

        $qb = $this->connection->createQueryBuilder();

        // Select specific columns or default to *
        if (!empty($options['select'])) {
            $selectExprs = [];
            foreach ($options['select'] as $col) {
                // Allow safe aggregate expressions: FUNC(col), FUNC(*), col as alias
                if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX|DATE|YEAR|MONTH|DAY)\(([a-zA-Z0-9_*]+)\)(\s+as\s+[a-zA-Z0-9_]+)?$/i', $col)) {
                    $selectExprs[] = $col; // safe aggregate expression
                } elseif (in_array($col, $validColumns, true)) {
                    $selectExprs[] = "`{$col}`";
                }
            }
            $qb->select(...($selectExprs ?: ['*']));
        } else {
            $qb->select('*');
        }

        // DISTINCT
        if (!empty($options['distinct'])) {
            $qb->distinct();
        }

        $qb->from($tableName);

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
                    continue;
                }
                $qb->andWhere("`{$field}` = :filter_{$field}")
                    ->setParameter("filter_{$field}", $value);
            }
        }

        // WHERE IN filters
        if (!empty($options['filters_in'])) {
            foreach ($options['filters_in'] as $field => $values) {
                if (!in_array($field, $validColumns, true) || empty($values)) {
                    continue;
                }
                $qb->andWhere("`{$field}` IN (:fin_{$field})")
                    ->setParameter("fin_{$field}", $values, \Doctrine\DBAL\ArrayParameterType::STRING);
            }
        }

        // WHERE NOT IN filters
        if (!empty($options['filters_not_in'])) {
            foreach ($options['filters_not_in'] as $field => $values) {
                if (!in_array($field, $validColumns, true) || empty($values)) {
                    continue;
                }
                $qb->andWhere("`{$field}` NOT IN (:fnin_{$field})")
                    ->setParameter("fnin_{$field}", $values, \Doctrine\DBAL\ArrayParameterType::STRING);
            }
        }

        // WHERE NOT filters
        if (!empty($options['filters_not'])) {
            foreach ($options['filters_not'] as $field => $value) {
                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                $qb->andWhere("`{$field}` != :fnot_{$field}")
                    ->setParameter("fnot_{$field}", $value);
            }
        }

        // WHERE BETWEEN filters
        if (!empty($options['filters_between'])) {
            foreach ($options['filters_between'] as $field => $range) {
                if (!in_array($field, $validColumns, true) || !is_array($range) || count($range) !== 2) {
                    continue;
                }
                $qb->andWhere("`{$field}` BETWEEN :fbtw_{$field}_min AND :fbtw_{$field}_max")
                    ->setParameter("fbtw_{$field}_min", $range[0])
                    ->setParameter("fbtw_{$field}_max", $range[1]);
            }
        }

        // WHERE NOT BETWEEN filters
        if (!empty($options['filters_not_between'])) {
            foreach ($options['filters_not_between'] as $field => $range) {
                if (!in_array($field, $validColumns, true) || !is_array($range) || count($range) !== 2) {
                    continue;
                }
                $qb->andWhere("`{$field}` NOT BETWEEN :fnbtw_{$field}_min AND :fnbtw_{$field}_max")
                    ->setParameter("fnbtw_{$field}_min", $range[0])
                    ->setParameter("fnbtw_{$field}_max", $range[1]);
            }
        }

        // WHERE NULL filters
        $softDeleteAuto = !empty($options['_soft_delete_auto']);
        if (!empty($options['filters_null'])) {
            foreach ($options['filters_null'] as $field) {
                if (!in_array($field, $validColumns, true)) {
                    // If this is the auto-added deleted_at and column doesn't exist, skip silently
                    continue;
                }
                $qb->andWhere("`{$field}` IS NULL");
            }
        }

        // WHERE NOT NULL filters
        if (!empty($options['filters_not_null'])) {
            foreach ($options['filters_not_null'] as $field) {
                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                $qb->andWhere("`{$field}` IS NOT NULL");
            }
        }

        // WHERE LIKE filters
        if (!empty($options['filters_like'])) {
            foreach ($options['filters_like'] as $field => $pattern) {
                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                $qb->andWhere("`{$field}` LIKE :flike_{$field}")
                    ->setParameter("flike_{$field}", $pattern);
            }
        }

        // WHERE NOT LIKE filters
        if (!empty($options['filters_not_like'])) {
            foreach ($options['filters_not_like'] as $field => $pattern) {
                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                $qb->andWhere("`{$field}` NOT LIKE :fnlike_{$field}")
                    ->setParameter("fnlike_{$field}", $pattern);
            }
        }

        // DATE function filters (whereDate, whereYear, whereMonth, whereDay)
        if (!empty($options['filters_date'])) {
            $dateIdx = 0;
            foreach ($options['filters_date'] as $dateCond) {
                $field = $dateCond['field'] ?? '';
                $func = $dateCond['func'] ?? '';
                $value = $dateCond['value'] ?? '';

                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                if (!in_array($func, ['DATE', 'YEAR', 'MONTH', 'DAY'], true)) {
                    continue;
                }

                $p = "fdate_{$dateIdx}";
                $qb->andWhere("{$func}(`{$field}`) = :{$p}")
                    ->setParameter($p, $value);
                $dateIdx++;
            }
        }

        // Raw WHERE conditions
        if (!empty($options['raw_conditions'])) {
            foreach ($options['raw_conditions'] as $raw) {
                $expr = $raw['expr'] ?? '';
                $bindings = $raw['bindings'] ?? [];

                if (empty($expr)) {
                    continue;
                }

                $qb->andWhere($expr);
                foreach ($bindings as $paramName => $paramValue) {
                    $qb->setParameter($paramName, $paramValue);
                }
            }
        }

        // Comparison filters (>, <, >=, <=)
        if (!empty($options['filters_compare'])) {
            $cmpIdx = 0;
            foreach ($options['filters_compare'] as $cmp) {
                $field = $cmp['field'] ?? '';
                $operator = $cmp['operator'] ?? '=';
                $value = $cmp['value'] ?? '';

                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                if (!in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
                    continue;
                }

                $p = "fcmp_{$cmpIdx}";
                $qb->andWhere("`{$field}` {$operator} :{$p}")
                    ->setParameter($p, $value);
                $cmpIdx++;
            }
        }

        // Column comparison filters (col1 > col2)
        if (!empty($options['filters_column'])) {
            foreach ($options['filters_column'] as $colCmp) {
                $col1 = $colCmp['col1'] ?? '';
                $operator = $colCmp['operator'] ?? '=';
                $col2 = $colCmp['col2'] ?? '';

                if (!in_array($col1, $validColumns, true) || !in_array($col2, $validColumns, true)) {
                    continue;
                }
                if (!in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
                    continue;
                }

                $qb->andWhere("`{$col1}` {$operator} `{$col2}`");
            }
        }

        // JSON contains filters
        if (!empty($options['filters_json_contains'])) {
            $jsonIdx = 0;
            foreach ($options['filters_json_contains'] as $jsonCond) {
                $field = $jsonCond['field'] ?? '';
                $value = $jsonCond['value'] ?? '';

                if (!in_array($field, $validColumns, true)) {
                    continue;
                }

                $p = "fjson_{$jsonIdx}";
                $jsonValue = json_encode($value);
                $qb->andWhere("JSON_CONTAINS(`{$field}`, :{$p})")
                    ->setParameter($p, $jsonValue);
                $jsonIdx++;
            }
        }

        // Compound AND/OR condition groups
        if (!empty($options['condition_groups'])) {
            $this->applyConditionGroups($qb, $options['condition_groups'], $validColumns);
        }

        // GROUP BY
        if (!empty($options['group_by'])) {
            $groupCols = [];
            foreach ($options['group_by'] as $col) {
                if (in_array($col, $validColumns, true)) {
                    $groupCols[] = "`{$col}`";
                }
            }
            if (!empty($groupCols)) {
                $qb->groupBy(...$groupCols);
            }
        }

        // HAVING (requires GROUP BY)
        if (!empty($options['having']) && !empty($options['group_by'])) {
            $havingIdx = 0;
            $allowedAggFuncs = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
            foreach ($options['having'] as $hCond) {
                $expr = $hCond['expr'] ?? '';
                $operator = $hCond['operator'] ?? '=';
                $value = $hCond['value'] ?? '';

                if (!in_array($operator, ['=', '!=', '>', '<', '>=', '<='], true)) {
                    continue;
                }

                // Validate expression: must be FUNC(col), FUNC(*), or a whitelisted column
                if (!preg_match('/^([A-Z]+)\(([a-zA-Z0-9_*]+)\)$/', $expr, $m)) {
                    // Not an aggregate function — check if it's a plain column name
                    if (!in_array($expr, $validColumns, true)) {
                        continue; // reject unrecognized expressions
                    }
                } else {
                    // Validate aggregate function name
                    if (!in_array($m[1], $allowedAggFuncs, true)) {
                        continue;
                    }
                    // Validate inner column (allow * for COUNT(*))
                    if ($m[2] !== '*' && !in_array($m[2], $validColumns, true)) {
                        continue;
                    }
                }

                $p = "having_{$havingIdx}";
                $qb->andHaving("{$expr} {$operator} :{$p}")
                    ->setParameter($p, $value);
                $havingIdx++;
            }
        }

        // Count total before pagination
        $countQb = clone $qb;
        $countQb->select('COUNT(*) as total');
        $total = (int) $countQb->executeQuery()->fetchOne();

        // Sort — whitelist sort_by against actual columns to prevent SQL injection
        $sortBy = $options['sort_by'] ?? 'id';
        if ($sortBy === 'RAND()') {
            $qb->orderBy('RAND()');
        } else {
            if (!in_array($sortBy, $validColumns, true)) {
                $sortBy = 'id';
            }
            $sortDir = strtoupper($options['sort_dir'] ?? 'DESC');
            if (!in_array($sortDir, ['ASC', 'DESC'])) {
                $sortDir = 'DESC';
            }
            $qb->orderBy("`{$sortBy}`", $sortDir);

            // Additional sort columns
            if (!empty($options['additional_sorts'])) {
                foreach ($options['additional_sorts'] as $addSort) {
                    $addField = $addSort['field'] ?? '';
                    $addDir = strtoupper($addSort['dir'] ?? 'ASC');
                    if (in_array($addField, $validColumns, true) && in_array($addDir, ['ASC', 'DESC'], true)) {
                        $qb->addOrderBy("`{$addField}`", $addDir);
                    }
                }
            }
        }

        // Paginate
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, (int) ($options['per_page'] ?? 20));
        $firstResult = ($page - 1) * $perPage;

        // Manual offset override (from EntryQuery::offset())
        if (isset($options['offset']) && is_int($options['offset']) && $options['offset'] >= 0) {
            $firstResult = $options['offset'];
        }

        $qb->setFirstResult($firstResult)
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
     * Apply compound AND/OR condition groups to a query builder.
     *
     * Each group is either:
     *   - A simple condition: {boolean, type, field, value/values/min/max}
     *   - A nested group:     {boolean, type: 'nested', conditions: [...]}
     *
     * Simple OR conditions use $qb->orWhere().
     * Nested groups produce parenthesized sub-expressions.
     *
     * SQL precedence note:
     *   ->where('a', 1)->where('b', 2)->orWhere('c', 3)
     *   Produces: WHERE a = 1 AND b = 2 OR c = 3
     *   SQL reads: (a = 1 AND b = 2) OR (c = 3)
     *
     *   For explicit grouping, use closures:
     *   ->where('a', 1)->where(fn($q) => $q->where('b', 2)->orWhere('c', 3))
     *   Produces: WHERE a = 1 AND (b = 2 OR c = 3)
     */
    private function applyConditionGroups($qb, array $groups, array $validColumns): void
    {
        static $paramCounter = 0;

        foreach ($groups as $group) {
            $boolean = strtoupper($group['boolean'] ?? 'AND');
            if (!in_array($boolean, ['AND', 'OR'], true)) {
                $boolean = 'AND';
            }

            if (($group['type'] ?? '') === 'nested' && !empty($group['conditions'])) {
                // Build a parenthesized sub-expression from child conditions
                $subParts = [];
                foreach ($group['conditions'] as $cond) {
                    $expr = $this->buildConditionExpr($qb, $cond, $validColumns, $paramCounter);
                    if ($expr === null) {
                        continue;
                    }

                    $condBoolean = strtoupper($cond['boolean'] ?? 'AND');
                    if (empty($subParts)) {
                        $subParts[] = $expr;
                    } else {
                        $subParts[] = ($condBoolean === 'OR' ? ' OR ' : ' AND ') . $expr;
                    }
                }

                if (!empty($subParts)) {
                    $subExpr = '(' . implode('', $subParts) . ')';
                    if ($boolean === 'OR') {
                        $qb->orWhere($subExpr);
                    } else {
                        $qb->andWhere($subExpr);
                    }
                }
            } else {
                // Simple top-level OR/AND condition
                $expr = $this->buildConditionExpr($qb, $group, $validColumns, $paramCounter);
                if ($expr === null) {
                    continue;
                }

                if ($boolean === 'OR') {
                    $qb->orWhere($expr);
                } else {
                    $qb->andWhere($expr);
                }
            }
        }
    }

    /**
     * Build a single condition SQL expression and bind its parameters.
     *
     * Returns null if the field is not in the valid columns whitelist.
     */
    private function buildConditionExpr($qb, array $cond, array $validColumns, int &$paramCounter): ?string
    {
        $type = $cond['type'] ?? '';
        $field = $cond['field'] ?? '';

        // Validate field exists in table (SQL injection prevention)
        if (!empty($field) && !in_array($field, $validColumns, true)) {
            return null;
        }

        $paramCounter++;
        $p = "cg_{$paramCounter}";

        switch ($type) {
            case 'eq':
            case 'basic':
                $qb->setParameter($p, $cond['value']);
                return "`{$field}` = :{$p}";

            case 'neq':
                $qb->setParameter($p, $cond['value']);
                return "`{$field}` != :{$p}";

            case 'in':
                $values = $cond['values'] ?? [];
                if (empty($values)) {
                    return null;
                }
                $qb->setParameter($p, $values, \Doctrine\DBAL\ArrayParameterType::STRING);
                return "`{$field}` IN (:{$p})";

            case 'between':
                $pMin = "cg_{$paramCounter}_min";
                $pMax = "cg_{$paramCounter}_max";
                $qb->setParameter($pMin, $cond['min']);
                $qb->setParameter($pMax, $cond['max']);
                return "`{$field}` BETWEEN :{$pMin} AND :{$pMax}";

            case 'like':
                $qb->setParameter($p, $cond['value']);
                return "`{$field}` LIKE :{$p}";

            case 'not_like':
                $qb->setParameter($p, $cond['value']);
                return "`{$field}` NOT LIKE :{$p}";

            case 'null':
                return "`{$field}` IS NULL";

            case 'not_null':
                return "`{$field}` IS NOT NULL";

            default:
                return null;
        }
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
        $tableName = $this->safeTable($tableName);
        if (!$this->tableExists($tableName)) {
            return 0;
        }

        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");
    }

    // ========================================================================
    // AGGREGATE QUERIES
    // ========================================================================

    /**
     * Execute an aggregate query with the same filter options as listEntries().
     * The 'select' option should contain the aggregate expression.
     *
     * @return mixed The aggregate result value
     */
    public function aggregateQuery(string $tableName, array $options = []): mixed
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);
        $qb = $this->buildFilteredQueryBuilder($tableName, $options, $validColumns);

        return $qb->executeQuery()->fetchOne();
    }

    // ========================================================================
    // BULK OPERATIONS
    // ========================================================================

    /**
     * Update all rows matching the filter conditions.
     * Returns the number of affected rows.
     */
    public function bulkUpdate(string $tableName, array $data, array $options = []): int
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);

        // Whitelist update data against valid columns
        $safeData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $validColumns, true) && $key !== 'id') {
                $safeData[$key] = $value;
            }
        }
        if (empty($safeData)) {
            return 0;
        }

        // Build a filtered SELECT to get matching IDs
        $options['select'] = ['id'];
        $qb = $this->buildFilteredQueryBuilder($tableName, $options, $validColumns);
        $qb->setMaxResults(100000);
        $ids = $qb->executeQuery()->fetchFirstColumn();

        if (empty($ids)) {
            return 0;
        }

        // Bulk update using IN clause
        $updateQb = $this->connection->createQueryBuilder();
        $updateQb->update($tableName);

        $paramIdx = 0;
        foreach ($safeData as $col => $val) {
            $p = "bu_{$paramIdx}";
            $updateQb->set("`{$col}`", ":{$p}");
            $updateQb->setParameter($p, $val);
            $paramIdx++;
        }

        $updateQb->where('id IN (:bu_ids)')
            ->setParameter('bu_ids', $ids, \Doctrine\DBAL\ArrayParameterType::STRING);

        return $updateQb->executeStatement();
    }

    /**
     * Delete all rows matching the filter conditions.
     * Returns the number of deleted rows.
     */
    public function bulkDelete(string $tableName, array $options = []): int
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);

        // Build a filtered SELECT to get matching IDs
        $options['select'] = ['id'];
        $qb = $this->buildFilteredQueryBuilder($tableName, $options, $validColumns);
        $qb->setMaxResults(100000);
        $ids = $qb->executeQuery()->fetchFirstColumn();

        if (empty($ids)) {
            return 0;
        }

        // Bulk delete using IN clause
        $deleteQb = $this->connection->createQueryBuilder();
        $deleteQb->delete($tableName)
            ->where('id IN (:bd_ids)')
            ->setParameter('bd_ids', $ids, \Doctrine\DBAL\ArrayParameterType::STRING);

        return $deleteQb->executeStatement();
    }

    // ========================================================================
    // QUERY DEBUGGING
    // ========================================================================

    /**
     * Build and return the SQL string without executing.
     */
    public function buildSql(string $tableName, array $options = []): string
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);
        $qb = $this->buildFilteredQueryBuilder($tableName, $options, $validColumns);

        // Apply sort
        $sortBy = $options['sort_by'] ?? 'id';
        if ($sortBy === 'RAND()') {
            $qb->orderBy('RAND()');
        } else {
            if (!in_array($sortBy, $validColumns, true)) {
                $sortBy = 'id';
            }
            $sortDir = strtoupper($options['sort_dir'] ?? 'DESC');
            if (!in_array($sortDir, ['ASC', 'DESC'])) {
                $sortDir = 'DESC';
            }
            $qb->orderBy("`{$sortBy}`", $sortDir);

            // Additional sort columns
            if (!empty($options['additional_sorts'])) {
                foreach ($options['additional_sorts'] as $addSort) {
                    $addField = $addSort['field'] ?? '';
                    $addDir = strtoupper($addSort['dir'] ?? 'ASC');
                    if (in_array($addField, $validColumns, true) && in_array($addDir, ['ASC', 'DESC'], true)) {
                        $qb->addOrderBy("`{$addField}`", $addDir);
                    }
                }
            }
        }

        // Apply pagination
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, (int) ($options['per_page'] ?? 20));
        $firstResult = ($page - 1) * $perPage;

        if (isset($options['offset']) && is_int($options['offset']) && $options['offset'] >= 0) {
            $firstResult = $options['offset'];
        }

        $qb->setFirstResult($firstResult)->setMaxResults($perPage);

        $sql = $qb->getSQL();

        // Inline parameters for readability
        $params = $qb->getParameters();
        foreach ($params as $key => $val) {
            $display = is_array($val) ? implode("', '", $val) : (string) $val;
            $sql = str_replace(":{$key}", "'" . addslashes($display) . "'", $sql);
        }

        return $sql;
    }

    // ========================================================================
    // INTERNAL: SHARED QUERY BUILDER
    // ========================================================================

    /**
     * Build a QueryBuilder with all filter options applied.
     * Shared by listEntries, aggregateQuery, bulkUpdate, bulkDelete, buildSql.
     */
    private function buildFilteredQueryBuilder(string $tableName, array $options, array $validColumns): \Doctrine\DBAL\Query\QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        // Select
        if (!empty($options['select'])) {
            $selectExprs = [];
            foreach ($options['select'] as $col) {
                // Allow safe aggregate expressions: FUNC(col), FUNC(*), col as alias
                if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX|DATE|YEAR|MONTH|DAY)\(([a-zA-Z0-9_*]+)\)(\s+as\s+[a-zA-Z0-9_]+)?$/i', $col)) {
                    $selectExprs[] = $col; // safe aggregate expression
                } elseif (in_array($col, $validColumns, true)) {
                    $selectExprs[] = "`{$col}`";
                }
            }
            $qb->select(...($selectExprs ?: ['*']));
        } else {
            $qb->select('*');
        }

        // DISTINCT
        if (!empty($options['distinct'])) {
            $qb->distinct();
        }

        $qb->from($tableName);

        // Search
        if (!empty($options['search']) && !empty($options['searchFields'])) {
            $searchTerm = $options['search'];
            $validSearchFields = array_filter($options['searchFields'], fn($f) => in_array($f, $validColumns, true));

            if (!empty($validSearchFields)) {
                $orConditions = [];
                $paramIndex = 0;
                foreach ($validSearchFields as $field) {
                    $orConditions[] = "`{$field}` LIKE :bfq_search{$paramIndex}";
                    $qb->setParameter("bfq_search{$paramIndex}", '%' . $searchTerm . '%');
                    $paramIndex++;
                }
                if (!empty($orConditions)) {
                    $qb->andWhere('(' . implode(' OR ', $orConditions) . ')');
                }
            }
        }

        // Equality filters
        if (!empty($options['filters'])) {
            foreach ($options['filters'] as $field => $value) {
                if (in_array($field, $validColumns, true)) {
                    $qb->andWhere("`{$field}` = :bfq_f_{$field}")
                        ->setParameter("bfq_f_{$field}", $value);
                }
            }
        }

        // IN filters
        if (!empty($options['filters_in'])) {
            foreach ($options['filters_in'] as $field => $values) {
                if (in_array($field, $validColumns, true) && !empty($values)) {
                    $qb->andWhere("`{$field}` IN (:bfq_fin_{$field})")
                        ->setParameter("bfq_fin_{$field}", $values, \Doctrine\DBAL\ArrayParameterType::STRING);
                }
            }
        }

        // NOT IN filters
        if (!empty($options['filters_not_in'])) {
            foreach ($options['filters_not_in'] as $field => $values) {
                if (in_array($field, $validColumns, true) && !empty($values)) {
                    $qb->andWhere("`{$field}` NOT IN (:bfq_fnin_{$field})")
                        ->setParameter("bfq_fnin_{$field}", $values, \Doctrine\DBAL\ArrayParameterType::STRING);
                }
            }
        }

        // NOT filters
        if (!empty($options['filters_not'])) {
            foreach ($options['filters_not'] as $field => $value) {
                if (in_array($field, $validColumns, true)) {
                    $qb->andWhere("`{$field}` != :bfq_fnot_{$field}")
                        ->setParameter("bfq_fnot_{$field}", $value);
                }
            }
        }

        // BETWEEN filters
        if (!empty($options['filters_between'])) {
            foreach ($options['filters_between'] as $field => $range) {
                if (in_array($field, $validColumns, true) && is_array($range) && count($range) === 2) {
                    $qb->andWhere("`{$field}` BETWEEN :bfq_btw_{$field}_min AND :bfq_btw_{$field}_max")
                        ->setParameter("bfq_btw_{$field}_min", $range[0])
                        ->setParameter("bfq_btw_{$field}_max", $range[1]);
                }
            }
        }

        // NOT BETWEEN filters
        if (!empty($options['filters_not_between'])) {
            foreach ($options['filters_not_between'] as $field => $range) {
                if (in_array($field, $validColumns, true) && is_array($range) && count($range) === 2) {
                    $qb->andWhere("`{$field}` NOT BETWEEN :bfq_nbtw_{$field}_min AND :bfq_nbtw_{$field}_max")
                        ->setParameter("bfq_nbtw_{$field}_min", $range[0])
                        ->setParameter("bfq_nbtw_{$field}_max", $range[1]);
                }
            }
        }

        // NULL filters (skip deleted_at if column doesn't exist and it's auto-added)
        if (!empty($options['filters_null'])) {
            foreach ($options['filters_null'] as $field) {
                if (!in_array($field, $validColumns, true)) {
                    continue;
                }
                $qb->andWhere("`{$field}` IS NULL");
            }
        }

        // NOT NULL filters
        if (!empty($options['filters_not_null'])) {
            foreach ($options['filters_not_null'] as $field) {
                if (in_array($field, $validColumns, true)) {
                    $qb->andWhere("`{$field}` IS NOT NULL");
                }
            }
        }

        // LIKE filters
        if (!empty($options['filters_like'])) {
            foreach ($options['filters_like'] as $field => $pattern) {
                if (in_array($field, $validColumns, true)) {
                    $qb->andWhere("`{$field}` LIKE :bfq_like_{$field}")
                        ->setParameter("bfq_like_{$field}", $pattern);
                }
            }
        }

        // NOT LIKE filters
        if (!empty($options['filters_not_like'])) {
            foreach ($options['filters_not_like'] as $field => $pattern) {
                if (in_array($field, $validColumns, true)) {
                    $qb->andWhere("`{$field}` NOT LIKE :bfq_nlike_{$field}")
                        ->setParameter("bfq_nlike_{$field}", $pattern);
                }
            }
        }

        // DATE filters
        if (!empty($options['filters_date'])) {
            $dateIdx = 0;
            foreach ($options['filters_date'] as $dateCond) {
                $field = $dateCond['field'] ?? '';
                $func = $dateCond['func'] ?? '';
                $value = $dateCond['value'] ?? '';
                if (in_array($field, $validColumns, true) && in_array($func, ['DATE', 'YEAR', 'MONTH', 'DAY'], true)) {
                    $p = "bfq_date_{$dateIdx}";
                    $qb->andWhere("{$func}(`{$field}`) = :{$p}")
                        ->setParameter($p, $value);
                    $dateIdx++;
                }
            }
        }

        // Raw conditions
        if (!empty($options['raw_conditions'])) {
            foreach ($options['raw_conditions'] as $raw) {
                $expr = $raw['expr'] ?? '';
                if (!empty($expr)) {
                    $qb->andWhere($expr);
                    foreach ($raw['bindings'] ?? [] as $paramName => $paramValue) {
                        $qb->setParameter($paramName, $paramValue);
                    }
                }
            }
        }

        // Comparison filters (>, <, >=, <=)
        if (!empty($options['filters_compare'])) {
            $cmpIdx = 0;
            foreach ($options['filters_compare'] as $cmp) {
                $field = $cmp['field'] ?? '';
                $operator = $cmp['operator'] ?? '=';
                if (in_array($field, $validColumns, true) && in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
                    $p = "bfq_cmp_{$cmpIdx}";
                    $qb->andWhere("`{$field}` {$operator} :{$p}")
                        ->setParameter($p, $cmp['value']);
                    $cmpIdx++;
                }
            }
        }

        // Column comparison filters
        if (!empty($options['filters_column'])) {
            foreach ($options['filters_column'] as $colCmp) {
                $col1 = $colCmp['col1'] ?? '';
                $operator = $colCmp['operator'] ?? '=';
                $col2 = $colCmp['col2'] ?? '';
                if (in_array($col1, $validColumns, true) && in_array($col2, $validColumns, true)
                    && in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
                    $qb->andWhere("`{$col1}` {$operator} `{$col2}`");
                }
            }
        }

        // JSON contains filters
        if (!empty($options['filters_json_contains'])) {
            $jsonIdx = 0;
            foreach ($options['filters_json_contains'] as $jsonCond) {
                $field = $jsonCond['field'] ?? '';
                if (in_array($field, $validColumns, true)) {
                    $p = "bfq_json_{$jsonIdx}";
                    $qb->andWhere("JSON_CONTAINS(`{$field}`, :{$p})")
                        ->setParameter($p, json_encode($jsonCond['value']));
                    $jsonIdx++;
                }
            }
        }

        // Condition groups
        if (!empty($options['condition_groups'])) {
            $this->applyConditionGroups($qb, $options['condition_groups'], $validColumns);
        }

        // GROUP BY
        if (!empty($options['group_by'])) {
            $groupCols = [];
            foreach ($options['group_by'] as $col) {
                if (in_array($col, $validColumns, true)) {
                    $groupCols[] = "`{$col}`";
                }
            }
            if (!empty($groupCols)) {
                $qb->groupBy(...$groupCols);
            }
        }

        // HAVING
        if (!empty($options['having']) && !empty($options['group_by'])) {
            $havingIdx = 0;
            $allowedAggFuncs = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
            foreach ($options['having'] as $hCond) {
                $expr = $hCond['expr'] ?? '';
                $operator = $hCond['operator'] ?? '=';
                if (!in_array($operator, ['=', '!=', '>', '<', '>=', '<='], true)) {
                    continue;
                }

                // Validate expression: FUNC(col), FUNC(*), or whitelisted column
                if (!preg_match('/^([A-Z]+)\(([a-zA-Z0-9_*]+)\)$/', $expr, $m)) {
                    if (!in_array($expr, $validColumns, true)) {
                        continue;
                    }
                } else {
                    if (!in_array($m[1], $allowedAggFuncs, true)) {
                        continue;
                    }
                    if ($m[2] !== '*' && !in_array($m[2], $validColumns, true)) {
                        continue;
                    }
                }

                $p = "bfq_having_{$havingIdx}";
                $qb->andHaving("{$expr} {$operator} :{$p}")
                    ->setParameter($p, $hCond['value']);
                $havingIdx++;
            }
        }

        return $qb;
    }

    // ========================================================================
    // ATOMIC COLUMN OPERATIONS
    // ========================================================================

    /**
     * Atomically increment (or decrement with negative amount) a numeric column.
     */
    public function incrementColumn(string $tableName, int|string $id, string $column, int $amount): void
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);

        if (!in_array($column, $validColumns, true)) {
            throw new \InvalidArgumentException("Column '{$column}' does not exist in table '{$tableName}'.");
        }

        $sign = $amount >= 0 ? '+' : '-';
        $absAmount = abs($amount);

        $this->connection->executeStatement(
            "UPDATE `{$tableName}` SET `{$column}` = `{$column}` {$sign} :amount, `updated_at` = :now WHERE `id` = :id",
            ['amount' => $absAmount, 'now' => (new \DateTime())->format('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    // ========================================================================
    // UPSERT (INSERT OR UPDATE)
    // ========================================================================

    /**
     * Insert a new entry or update if a matching entry exists.
     * The $match array defines which columns to match against.
     * Returns the entry ID.
     */
    public function upsertEntry(string $tableName, array $match, array $data, bool $useUuid = false): int|string
    {
        $tableName = $this->safeTable($tableName);
        $validColumns = $this->getTableColumns($tableName);

        // Validate match columns
        foreach (array_keys($match) as $col) {
            if (!in_array($col, $validColumns, true)) {
                throw new \InvalidArgumentException("Match column '{$col}' does not exist in table '{$tableName}'.");
            }
        }

        // Find existing entry by match criteria
        $qb = $this->connection->createQueryBuilder()
            ->select('id')
            ->from($tableName);

        $idx = 0;
        foreach ($match as $col => $val) {
            $p = "ups_m_{$idx}";
            $qb->andWhere("`{$col}` = :{$p}")
                ->setParameter($p, $val);
            $idx++;
        }

        $existing = $qb->executeQuery()->fetchAssociative();

        if ($existing) {
            // Update existing
            $updateData = array_merge($data, ['updated_at' => (new \DateTime())->format('Y-m-d H:i:s')]);
            $safeData = [];
            foreach ($updateData as $key => $value) {
                if (in_array($key, $validColumns, true) && $key !== 'id') {
                    $safeData[$key] = $value;
                }
            }
            if (!empty($safeData)) {
                $this->connection->update($tableName, $safeData, ['id' => $existing['id']]);
            }
            return $existing['id'];
        }

        // Insert new
        $insertData = array_merge($match, $data);
        return $this->insertEntry($tableName, $insertData, $useUuid);
    }
}
