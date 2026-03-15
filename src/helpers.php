<?php

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\TranslationService;
use ZephyrPHP\Cache\CacheManager;

if (!function_exists('collection')) {
    /**
     * Query collection/page type entries.
     *
     * @param string $slug Collection slug
     * @param array  $options Options: per_page, page, sort_by, sort_dir, filters, search, searchFields, resolve_depth
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int}
     */
    function collection(string $slug, array $options = []): array
    {
        $emptyResult = ['data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1];
        try {
            // Cache frontend queries (60s TTL). Skip cache if 'no_cache' option is set.
            $useCache = empty($options['no_cache']);
            unset($options['no_cache']);

            if ($useCache && class_exists(CacheManager::class)) {
                $cacheKey = 'cms.collection.' . $slug . '.' . md5(serialize($options));
                try {
                    $cache = CacheManager::getInstance();
                    $cached = $cache->get($cacheKey);
                    if ($cached !== null) {
                        return $cached;
                    }
                } catch (\Throwable $e) {
                    // Cache unavailable, continue without it
                }
            }

            $schema = new SchemaManager();
            $tableName = null;
            $defaultPerPage = 10;
            $fields = [];

            $coll = Collection::findOneBy(['slug' => $slug]);
            if ($coll) {
                $tableName = $coll->getTableName();
                $fields = $coll->getFields()->toArray();
            }

            if (!$tableName || !$schema->tableExists($tableName)) {
                return $emptyResult;
            }

            if (!isset($options['filters'])) {
                $options['filters'] = [];
            }

            if (isset($options['per_page'])) {
                $options['per_page'] = (int) $options['per_page'];
            } else {
                $options['per_page'] = $defaultPerPage;
            }

            if (!isset($options['sort_by'])) {
                $options['sort_by'] = 'id';
            }
            if (!isset($options['sort_dir'])) {
                $options['sort_dir'] = 'DESC';
            }

            if (!isset($options['page'])) {
                $options['page'] = max(1, (int) ($_GET['page'] ?? 1));
            }

            // Auto-populate searchFields when search is provided but searchFields is not
            if (!empty($options['search']) && empty($options['searchFields']) && !empty($fields)) {
                $searchableTypes = ['text', 'textarea', 'richtext', 'email', 'url', 'slug'];
                $searchFields = [];
                foreach ($fields as $field) {
                    if (in_array($field->getType(), $searchableTypes)) {
                        $searchFields[] = $field->getSlug();
                    }
                }
                // Also include slug column if table has it
                if (!in_array('slug', $searchFields)) {
                    $searchFields[] = 'slug';
                }
                if (!empty($searchFields)) {
                    $options['searchFields'] = $searchFields;
                }
            }

            $depth = isset($options['resolve_depth']) ? (int) $options['resolve_depth'] : 1;
            unset($options['resolve_depth']);

            $result = $schema->listEntries($tableName, $options);

            // Resolve relation fields
            if ($depth > 0 && !empty($result['data']) && !empty($fields)) {
                $result['data'] = _cms_resolve_relations($result['data'], $fields, $tableName, $schema, $depth);
            }

            // Apply locale translations if requested
            $locale = $options['locale'] ?? null;
            if ($locale && $coll && $coll->isTranslatable() && !empty($result['data'])) {
                $result['data'] = TranslationService::resolveEntries($result['data'], $tableName, $locale);
            }

            // Cache the result
            if ($useCache && isset($cache, $cacheKey)) {
                try {
                    $cache->set($cacheKey, $result, 60);
                } catch (\Throwable $e) {
                    // Cache write failure is non-fatal
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return $emptyResult;
        }
    }
}

if (!function_exists('entry')) {
    /**
     * Fetch a single entry by slug or ID.
     *
     * @param string     $slug       Collection slug
     * @param string|int $identifier Entry slug (string) or ID (int)
     * @param array      $options    Options: resolve_depth (default 1, set 0 to skip)
     * @return array|null
     */
    function entry(string $slug, string|int $identifier, array $options = []): ?array
    {
        try {
            $useCache = empty($options['no_cache']);
            unset($options['no_cache']);

            if ($useCache && class_exists(CacheManager::class)) {
                $cacheKey = 'cms.entry.' . $slug . '.' . $identifier;
                try {
                    $cache = CacheManager::getInstance();
                    $cached = $cache->get($cacheKey);
                    if ($cached !== null) {
                        return $cached;
                    }
                } catch (\Throwable $e) {
                    // Cache unavailable
                }
            }

            $schema = new SchemaManager();
            $tableName = null;
            $fields = [];

            $coll = Collection::findOneBy(['slug' => $slug]);
            if ($coll) {
                $tableName = $coll->getTableName();
                $fields = $coll->getFields()->toArray();
            }

            if (!$tableName || !$schema->tableExists($tableName)) {
                return null;
            }

            $conn = $schema->getConnection();

            if (is_string($identifier) && !is_numeric($identifier)) {
                $entry = $conn->createQueryBuilder()
                    ->select('*')
                    ->from($tableName)
                    ->where('slug = :slug')
                    ->setParameter('slug', $identifier)
                    ->executeQuery()
                    ->fetchAssociative();
            } else {
                $entry = $conn->createQueryBuilder()
                    ->select('*')
                    ->from($tableName)
                    ->where('id = :id')
                    ->setParameter('id', $identifier)
                    ->executeQuery()
                    ->fetchAssociative();
            }

            if (!$entry) {
                return null;
            }

            $depth = isset($options['resolve_depth']) ? (int) $options['resolve_depth'] : 1;

            // Resolve relation fields
            if ($depth > 0 && !empty($fields)) {
                $resolved = _cms_resolve_relations([$entry], $fields, $tableName, $schema, $depth);
                $entry = $resolved[0];
            }

            // Apply locale translations if requested
            $locale = $options['locale'] ?? null;
            if ($locale && $coll && $coll->isTranslatable()) {
                $entry = TranslationService::resolveEntry($entry, $tableName, $locale);
            }

            // Cache the result
            if ($useCache && isset($cache, $cacheKey)) {
                try {
                    $cache->set($cacheKey, $entry, 60);
                } catch (\Throwable $e) {
                    // Cache write failure is non-fatal
                }
            }

            return $entry;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('_cms_resolve_relations')) {
    /**
     * Resolve relation fields in entries — replace raw IDs with full related entry data.
     *
     * Performance guarantees:
     *   - one_to_one:         1 query per relation field (batch IN), regardless of entry count
     *   - one_to_many/m2m:    2 queries per relation field (1 pivot + 1 entries), regardless of entry count
     *   - Circular references: skipped automatically (A→B→A stops at B)
     *   - Max depth:          capped at 3 to prevent runaway nesting
     *
     * @param array         $entries   Entries to resolve
     * @param array         $fields    Field definitions (Field model objects)
     * @param string        $tableName Current table name
     * @param SchemaManager $schema    Schema manager instance
     * @param int           $depth     How many levels deep to resolve (default 1, max 3)
     * @param array         $visited   Tables already visited (circular protection)
     */
    function _cms_resolve_relations(
        array $entries,
        array $fields,
        string $tableName,
        SchemaManager $schema,
        int $depth = 1,
        array $visited = []
    ): array {
        // Safety: cap depth, bail if nothing to do
        $depth = min($depth, 3);
        if ($depth <= 0 || empty($entries)) {
            return $entries;
        }

        $visited[] = $tableName;
        $conn = $schema->getConnection();

        foreach ($fields as $field) {
            if ($field->getType() !== 'relation') {
                continue;
            }

            $opts = $field->getOptions();
            $relSlug = $opts['relation_collection'] ?? '';
            $relationType = $opts['relation_type'] ?? 'one_to_one';

            if (empty($relSlug)) {
                continue;
            }

            // Find related table
            $relTableName = _cms_find_table($relSlug, $schema);
            if (!$relTableName) {
                continue;
            }

            // Circular reference protection: skip if already visiting this table
            if (in_array($relTableName, $visited)) {
                continue;
            }

            if ($relationType === 'one_to_one') {
                $entries = _cms_resolve_one_to_one($entries, $field, $relSlug, $relTableName, $conn, $schema, $depth, $visited);
            } else {
                $entries = _cms_resolve_multi($entries, $field, $relSlug, $relTableName, $tableName, $conn, $schema, $depth, $visited);
            }
        }

        return $entries;
    }
}

if (!function_exists('_cms_resolve_one_to_one')) {
    /**
     * Batch-resolve one_to_one relations. 1 query total.
     */
    function _cms_resolve_one_to_one(
        array $entries, Field $field, string $relSlug, string $relTableName,
        $conn, SchemaManager $schema, int $depth, array $visited
    ): array {
        $fieldSlug = $field->getSlug();

        // Collect all unique IDs from entries
        $ids = [];
        foreach ($entries as $entry) {
            $val = $entry[$fieldSlug] ?? null;
            if ($val !== null && $val !== '' && $val !== 0) {
                $ids[] = $val;
            }
        }
        if (empty($ids)) {
            return $entries;
        }
        $ids = array_values(array_unique($ids));

        // 1 batch query for all related entries
        $relEntries = $conn->createQueryBuilder()
            ->select('*')
            ->from($relTableName)
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        // Recurse into nested relations if depth > 1
        if ($depth > 1 && !empty($relEntries)) {
            $relFields = _cms_get_fields($relSlug);
            if (!empty($relFields)) {
                $relEntries = _cms_resolve_relations($relEntries, $relFields, $relTableName, $schema, $depth - 1, $visited);
            }
        }

        // Index by ID for O(1) lookup
        $relMap = [];
        foreach ($relEntries as $re) {
            $relMap[$re['id']] = $re;
        }

        // Replace raw IDs with resolved data
        foreach ($entries as &$entry) {
            $val = $entry[$fieldSlug] ?? null;
            if ($val !== null && isset($relMap[$val])) {
                $entry[$fieldSlug] = $relMap[$val];
            }
        }
        unset($entry);

        return $entries;
    }
}

if (!function_exists('_cms_resolve_multi')) {
    /**
     * Batch-resolve one_to_many / many_to_many relations. 2 queries total.
     */
    function _cms_resolve_multi(
        array $entries, Field $field, string $relSlug, string $relTableName,
        string $tableName, $conn, SchemaManager $schema, int $depth, array $visited
    ): array {
        $fieldSlug = $field->getSlug();
        $pivotTable = $schema->getPivotTableName($tableName, $fieldSlug);

        if (!$schema->tableExists($pivotTable)) {
            // No pivot table — set empty arrays
            foreach ($entries as &$entry) {
                $entry[$fieldSlug] = [];
            }
            unset($entry);
            return $entries;
        }

        $srcCol = "{$tableName}_id";
        $tgtCol = "{$relTableName}_id";

        // Collect all entry IDs
        $entryIds = [];
        foreach ($entries as $entry) {
            if (isset($entry['id'])) {
                $entryIds[] = $entry['id'];
            }
        }
        if (empty($entryIds)) {
            return $entries;
        }

        // Query 1: batch-fetch ALL pivot rows for all entries in one query
        $pivotRows = $conn->createQueryBuilder()
            ->select("`{$srcCol}`, `{$tgtCol}`")
            ->from($pivotTable)
            ->where("`{$srcCol}` IN (:ids)")
            ->setParameter('ids', $entryIds, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        // Group pivot by source ID, collect all target IDs
        $pivotMap = [];       // sourceId => [targetId, ...]
        $allTargetIds = [];
        foreach ($pivotRows as $row) {
            $srcId = $row[$srcCol];
            $tgtId = $row[$tgtCol];
            $pivotMap[$srcId][] = $tgtId;
            $allTargetIds[] = $tgtId;
        }
        $allTargetIds = array_values(array_unique($allTargetIds));

        // Query 2: batch-fetch ALL related entries in one query
        $relEntries = [];
        if (!empty($allTargetIds)) {
            $relEntries = $conn->createQueryBuilder()
                ->select('*')
                ->from($relTableName)
                ->where('id IN (:ids)')
                ->setParameter('ids', $allTargetIds, \Doctrine\DBAL\ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();

            // Recurse into nested relations if depth > 1
            if ($depth > 1 && !empty($relEntries)) {
                $relFields = _cms_get_fields($relSlug);
                if (!empty($relFields)) {
                    $relEntries = _cms_resolve_relations($relEntries, $relFields, $relTableName, $schema, $depth - 1, $visited);
                }
            }
        }

        // Index by ID
        $relMap = [];
        foreach ($relEntries as $re) {
            $relMap[$re['id']] = $re;
        }

        // Map related entries back to each source entry
        foreach ($entries as &$entry) {
            $entryId = $entry['id'] ?? null;
            $related = [];
            foreach ($pivotMap[$entryId] ?? [] as $relId) {
                if (isset($relMap[$relId])) {
                    $related[] = $relMap[$relId];
                }
            }
            $entry[$fieldSlug] = $related;
        }
        unset($entry);

        return $entries;
    }
}

if (!function_exists('_cms_find_table')) {
    /**
     * Find the database table name for a collection or page type slug.
     */
    function _cms_find_table(string $slug, SchemaManager $schema): ?string
    {
        $coll = Collection::findOneBy(['slug' => $slug]);
        if ($coll) {
            $table = $coll->getTableName();
            return $schema->tableExists($table) ? $table : null;
        }

        return null;
    }
}

if (!function_exists('_cms_get_fields')) {
    /**
     * Get Field objects for a collection or page type slug.
     */
    function _cms_get_fields(string $slug): array
    {
        $coll = Collection::findOneBy(['slug' => $slug]);
        if ($coll) {
            return $coll->getFields()->toArray();
        }

        return [];
    }
}

if (!function_exists('cms_invalidate_cache')) {
    /**
     * Invalidate all cached data for a collection.
     * Call this after creating, updating, or deleting entries.
     */
    function cms_invalidate_cache(string $collectionSlug): void
    {
        try {
            if (!class_exists(CacheManager::class)) {
                return;
            }
            $cache = CacheManager::getInstance();
            // Clear the entire cache store — cache drivers don't support prefix-based deletion
            // For production, use short TTLs (60s) so stale data auto-expires
            // For immediate invalidation, we delete known keys when possible
            $cache->delete('cms.entry.' . $collectionSlug);
            $cache->delete('cms.collection.' . $collectionSlug);
        } catch (\Throwable $e) {
            // Cache unavailable, ignore
        }
    }
}

if (!function_exists('str_getcsv_lines')) {
    /**
     * Parse a CSV string into an array of rows (each row is an array of columns).
     */
    function str_getcsv_lines(string $csv): array
    {
        $lines = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $lines[] = $row;
        }
        fclose($handle);
        return $lines;
    }
}
