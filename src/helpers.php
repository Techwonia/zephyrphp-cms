<?php

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\TranslationService;
use ZephyrPHP\Cache\CacheManager;

if (!function_exists('format_bytes')) {
    /**
     * Format bytes into human-readable string (KB, MB, GB).
     */
    function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

if (!function_exists('admin_url')) {
    /**
     * Generate a URL to the admin panel.
     * Reads the admin path from ADMIN_PATH env var (default: 'admin').
     *
     * @param string $path Path within admin (e.g. '/collections', '/themes')
     * @return string Full admin URL (e.g. '/admin/collections')
     */
    function admin_url(string $path = ''): string
    {
        static $prefix = null;
        if ($prefix === null) {
            $raw = $_ENV['ADMIN_PATH'] ?? 'admin';
            // Sanitize: only allow alphanumeric, hyphens, underscores
            $prefix = '/' . trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $raw), '/');
        }
        if ($path === '' || $path === '/') {
            return $prefix;
        }
        return $prefix . '/' . ltrim($path, '/');
    }
}

if (!function_exists('admin_path')) {
    /**
     * Get the admin path prefix without leading slash (for route registration).
     *
     * @return string e.g. 'admin'
     */
    function admin_path(): string
    {
        $raw = $_ENV['ADMIN_PATH'] ?? 'admin';
        return trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $raw), '/');
    }
}

if (!function_exists('login_url')) {
    /**
     * Get the login URL from config or env.
     */
    function login_url(): string
    {
        return \ZephyrPHP\Config\Config::get('auth.routes.login', env('AUTH_LOGIN_URL', '/zephyrphp/auth/login'));
    }
}

if (!function_exists('logout_url')) {
    /**
     * Get the logout URL from config or env.
     */
    function logout_url(): string
    {
        return \ZephyrPHP\Config\Config::get('auth.routes.logout', env('AUTH_LOGOUT_URL', '/zephyrphp/auth/logout'));
    }
}

if (!function_exists('collection')) {
    /**
     * Query collection/page type entries.
     *
     * Delegates to EntryQuery for all query logic, caching, and relation resolution.
     *
     * @param string $slug Collection slug
     * @param array  $options Options: per_page, page, sort_by, sort_dir, filters, search, searchFields, resolve_depth, locale, no_cache
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int}
     */
    function collection(string $slug, array $options = []): array
    {
        $emptyResult = ['data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1];

        try {
            $query = EntryQuery::collection($slug);

            // Cache control
            if (!empty($options['no_cache'])) {
                $query->noCache();
            }

            // Sorting
            $sortBy = $options['sort_by'] ?? 'id';
            $sortDir = $options['sort_dir'] ?? 'DESC';
            $query->orderBy($sortBy, $sortDir);

            // Filters
            if (!empty($options['filters'])) {
                foreach ($options['filters'] as $field => $value) {
                    $query->where($field, $value);
                }
            }

            // Search
            if (!empty($options['search'])) {
                $searchFields = $options['searchFields'] ?? null;
                $query->search($options['search'], $searchFields);
            }

            // Relation resolution
            $depth = isset($options['resolve_depth']) ? (int) $options['resolve_depth'] : 1;
            if ($depth > 0) {
                $query->withRelations($depth);
            }

            // Locale
            if (!empty($options['locale'])) {
                $query->locale($options['locale']);
            }

            // Pagination
            $perPage = isset($options['per_page']) ? (int) $options['per_page'] : 10;
            $page = isset($options['page']) ? (int) $options['page'] : max(1, (int) ($_GET['page'] ?? 1));

            return $query->paginate($page, $perPage);
        } catch (\Throwable $e) {
            return $emptyResult;
        }
    }
}

if (!function_exists('entry')) {
    /**
     * Fetch a single entry by slug or ID.
     *
     * Delegates to EntryQuery for query logic, caching, and relation resolution.
     *
     * @param string     $slug       Collection slug
     * @param string|int $identifier Entry slug (string) or ID (int)
     * @param array      $options    Options: resolve_depth (default 1, set 0 to skip), locale, no_cache
     * @return array|null
     */
    function entry(string $slug, string|int $identifier, array $options = []): ?array
    {
        try {
            $query = EntryQuery::collection($slug);

            // Cache control
            if (!empty($options['no_cache'])) {
                $query->noCache();
            }

            // Relation resolution
            $depth = isset($options['resolve_depth']) ? (int) $options['resolve_depth'] : 1;
            if ($depth > 0) {
                $query->withRelations($depth);
            }

            // Locale
            if (!empty($options['locale'])) {
                $query->locale($options['locale']);
            }

            // Find by slug (non-numeric string) or by ID
            if (is_string($identifier) && !is_numeric($identifier)) {
                return $query->findBySlug($identifier);
            }

            return $query->find($identifier);
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

        $srcCol = SchemaManager::validateIdentifier("{$tableName}_id", 'pivot column');
        $tgtCol = SchemaManager::validateIdentifier("{$relTableName}_id", 'pivot column');

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
