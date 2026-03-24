<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cache\CacheManager;

/**
 * Fluent query builder for dynamic collection entries.
 *
 * Wraps SchemaManager (DBAL) with a clean, chainable API.
 * Entries are returned as arrays — no ORM entities for dynamic tables.
 *
 * Usage:
 *   $posts = Entry::collection('blog')->where('status', 'published')->orderBy('created_at', 'DESC')->paginate();
 *   $post  = Entry::collection('blog')->with('author', 'categories')->find(42);
 *   $id    = Entry::collection('blog')->create(['title' => 'Hello', 'categories' => [1, 3]]);
 */
class EntryQuery
{
    private string $collectionSlug;
    private ?Collection $collection = null;
    private ?SchemaManager $schema = null;
    private ?string $tableName = null;
    private ?array $fields = null;

    // Query state
    private array $wheres = [];
    private array $whereIns = [];
    private array $whereNots = [];
    private array $whereBetweens = [];
    private array $whereNulls = [];
    private array $whereNotNulls = [];
    private array $whereLikes = [];
    private array $whereNotLikes = [];
    private array $whereDates = [];
    /** @var array<int, array{boolean: string, type: string, group?: FilterGroup, conditions?: array}> */
    private array $conditionGroups = [];
    private ?string $searchTerm = null;
    private ?array $searchFields = null;
    private string $sortBy = 'id';
    private string $sortDir = 'DESC';
    private ?int $limitValue = null;
    private array $selectColumns = [];
    private array $groupByColumns = [];
    private array $havingConditions = [];
    private array $rawConditions = [];
    private array $whereNotIns = [];
    private array $whereNotBetweens = [];
    private array $whereCompares = [];
    private array $whereColumns = [];
    private array $whereJsonContains = [];
    private array $additionalSorts = [];
    private ?int $offsetValue = null;
    private bool $distinctMode = false;

    // Relation loading
    private array $withRelations = [];
    private bool $resolveAll = false;
    private int $resolveDepth = 0;

    // Locale
    private ?string $localeValue = null;

    // Cache
    private bool $useCache = true;

    // Soft deletes
    private bool $includeTrashed = false;
    private bool $onlyTrashed = false;

    // Scopes
    /** @var array<string, \Closure> */
    private static array $scopes = [];

    private function __construct(string $slug)
    {
        $this->collectionSlug = $slug;
    }

    /**
     * Start a query for a collection.
     *
     *   EntryQuery::collection('blog')->where('status', 'published')->get()
     */
    public static function collection(string $slug): static
    {
        return new static($slug);
    }

    // ========================================================================
    // FILTERING
    // ========================================================================

    /**
     * Add a where condition (field = value) or a grouped condition (Closure).
     *
     *   ->where('status', 'published')
     *   ->where(function(FilterGroup $q) {
     *       $q->where('status', 'published')->orWhere('featured', 1);
     *   })
     */
    public function where(string|\Closure $field, mixed $value = null): static
    {
        if ($field instanceof \Closure) {
            $group = new FilterGroup();
            $field($group);
            if (!$group->isEmpty()) {
                $this->conditionGroups[] = ['boolean' => 'AND', 'type' => 'nested', 'group' => $group];
            }
            return $this;
        }
        $this->wheres[$field] = $value;
        return $this;
    }

    /**
     * Add an OR condition (field = value) or an OR grouped condition (Closure).
     *
     *   ->where('status', 'published')->orWhere('featured', 1)
     *   // → WHERE status = 'published' OR featured = 1
     *
     *   ->where('category', 'news')->orWhere(function(FilterGroup $q) {
     *       $q->where('status', 'scheduled')->whereNotNull('publish_date');
     *   })
     *   // → WHERE category = 'news' OR (status = 'scheduled' AND publish_date IS NOT NULL)
     */
    public function orWhere(string|\Closure $field, mixed $value = null): static
    {
        if ($field instanceof \Closure) {
            $group = new FilterGroup();
            $field($group);
            if (!$group->isEmpty()) {
                $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'nested', 'group' => $group];
            }
            return $this;
        }
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'basic', 'field' => $field, 'value' => $value];
        return $this;
    }

    /**
     * Add an OR IN condition.
     *
     *   ->where('status', 'published')->orWhereIn('category', ['news', 'tech'])
     */
    public function orWhereIn(string $field, array $values): static
    {
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'in', 'field' => $field, 'values' => array_values($values)];
        return $this;
    }

    /**
     * Add an OR NOT EQUAL condition.
     */
    public function orWhereNot(string $field, mixed $value): static
    {
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'neq', 'field' => $field, 'value' => $value];
        return $this;
    }

    /**
     * Add an OR BETWEEN condition.
     */
    public function orWhereBetween(string $field, mixed $min, mixed $max): static
    {
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'between', 'field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Add an OR NULL condition.
     */
    public function orWhereNull(string $field): static
    {
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'null', 'field' => $field];
        return $this;
    }

    /**
     * Add an OR NOT NULL condition.
     */
    public function orWhereNotNull(string $field): static
    {
        $this->conditionGroups[] = ['boolean' => 'OR', 'type' => 'not_null', 'field' => $field];
        return $this;
    }

    /**
     * Filter where field value is in a set of values.
     *
     *   ->whereIn('status', ['published', 'scheduled'])
     */
    public function whereIn(string $field, array $values): static
    {
        $this->whereIns[$field] = array_values($values);
        return $this;
    }

    /**
     * Filter where field value is NOT in a set of values.
     *
     *   ->whereNotIn('status', ['draft', 'archived'])
     */
    public function whereNotIn(string $field, array $values): static
    {
        $this->whereNotIns[$field] = array_values($values);
        return $this;
    }

    /**
     * Filter where field value does NOT equal the given value.
     *
     *   ->whereNot('status', 'draft')
     */
    public function whereNot(string $field, mixed $value): static
    {
        $this->whereNots[$field] = $value;
        return $this;
    }

    /**
     * Filter where field value is between two values (inclusive).
     *
     *   ->whereBetween('price', 10, 100)
     *   ->whereBetween('created_at', '2025-01-01', '2025-12-31')
     */
    public function whereBetween(string $field, mixed $min, mixed $max): static
    {
        $this->whereBetweens[$field] = [$min, $max];
        return $this;
    }

    /**
     * Filter where field value is NOT between two values.
     *
     *   ->whereNotBetween('price', 10, 100)
     *   ->whereNotBetween('created_at', '2025-01-01', '2025-06-30')
     */
    public function whereNotBetween(string $field, mixed $min, mixed $max): static
    {
        $this->whereNotBetweens[$field] = [$min, $max];
        return $this;
    }

    /**
     * Filter where field value is NULL.
     *
     *   ->whereNull('published_at')
     */
    public function whereNull(string $field): static
    {
        $this->whereNulls[] = $field;
        return $this;
    }

    /**
     * Filter where field value is NOT NULL.
     *
     *   ->whereNotNull('published_at')
     */
    public function whereNotNull(string $field): static
    {
        $this->whereNotNulls[] = $field;
        return $this;
    }

    // ========================================================================
    // COMPARISON OPERATORS
    // ========================================================================

    /**
     * Filter with a comparison operator.
     *
     *   ->whereCompare('price', '>', 100)
     *   ->whereCompare('stock', '<=', 0)
     *   ->whereCompare('rating', '>=', 4.5)
     *
     * Supported operators: >, <, >=, <=, =, !=
     */
    public function whereCompare(string $field, string $operator, mixed $value): static
    {
        if (!in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
            throw new \InvalidArgumentException("Invalid comparison operator: '{$operator}'.");
        }
        $this->whereCompares[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    // ========================================================================
    // COLUMN COMPARISONS
    // ========================================================================

    /**
     * Filter by comparing two columns.
     *
     *   ->whereColumn('updated_at', '>', 'created_at')
     *   ->whereColumn('price', '<=', 'max_price')
     */
    public function whereColumn(string $column1, string $operator, string $column2): static
    {
        if (!in_array($operator, ['>', '<', '>=', '<=', '=', '!='], true)) {
            throw new \InvalidArgumentException("Invalid comparison operator: '{$operator}'.");
        }
        $this->whereColumns[] = ['col1' => $column1, 'operator' => $operator, 'col2' => $column2];
        return $this;
    }

    // ========================================================================
    // JSON FILTERS
    // ========================================================================

    /**
     * Filter where a JSON column contains a value.
     * Works with MySQL JSON_CONTAINS function.
     *
     *   ->whereJsonContains('tags', 'php')
     *   ->whereJsonContains('metadata', ['key' => 'value'])
     */
    public function whereJsonContains(string $field, mixed $value): static
    {
        $this->whereJsonContains[] = ['field' => $field, 'value' => $value];
        return $this;
    }

    // ========================================================================
    // LIKE FILTERS
    // ========================================================================

    /**
     * Filter where field matches a LIKE pattern.
     *
     *   ->whereLike('title', '%hello%')
     *   ->whereLike('email', '%@gmail.com')
     */
    public function whereLike(string $field, string $pattern): static
    {
        $this->whereLikes[$field] = $pattern;
        return $this;
    }

    /**
     * Filter where field does NOT match a LIKE pattern.
     *
     *   ->whereNotLike('title', '%spam%')
     */
    public function whereNotLike(string $field, string $pattern): static
    {
        $this->whereNotLikes[$field] = $pattern;
        return $this;
    }

    /**
     * Filter where field starts with a prefix.
     *
     *   ->whereStartsWith('slug', 'blog-')
     *   ->whereStartsWith('email', 'admin')
     */
    public function whereStartsWith(string $field, string $prefix): static
    {
        $escaped = addcslashes($prefix, '%_\\');
        $this->whereLikes[$field] = $escaped . '%';
        return $this;
    }

    /**
     * Filter where field ends with a suffix.
     * LIKE wildcards (% and _) in the suffix are escaped automatically.
     *
     *   ->whereEndsWith('email', '@gmail.com')
     *   ->whereEndsWith('slug', '-draft')
     */
    public function whereEndsWith(string $field, string $suffix): static
    {
        $escaped = addcslashes($suffix, '%_\\');
        $this->whereLikes[$field] = '%' . $escaped;
        return $this;
    }

    // ========================================================================
    // DATE FILTERS
    // ========================================================================

    /**
     * Filter by exact date (ignoring time portion).
     *
     *   ->whereDate('created_at', '2025-06-15')
     */
    public function whereDate(string $field, string $date): static
    {
        $this->whereDates[] = ['field' => $field, 'func' => 'DATE', 'value' => $date];
        return $this;
    }

    /**
     * Filter by year.
     *
     *   ->whereYear('created_at', 2025)
     */
    public function whereYear(string $field, int $year): static
    {
        $this->whereDates[] = ['field' => $field, 'func' => 'YEAR', 'value' => (string) $year];
        return $this;
    }

    /**
     * Filter by month (1-12).
     *
     *   ->whereMonth('created_at', 3)
     */
    public function whereMonth(string $field, int $month): static
    {
        $this->whereDates[] = ['field' => $field, 'func' => 'MONTH', 'value' => (string) $month];
        return $this;
    }

    /**
     * Filter by day of month (1-31).
     *
     *   ->whereDay('created_at', 15)
     */
    public function whereDay(string $field, int $day): static
    {
        $this->whereDates[] = ['field' => $field, 'func' => 'DAY', 'value' => (string) $day];
        return $this;
    }

    /**
     * Search across text fields.
     */
    public function search(string $term, ?array $fields = null): static
    {
        $this->searchTerm = $term;
        $this->searchFields = $fields;
        return $this;
    }

    // ========================================================================
    // SORTING & LIMITING
    // ========================================================================

    /**
     * Set sort order.
     */
    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $this->sortBy = $field;
        $this->sortDir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }

    /**
     * Shortcut: order by field descending (newest first).
     */
    public function latest(string $field = 'created_at'): static
    {
        return $this->orderBy($field, 'DESC');
    }

    /**
     * Shortcut: order by field ascending (oldest first).
     */
    public function oldest(string $field = 'created_at'): static
    {
        return $this->orderBy($field, 'ASC');
    }

    /**
     * Limit the number of results (for get(), not paginate()).
     */
    public function limit(int $n): static
    {
        $this->limitValue = max(1, $n);
        return $this;
    }

    /**
     * Skip the first N results.
     * Useful with limit() for manual pagination outside paginate().
     *
     *   Entry::collection('blog')->orderBy('id')->offset(20)->limit(10)->get()
     */
    public function offset(int $n): static
    {
        $this->offsetValue = max(0, $n);
        return $this;
    }

    /**
     * Add a secondary sort column.
     *
     *   ->orderBy('status', 'ASC')->thenBy('created_at', 'DESC')
     *   // → ORDER BY status ASC, created_at DESC
     */
    public function thenBy(string $field, string $direction = 'ASC'): static
    {
        $this->additionalSorts[] = [
            'field' => $field,
            'dir' => strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC',
        ];
        return $this;
    }

    /**
     * Shortcut: add a secondary sort descending.
     */
    public function thenByDesc(string $field): static
    {
        return $this->thenBy($field, 'DESC');
    }

    /**
     * Reset all sorting and optionally set a new sort.
     * Pass null to reset to default (id DESC).
     *
     *   $base = Entry::collection('blog')->orderBy('title');
     *   $reordered = (clone $base)->reorder('created_at', 'DESC')->get();
     *   $default = (clone $base)->reorder()->get(); // resets to id DESC
     */
    public function reorder(?string $field = null, string $direction = 'ASC'): static
    {
        $this->additionalSorts = [];

        if ($field === null) {
            $this->sortBy = 'id';
            $this->sortDir = 'DESC';
        } else {
            $this->sortBy = $field;
            $this->sortDir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        }

        return $this;
    }

    // ========================================================================
    // SELECT FIELDS
    // ========================================================================

    /**
     * Select specific columns instead of SELECT *.
     *
     *   ->select('id', 'title', 'status')->get()
     *   ->select('id', 'COUNT(*) as total')->groupBy('status')->get()
     */
    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns;
        return $this;
    }

    /**
     * Select specific fields, automatically including 'id'.
     * Prevents accidentally dropping the ID column.
     *
     *   ->onlyFields('title', 'status', 'created_at')->get()
     *   // → SELECT id, title, status, created_at
     */
    public function onlyFields(string ...$fields): static
    {
        $columns = array_unique(array_merge(['id'], $fields));
        $this->selectColumns = $columns;
        return $this;
    }

    /**
     * Enable SELECT DISTINCT to remove duplicate rows.
     *
     *   Entry::collection('blog')->select('category')->distinct()->get()
     */
    public function distinct(): static
    {
        $this->distinctMode = true;
        return $this;
    }

    // ========================================================================
    // GROUP BY / HAVING
    // ========================================================================

    /**
     * Group results by one or more columns.
     *
     *   ->select('status', 'COUNT(*) as total')->groupBy('status')->get()
     */
    public function groupBy(string ...$columns): static
    {
        $this->groupByColumns = array_merge($this->groupByColumns, $columns);
        return $this;
    }

    /**
     * Add a HAVING condition (used with groupBy).
     *
     *   ->groupBy('status')->having('COUNT(*)', '>', 5)->get()
     */
    public function having(string $expression, string $operator, mixed $value): static
    {
        $allowedOperators = ['>', '<', '>=', '<=', '=', '!=', '<>'];
        if (!in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException("Invalid HAVING operator: {$operator}");
        }
        $this->havingConditions[] = ['expr' => $expression, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    // ========================================================================
    // RAW EXPRESSIONS
    // ========================================================================

    /**
     * Add a raw WHERE expression with parameter bindings.
     * Use for edge cases not covered by other methods.
     *
     *   ->whereRaw('CHAR_LENGTH(`title`) > :minLen', ['minLen' => 10])
     *   ->whereRaw('`price` * `quantity` > :threshold', ['threshold' => 100])
     *
     * WARNING: The expression is injected directly — never pass user input as the expression.
     * Only values should come from user input (via the $bindings parameter).
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->rawConditions[] = ['expr' => $expression, 'bindings' => $bindings];
        return $this;
    }

    // ========================================================================
    // SCOPES (reusable named filter presets)
    // ========================================================================

    /**
     * Register a global scope for a collection.
     *
     *   EntryQuery::addScope('published', function(EntryQuery $q) {
     *       $q->where('status', 'published')->whereNotNull('published_at');
     *   });
     *
     * Then use:
     *   Entry::collection('blog')->scope('published')->get()
     */
    public static function addScope(string $name, \Closure $callback): void
    {
        static::$scopes[$name] = $callback;
    }

    /**
     * Apply a registered scope.
     *
     *   ->scope('published')
     *   ->scope('recent')
     */
    public function scope(string $name): static
    {
        if (isset(static::$scopes[$name])) {
            (static::$scopes[$name])($this);
        }
        return $this;
    }

    /**
     * Apply an inline scope (one-off closure without registering).
     *
     *   ->tap(function($q) {
     *       if ($onlyFeatured) $q->where('featured', 1);
     *   })
     */
    public function tap(\Closure $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * Magic method: call registered scopes as methods.
     *
     *   EntryQuery::addScope('published', fn($q) => $q->where('status', 'published'));
     *   Entry::collection('blog')->published()->get()
     */
    public function __call(string $name, array $arguments): static
    {
        if (isset(static::$scopes[$name])) {
            (static::$scopes[$name])($this, ...$arguments);
            return $this;
        }
        throw new \BadMethodCallException("Method {$name}() does not exist on EntryQuery.");
    }

    // ========================================================================
    // RELATION LOADING
    // ========================================================================

    /**
     * Eager-load specific relations by field slug.
     * Supports dot notation for nested relations: with('author.role')
     *
     *   ->with('author', 'categories')
     *   ->with('author.role')
     */
    public function with(string ...$relations): static
    {
        $this->withRelations = array_merge($this->withRelations, $relations);
        return $this;
    }

    /**
     * Load ALL relations up to a given depth.
     * Less efficient than with() — resolves every relation field.
     */
    public function withRelations(int $depth = 1): static
    {
        $this->resolveAll = true;
        $this->resolveDepth = min(max($depth, 0), 3);
        return $this;
    }

    /**
     * Remove specific relations from the eager-load list.
     * Useful when branching a base query that already has with().
     *
     *   $base = Entry::collection('blog')->with('author', 'categories', 'tags');
     *   $light = (clone $base)->without('tags')->get();
     */
    public function without(string ...$relations): static
    {
        $this->withRelations = array_values(
            array_diff($this->withRelations, $relations)
        );
        return $this;
    }

    // ========================================================================
    // LOCALE
    // ========================================================================

    /**
     * Apply locale translations to results.
     */
    public function locale(string $locale): static
    {
        $this->localeValue = $locale;
        return $this;
    }

    // ========================================================================
    // CACHE CONTROL
    // ========================================================================

    /**
     * Skip cache for this query.
     */
    public function noCache(): static
    {
        $this->useCache = false;
        return $this;
    }

    // ========================================================================
    // READ OPERATIONS
    // ========================================================================

    /**
     * Get all matching entries (respects limit, no pagination metadata).
     *
     * @return array<int, array> List of entry arrays
     */
    public function get(): array
    {
        $result = $this->executeList($this->limitValue ?? 100, 1);
        return $result['data'];
    }

    /**
     * Get paginated results with rich metadata.
     *
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null, has_more_pages: bool}
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        $result = $this->executeList($perPage, $page);

        $total = $result['total'];
        $lastPage = $result['last_page'];
        $count = count($result['data']);

        $result['from'] = $count > 0 ? (($page - 1) * $perPage) + 1 : null;
        $result['to'] = $count > 0 ? ($result['from'] + $count - 1) : null;
        $result['has_more_pages'] = $page < $lastPage;

        return $result;
    }

    /**
     * Find a single entry by ID.
     */
    public function find(int|string $id): ?array
    {
        $this->boot();
        if (!$this->tableName) {
            return null;
        }

        $cacheKey = null;
        if ($this->useCache) {
            $cached = $this->cacheGet('cms.entry.' . $this->collectionSlug . '.' . $id);
            if ($cached !== null) {
                return $cached;
            }
            $cacheKey = 'cms.entry.' . $this->collectionSlug . '.' . $id;
        }

        $entry = $this->schema->findEntry($this->tableName, $id);
        if (!$entry) {
            return null;
        }

        $entry = $this->resolveEntryRelations([$entry]);
        $entry = $entry[0] ?? null;

        if ($entry) {
            $entry = $this->applyLocale([$entry])[0];
        }

        if ($cacheKey && $entry) {
            $this->cacheSet($cacheKey, $entry, 60);
        }

        return $entry;
    }

    /**
     * Find a single entry by its slug column.
     */
    public function findBySlug(string $slug): ?array
    {
        $this->boot();
        if (!$this->tableName) {
            return null;
        }

        $conn = $this->schema->getConnection();
        $entry = $conn->createQueryBuilder()
            ->select('*')
            ->from($this->tableName)
            ->where('slug = :slug')
            ->setParameter('slug', $slug)
            ->executeQuery()
            ->fetchAssociative();

        if (!$entry) {
            return null;
        }

        $entry = $this->resolveEntryRelations([$entry]);
        $entry = $entry[0] ?? null;

        if ($entry) {
            $entry = $this->applyLocale([$entry])[0];
        }

        return $entry;
    }

    /**
     * Get entries as a nested tree structure (for collections with hierarchy).
     * Each entry will have a 'children' key containing its child entries.
     *
     * Usage in Twig: {% set categories = entry_query('categories').tree() %}
     *
     * @return array Nested tree array
     */
    public function tree(): array
    {
        $entries = $this->orderBy('parent_id')->thenBy('id')->get();
        return $this->buildTreeStructure($entries);
    }

    /**
     * Build nested tree structure from a flat list of entries.
     */
    private function buildTreeStructure(array $entries, $parentId = null): array
    {
        $tree = [];
        foreach ($entries as $entry) {
            $pid = $entry['parent_id'] ?? null;
            if ($pid === '' || $pid === '0' || $pid === 0) {
                $pid = null;
            }
            if ($pid == $parentId) {
                $entry['children'] = $this->buildTreeStructure($entries, $entry['id']);
                $tree[] = $entry;
            }
        }
        return $tree;
    }

    /**
     * Get the first matching entry.
     */
    public function first(): ?array
    {
        $result = $this->executeList(1, 1);
        return $result['data'][0] ?? null;
    }

    /**
     * Get a single column's value from the first matching entry.
     *
     *   $title = Entry::collection('blog')->where('id', 42)->value('title');
     *   // → 'My Blog Post'
     */
    public function value(string $column): mixed
    {
        $savedSelect = $this->selectColumns;
        $this->selectColumns = [$column];

        $result = $this->executeList(1, 1);

        $this->selectColumns = $savedSelect;

        return $result['data'][0][$column] ?? null;
    }

    /**
     * Get results as a plain array (alias for get(), useful for chaining intent).
     *
     *   $data = Entry::collection('blog')->where('status', 'published')->toArray();
     */
    public function toArray(): array
    {
        return $this->get();
    }

    /**
     * Get results as a JSON string.
     *
     *   $json = Entry::collection('blog')->where('status', 'published')->toJson();
     *   $json = Entry::collection('blog')->limit(5)->toJson(JSON_PRETTY_PRINT);
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->get(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Count matching entries.
     */
    public function count(): int
    {
        if (empty($this->wheres) && empty($this->conditionGroups) && empty($this->whereLikes)
            && empty($this->whereDates) && empty($this->rawConditions) && empty($this->whereCompares)
            && empty($this->whereColumns) && empty($this->whereJsonContains)
            && empty($this->whereNotIns) && empty($this->whereNotBetweens)
            && $this->searchTerm === null
            && !$this->onlyTrashed && !$this->includeTrashed) {
            $this->boot();
            if (!$this->tableName) {
                return 0;
            }
            return $this->schema->countEntries($this->tableName);
        }

        // With filters (including soft-delete flags), use the list query but only get total
        $result = $this->executeList(1, 1);
        return $result['total'];
    }

    // ========================================================================
    // ITERATION (memory-efficient)
    // ========================================================================

    /**
     * Process results in chunks to limit memory usage.
     * The callback receives each chunk (array of entries).
     * Return false from the callback to stop iteration.
     *
     *   Entry::collection('blog')->where('status', 'published')->chunk(100, function(array $entries) {
     *       foreach ($entries as $entry) { processEntry($entry); }
     *   });
     */
    public function chunk(int $size, \Closure $callback): void
    {
        $page = 1;

        do {
            $result = $this->executeList($size, $page);
            $entries = $result['data'];

            if (empty($entries)) {
                break;
            }

            if ($callback($entries) === false) {
                break;
            }

            $page++;
        } while ($page <= $result['last_page']);
    }

    /**
     * Iterate over each entry one at a time (chunked internally for efficiency).
     * Return false from the callback to stop iteration.
     *
     *   Entry::collection('blog')->each(function(array $entry) {
     *       // process single entry
     *   });
     */
    public function each(\Closure $callback, int $chunkSize = 100): void
    {
        $this->chunk($chunkSize, function (array $entries) use ($callback) {
            foreach ($entries as $entry) {
                if ($callback($entry) === false) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Lazily iterate over all matching entries using a Generator.
     * Memory-efficient — only one chunk is loaded at a time.
     *
     *   foreach (Entry::collection('orders')->where('status', 'paid')->lazy() as $order) {
     *       processOrder($order);
     *   }
     *
     *   // Collect into array (same as get() but chunked internally)
     *   $entries = iterator_to_array(Entry::collection('blog')->lazy(200));
     *
     * @return \Generator<int, array>
     */
    public function lazy(int $chunkSize = 100): \Generator
    {
        $page = 1;

        do {
            $result = $this->executeList($chunkSize, $page);
            $entries = $result['data'];

            if (empty($entries)) {
                break;
            }

            foreach ($entries as $entry) {
                yield $entry;
            }

            $page++;
        } while ($page <= $result['last_page']);
    }

    // ========================================================================
    // COLUMN EXTRACTION
    // ========================================================================

    /**
     * Get an array of values for a single column, optionally keyed by another column.
     *
     *   Entry::collection('blog')->pluck('title')
     *   // → ['First Post', 'Second Post', ...]
     *
     *   Entry::collection('blog')->pluck('title', 'id')
     *   // → [1 => 'First Post', 2 => 'Second Post', ...]
     */
    public function pluck(string $column, ?string $keyColumn = null): array
    {
        $savedSelect = $this->selectColumns;
        $this->selectColumns = $keyColumn ? [$column, $keyColumn] : [$column];

        $result = $this->executeList($this->limitValue ?? 100000, 1);

        $this->selectColumns = $savedSelect;

        $values = [];
        foreach ($result['data'] as $row) {
            if ($keyColumn !== null && isset($row[$keyColumn])) {
                $values[$row[$keyColumn]] = $row[$column] ?? null;
            } else {
                $values[] = $row[$column] ?? null;
            }
        }

        return $values;
    }

    // ========================================================================
    // BOOLEAN CHECKS
    // ========================================================================

    /**
     * Check if any entries match the current query.
     *
     *   if (Entry::collection('blog')->where('status', 'published')->exists()) { ... }
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if NO entries match the current query.
     *
     *   if (Entry::collection('blog')->where('slug', 'my-post')->doesntExist()) { ... }
     */
    public function doesntExist(): bool
    {
        return $this->count() === 0;
    }

    // ========================================================================
    // STRICT FINDERS (throw on not found)
    // ========================================================================

    /**
     * Find an entry by ID or throw an exception.
     *
     *   $post = Entry::collection('blog')->findOrFail(42);
     *
     * @throws \RuntimeException When no entry is found
     */
    public function findOrFail(int|string $id): array
    {
        $entry = $this->find($id);

        if ($entry === null) {
            throw new \RuntimeException(
                "Entry not found: collection '{$this->collectionSlug}', id '{$id}'."
            );
        }

        return $entry;
    }

    /**
     * Get the first matching entry or throw an exception.
     *
     *   $post = Entry::collection('blog')->where('slug', 'hello')->firstOrFail();
     *
     * @throws \RuntimeException When no entry matches
     */
    public function firstOrFail(): array
    {
        $entry = $this->first();

        if ($entry === null) {
            throw new \RuntimeException(
                "No matching entry found in collection '{$this->collectionSlug}'."
            );
        }

        return $entry;
    }

    /**
     * Get exactly one matching entry. Throws if zero or more than one match.
     * Ideal for unique lookups (settings by key, page by slug).
     *
     *   $setting = Entry::collection('settings')->where('key', 'site_name')->sole();
     *   $page = Entry::collection('pages')->where('slug', 'about')->sole();
     *
     * @throws \RuntimeException When zero or multiple entries match
     */
    public function sole(): array
    {
        $result = $this->executeList(2, 1);
        $count = count($result['data']);

        if ($count === 0) {
            throw new \RuntimeException(
                "No matching entry found in collection '{$this->collectionSlug}'."
            );
        }

        if ($count > 1) {
            throw new \RuntimeException(
                "Expected exactly one entry in collection '{$this->collectionSlug}', found {$result['total']}."
            );
        }

        return $result['data'][0];
    }

    // ========================================================================
    // MULTI-ID LOOKUP
    // ========================================================================

    /**
     * Find multiple entries by an array of IDs.
     *
     *   $posts = Entry::collection('blog')->findMany([1, 2, 3]);
     *   // → [['id' => 1, ...], ['id' => 2, ...], ['id' => 3, ...]]
     */
    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->whereIn('id', $ids)->get();
    }

    // ========================================================================
    // CONDITIONAL FILTERS
    // ========================================================================

    /**
     * Apply filters conditionally — avoids wrapping in if/else.
     *
     *   Entry::collection('blog')
     *       ->when($request->has('status'), function($q) use ($request) {
     *           $q->where('status', $request->get('status'));
     *       })
     *       ->get()
     */
    public function when(mixed $condition, \Closure $callback, ?\Closure $fallback = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($fallback !== null) {
            $fallback($this, $condition);
        }

        return $this;
    }

    /**
     * Apply filters when condition is falsy (inverse of when).
     *
     *   Entry::collection('blog')
     *       ->unless($isAdmin, function($q) {
     *           $q->where('status', 'published');
     *       })
     *       ->get()
     */
    public function unless(mixed $condition, \Closure $callback, ?\Closure $fallback = null): static
    {
        return $this->when(!$condition, $callback, $fallback);
    }

    // ========================================================================
    // QUERY CLONING & REUSE
    // ========================================================================

    /**
     * Clone the current query to branch from the same state.
     *
     *   $base = Entry::collection('blog')->where('status', 'published');
     *   $recent = (clone $base)->latest()->limit(5)->get();
     *   $count  = (clone $base)->count();
     */
    public function __clone()
    {
        // Deep clone any object references to prevent shared state
        // (All current state is arrays/scalars, so PHP's default clone is sufficient.
        //  This method exists for forward compatibility if object properties are added.)
    }

    /**
     * Get a fresh query instance for the same collection.
     *
     *   $fresh = $query->newQuery();
     */
    public function newQuery(): static
    {
        return new static($this->collectionSlug);
    }

    // ========================================================================
    // RANDOM ORDER
    // ========================================================================

    /**
     * Order results randomly.
     *
     *   Entry::collection('quotes')->inRandomOrder()->limit(1)->first()
     */
    public function inRandomOrder(): static
    {
        $this->sortBy = 'RAND()';
        $this->sortDir = 'ASC';
        return $this;
    }

    // ========================================================================
    // AGGREGATE METHODS
    // ========================================================================

    /**
     * Get the sum of a column.
     *
     *   Entry::collection('orders')->where('status', 'paid')->sum('total')
     */
    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    /**
     * Get the average of a column.
     *
     *   Entry::collection('products')->avg('price')
     */
    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    /**
     * Get the minimum value of a column.
     *
     *   Entry::collection('products')->min('price')
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Get the maximum value of a column.
     *
     *   Entry::collection('products')->max('price')
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Execute an aggregate function on a column.
     */
    private function aggregate(string $function, string $column): mixed
    {
        $this->boot();
        if (!$this->tableName) {
            return null;
        }

        // Validate column name to prevent SQL injection via backtick breakout
        if ($column !== '*' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name for aggregate: {$column}");
        }

        $savedSelect = $this->selectColumns;
        $this->selectColumns = ["{$function}(`{$column}`) as agg_result"];

        $options = $this->buildListOptions(1, 1);

        $this->selectColumns = $savedSelect;

        $result = $this->schema->aggregateQuery($this->tableName, $options);
        return $result;
    }

    // ========================================================================
    // SOFT DELETES
    // ========================================================================

    /**
     * Include soft-deleted entries in results.
     * Only applies to collections that have a `deleted_at` column.
     *
     *   Entry::collection('blog')->withTrashed()->get()
     */
    public function withTrashed(): static
    {
        $this->includeTrashed = true;
        return $this;
    }

    /**
     * Only return soft-deleted entries.
     *
     *   Entry::collection('blog')->onlyTrashed()->get()
     */
    public function onlyTrashed(): static
    {
        $this->onlyTrashed = true;
        $this->includeTrashed = true;
        return $this;
    }

    /**
     * Soft-delete an entry (set deleted_at instead of removing).
     * Falls back to hard delete if the table has no deleted_at column.
     *
     *   Entry::collection('blog')->softDelete(42)
     */
    public function softDelete(int|string $id): void
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        if ($this->hasSoftDeleteColumn()) {
            $this->schema->updateEntry($this->tableName, $id, [
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->schema->deleteEntry($this->tableName, $id);
        }
        cms_invalidate_cache($this->collectionSlug);
    }

    /**
     * Restore a soft-deleted entry.
     *
     *   Entry::collection('blog')->restore(42)
     */
    public function restore(int|string $id): void
    {
        $this->boot();
        if (!$this->tableName || !$this->hasSoftDeleteColumn()) {
            return;
        }

        $this->schema->updateEntry($this->tableName, $id, ['deleted_at' => null]);
        cms_invalidate_cache($this->collectionSlug);
    }

    /**
     * Check if the collection table has a deleted_at column.
     */
    private function hasSoftDeleteColumn(): bool
    {
        $this->boot();
        if (!$this->tableName) {
            return false;
        }

        try {
            $columns = $this->schema->getConnection()
                ->createSchemaManager()
                ->listTableColumns($this->tableName);
            return isset($columns['deleted_at']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ========================================================================
    // BULK OPERATIONS
    // ========================================================================

    /**
     * Update all entries matching current filters.
     * Returns the number of affected rows.
     *
     *   Entry::collection('blog')
     *       ->where('status', 'draft')
     *       ->whereBetween('created_at', '2024-01-01', '2024-06-30')
     *       ->updateWhere(['status' => 'archived'])
     */
    public function updateWhere(array $data): int
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        $options = $this->buildListOptions(100000, 1);
        $affected = $this->schema->bulkUpdate($this->tableName, $data, $options);
        cms_invalidate_cache($this->collectionSlug);
        return $affected;
    }

    /**
     * Delete all entries matching current filters.
     * Returns the number of deleted rows.
     *
     *   Entry::collection('blog')
     *       ->where('status', 'draft')
     *       ->whereNull('title')
     *       ->deleteWhere()
     */
    public function deleteWhere(): int
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        if (empty($this->wheres) && empty($this->whereIns) && empty($this->whereNotIns)
            && empty($this->whereNots) && empty($this->whereBetweens) && empty($this->whereNotBetweens)
            && empty($this->whereNulls) && empty($this->whereNotNulls)
            && empty($this->whereLikes) && empty($this->conditionGroups) && empty($this->rawConditions)
            && empty($this->whereCompares) && empty($this->whereColumns) && empty($this->whereJsonContains)) {
            throw new \RuntimeException('deleteWhere() requires at least one filter to prevent accidental full table deletion.');
        }

        $options = $this->buildListOptions(100000, 1);
        $affected = $this->schema->bulkDelete($this->tableName, $options);
        cms_invalidate_cache($this->collectionSlug);
        return $affected;
    }

    // ========================================================================
    // QUERY DEBUGGING
    // ========================================================================

    /**
     * Get the SQL that would be executed (without actually running it).
     * Useful for debugging.
     *
     *   $sql = Entry::collection('blog')->where('status', 'published')->toSql();
     *   // → "SELECT * FROM cms_blog WHERE `status` = 'published' ORDER BY `id` DESC LIMIT 100"
     */
    public function toSql(): string
    {
        $this->boot();
        if (!$this->tableName) {
            return '-- Collection not found';
        }

        $options = $this->buildListOptions($this->limitValue ?? 100, 1);
        return $this->schema->buildSql($this->tableName, $options);
    }

    /**
     * Dump the current query state and SQL for debugging, then continue the chain.
     *
     *   Entry::collection('blog')->where('status', 'published')->dump()->limit(5)->get()
     */
    public function dump(): static
    {
        // Block in production to prevent information disclosure
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
        if ($env === 'production') {
            return $this;
        }

        $state = [
            'collection' => $this->collectionSlug,
            'wheres' => $this->wheres,
            'whereIns' => $this->whereIns,
            'whereNotIns' => $this->whereNotIns,
            'whereNots' => $this->whereNots,
            'whereBetweens' => $this->whereBetweens,
            'whereNotBetweens' => $this->whereNotBetweens,
            'whereNulls' => $this->whereNulls,
            'whereNotNulls' => $this->whereNotNulls,
            'whereLikes' => $this->whereLikes,
            'whereNotLikes' => $this->whereNotLikes,
            'whereDates' => $this->whereDates,
            'whereCompares' => $this->whereCompares,
            'whereColumns' => $this->whereColumns,
            'whereJsonContains' => $this->whereJsonContains,
            'conditionGroups' => count($this->conditionGroups),
            'rawConditions' => count($this->rawConditions),
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'additionalSorts' => $this->additionalSorts,
            'limit' => $this->limitValue,
            'offset' => $this->offsetValue,
            'distinct' => $this->distinctMode,
            'select' => $this->selectColumns,
            'groupBy' => $this->groupByColumns,
            'search' => $this->searchTerm,
            'locale' => $this->localeValue,
            'cache' => $this->useCache,
        ];

        // Remove empty/null/false values for cleaner output
        $state = array_filter($state, fn($v) => !empty($v) && $v !== false);

        echo "=== EntryQuery Debug ===\n";
        echo "SQL: " . $this->toSql() . "\n";
        echo "State: " . json_encode($state, JSON_PRETTY_PRINT) . "\n";
        echo "========================\n";

        return $this;
    }

    /**
     * Dump the current query state and SQL, then halt execution.
     *
     *   Entry::collection('blog')->where('status', 'published')->dd();
     */
    public function dd(): void
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
        if ($env === 'production') {
            return;
        }

        $this->dump();
        exit(1);
    }

    // ========================================================================
    // WRITE OPERATIONS
    // ========================================================================

    /**
     * Create a new entry. Returns the new entry ID.
     *
     * Array values for many-to-many relation fields are automatically synced to pivot tables.
     *
     *   Entry::collection('blog')->create([
     *       'title' => 'Hello',
     *       'author' => 5,              // one-to-one: FK ID
     *       'categories' => [1, 3, 7],  // many-to-many: auto-syncs pivot
     *   ]);
     */
    public function create(array $data): int|string
    {
        $this->boot();
        if (!$this->tableName || !$this->collection) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        [$entryData, $pivotData] = $this->separateRelationData($data);

        $entryId = $this->schema->insertEntry($this->tableName, $entryData, $this->collection->isUuid());

        // Sync many-to-many pivot tables
        $this->syncPivots($entryId, $pivotData);

        // Invalidate cache
        cms_invalidate_cache($this->collectionSlug);

        return $entryId;
    }

    /**
     * Create multiple entries in a single batch. Returns array of new IDs.
     *
     *   $ids = Entry::collection('blog')->createMany([
     *       ['title' => 'Post 1', 'status' => 'draft'],
     *       ['title' => 'Post 2', 'status' => 'published'],
     *   ]);
     *   // → [101, 102]
     *
     * Relation pivot data in each entry is synced automatically.
     */
    public function createMany(array $entries): array
    {
        $this->boot();
        if (!$this->tableName || !$this->collection) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        if (empty($entries)) {
            return [];
        }

        $ids = [];
        $isUuid = $this->collection->isUuid();

        foreach ($entries as $data) {
            if (!is_array($data)) {
                continue;
            }

            [$entryData, $pivotData] = $this->separateRelationData($data);

            $entryId = $this->schema->insertEntry($this->tableName, $entryData, $isUuid);

            $this->syncPivots($entryId, $pivotData);

            $ids[] = $entryId;
        }

        cms_invalidate_cache($this->collectionSlug);

        return $ids;
    }

    /**
     * Update an existing entry by ID.
     *
     * Array values for many-to-many relation fields are automatically re-synced.
     */
    public function update(int|string $id, array $data): void
    {
        $this->boot();
        if (!$this->tableName || !$this->collection) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        [$entryData, $pivotData] = $this->separateRelationData($data);

        if (!empty($entryData)) {
            $this->schema->updateEntry($this->tableName, $id, $entryData);
        }

        // Sync many-to-many pivot tables
        $this->syncPivots($id, $pivotData);

        // Invalidate cache
        cms_invalidate_cache($this->collectionSlug);
    }

    /**
     * Delete an entry by ID.
     * Uses soft delete (sets deleted_at) when the column exists, otherwise hard deletes.
     */
    public function delete(int|string $id): void
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        if ($this->hasSoftDeleteColumn()) {
            $this->schema->softDeleteEntry($this->tableName, $id);
        } else {
            $this->schema->deleteEntry($this->tableName, $id);
        }
        cms_invalidate_cache($this->collectionSlug);
    }

    /**
     * Permanently delete an entry by ID (bypasses soft delete).
     */
    public function forceDelete(int|string $id): void
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        $this->schema->forceDeleteEntry($this->tableName, $id);
        cms_invalidate_cache($this->collectionSlug);
    }

    // ========================================================================
    // ATOMIC COUNTER OPERATIONS
    // ========================================================================

    /**
     * Atomically increment a numeric column on an entry.
     *
     *   Entry::collection('blog')->increment(42, 'views');
     *   Entry::collection('blog')->increment(42, 'views', 5);
     */
    public function increment(int|string $id, string $column, int $amount = 1): void
    {
        $this->boot();
        if (!$this->tableName) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        $this->schema->incrementColumn($this->tableName, $id, $column, $amount);
        cms_invalidate_cache($this->collectionSlug);
    }

    /**
     * Atomically decrement a numeric column on an entry.
     *
     *   Entry::collection('products')->decrement(15, 'stock');
     *   Entry::collection('products')->decrement(15, 'stock', 3);
     */
    public function decrement(int|string $id, string $column, int $amount = 1): void
    {
        $this->increment($id, $column, -$amount);
    }

    // ========================================================================
    // UPSERT (INSERT OR UPDATE)
    // ========================================================================

    /**
     * Insert a new entry or update if a matching entry exists (by unique key).
     * Returns the entry ID (new or existing).
     *
     *   Entry::collection('settings')->upsert(
     *       ['key' => 'site_name'],           // match criteria
     *       ['value' => 'My Site']             // data to update/insert
     *   );
     */
    public function upsert(array $match, array $data): int|string
    {
        $this->boot();
        if (!$this->tableName || !$this->collection) {
            throw new \RuntimeException("Collection '{$this->collectionSlug}' not found.");
        }

        $result = $this->schema->upsertEntry(
            $this->tableName,
            $match,
            $data,
            $this->collection->isUuid()
        );

        cms_invalidate_cache($this->collectionSlug);
        return $result;
    }

    // ========================================================================
    // FIRST OR CREATE
    // ========================================================================

    /**
     * Find the first entry matching criteria, or create it if it doesn't exist.
     * Returns the full entry array (existing or newly created).
     *
     *   $page = Entry::collection('pages')->firstOrCreate(
     *       ['slug' => 'about'],                      // match criteria
     *       ['title' => 'About Us', 'status' => 'draft']  // extra data for creation
     *   );
     *
     *   // Settings pattern — ensure a default exists
     *   $setting = Entry::collection('settings')->firstOrCreate(
     *       ['key' => 'theme'],
     *       ['value' => 'default']
     *   );
     */
    public function firstOrCreate(array $match, array $data = []): array
    {
        // Try to find existing
        $query = $this->newQuery();
        foreach ($match as $field => $value) {
            $query->where($field, $value);
        }
        $existing = $query->first();

        if ($existing !== null) {
            return $existing;
        }

        // Create new entry with match + data merged
        $createData = array_merge($match, $data);
        $id = $this->create($createData);

        return $this->newQuery()->noCache()->find($id);
    }

    // ========================================================================
    // INTERNAL: BOOT / RESOLVE
    // ========================================================================

    /**
     * Lazy-load the Collection model, SchemaManager, table name, and fields.
     */
    private function boot(): void
    {
        if ($this->schema !== null) {
            return;
        }

        $this->schema = new SchemaManager();
        $this->collection = Collection::findOneBy(['slug' => $this->collectionSlug]);

        if ($this->collection) {
            $this->tableName = $this->collection->getTableName();
            if (!$this->schema->tableExists($this->tableName)) {
                $this->tableName = null;
            }
        }
    }

    /**
     * Get field definitions (lazy-loaded).
     */
    private function getFields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $this->boot();
        $this->fields = $this->collection ? $this->collection->getFields()->toArray() : [];
        return $this->fields;
    }

    // ========================================================================
    // INTERNAL: LIST QUERY EXECUTION
    // ========================================================================

    /**
     * Build the options array from current query state for SchemaManager.
     */
    private function buildListOptions(int $perPage, int $page): array
    {
        $options = [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $this->sortBy,
            'sort_dir' => $this->sortDir,
        ];

        if (!empty($this->wheres)) {
            $options['filters'] = $this->wheres;
        }
        if (!empty($this->whereIns)) {
            $options['filters_in'] = $this->whereIns;
        }
        if (!empty($this->whereNotIns)) {
            $options['filters_not_in'] = $this->whereNotIns;
        }
        if (!empty($this->whereNots)) {
            $options['filters_not'] = $this->whereNots;
        }
        if (!empty($this->whereBetweens)) {
            $options['filters_between'] = $this->whereBetweens;
        }
        if (!empty($this->whereNotBetweens)) {
            $options['filters_not_between'] = $this->whereNotBetweens;
        }
        if (!empty($this->whereNulls)) {
            $options['filters_null'] = $this->whereNulls;
        }
        if (!empty($this->whereNotNulls)) {
            $options['filters_not_null'] = $this->whereNotNulls;
        }

        // LIKE filters
        if (!empty($this->whereLikes)) {
            $options['filters_like'] = $this->whereLikes;
        }
        if (!empty($this->whereNotLikes)) {
            $options['filters_not_like'] = $this->whereNotLikes;
        }

        // Date filters
        if (!empty($this->whereDates)) {
            $options['filters_date'] = $this->whereDates;
        }

        // Compound AND/OR condition groups
        if (!empty($this->conditionGroups)) {
            $options['condition_groups'] = $this->serializeConditionGroups();
        }

        // Select specific columns
        if (!empty($this->selectColumns)) {
            $options['select'] = $this->selectColumns;
        }

        // Group by
        if (!empty($this->groupByColumns)) {
            $options['group_by'] = $this->groupByColumns;
        }

        // Having
        if (!empty($this->havingConditions)) {
            $options['having'] = $this->havingConditions;
        }

        // Raw conditions
        if (!empty($this->rawConditions)) {
            $options['raw_conditions'] = $this->rawConditions;
        }

        // Comparison filters (>, <, >=, <=)
        if (!empty($this->whereCompares)) {
            $options['filters_compare'] = $this->whereCompares;
        }

        // Column comparisons
        if (!empty($this->whereColumns)) {
            $options['filters_column'] = $this->whereColumns;
        }

        // JSON contains filters
        if (!empty($this->whereJsonContains)) {
            $options['filters_json_contains'] = $this->whereJsonContains;
        }

        // Additional sort columns
        if (!empty($this->additionalSorts)) {
            $options['additional_sorts'] = $this->additionalSorts;
        }

        // Offset (skip N results)
        if ($this->offsetValue !== null) {
            $options['offset'] = $this->offsetValue;
        }

        // DISTINCT
        if ($this->distinctMode) {
            $options['distinct'] = true;
        }

        // Soft delete handling
        if ($this->onlyTrashed) {
            $options['filters_not_null'] = array_merge($options['filters_not_null'] ?? [], ['deleted_at']);
        } elseif (!$this->includeTrashed) {
            // By default, exclude soft-deleted entries
            $options['filters_null'] = array_merge($options['filters_null'] ?? [], ['deleted_at']);
            $options['_soft_delete_auto'] = true; // flag for SchemaManager to skip if column doesn't exist
        }

        if ($this->searchTerm !== null) {
            $options['search'] = $this->searchTerm;

            if ($this->searchFields !== null) {
                $options['searchFields'] = $this->searchFields;
            } else {
                // Auto-populate from searchable fields
                $fields = $this->getFields();
                $searchableTypes = ['text', 'textarea', 'richtext', 'email', 'url', 'slug'];
                $autoFields = [];
                foreach ($fields as $field) {
                    if (in_array($field->getType(), $searchableTypes)) {
                        $autoFields[] = $field->getSlug();
                    }
                }
                if (!in_array('slug', $autoFields)) {
                    $autoFields[] = 'slug';
                }
                $options['searchFields'] = $autoFields;
            }
        }

        return $options;
    }

    /**
     * Execute the list query with all accumulated filters, search, sort, and pagination.
     */
    private function executeList(int $perPage, int $page): array
    {
        $emptyResult = ['data' => [], 'total' => 0, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => 1];

        $this->boot();
        if (!$this->tableName) {
            return $emptyResult;
        }

        // Build cache key from query state (skip cache for random ordering)
        if ($this->useCache && $this->sortBy !== 'RAND()') {
            $cacheKey = $this->buildListCacheKey($perPage, $page);
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $options = $this->buildListOptions($perPage, $page);
        $result = $this->schema->listEntries($this->tableName, $options);

        // Resolve relations
        if (!empty($result['data'])) {
            $result['data'] = $this->resolveEntryRelations($result['data']);
        }

        // Apply locale
        if (!empty($result['data'])) {
            $result['data'] = $this->applyLocale($result['data']);
        }

        // Cache
        if ($this->useCache && isset($cacheKey)) {
            $this->cacheSet($cacheKey, $result, 60);
        }

        return $result;
    }

    private function buildListCacheKey(int $perPage, int $page): string
    {
        $parts = [
            'cms.q',
            $this->collectionSlug,
            'p' . $page,
            'pp' . $perPage,
            's' . $this->sortBy . $this->sortDir,
        ];

        $filterState = [
            $this->wheres,
            $this->whereIns,
            $this->whereNotIns,
            $this->whereNots,
            $this->whereBetweens,
            $this->whereNotBetweens,
            $this->whereNulls,
            $this->whereNotNulls,
            $this->whereLikes,
            $this->whereNotLikes,
            $this->whereDates,
            !empty($this->conditionGroups) ? $this->serializeConditionGroups() : [],
            $this->selectColumns,
            $this->groupByColumns,
            $this->havingConditions,
            $this->rawConditions,
            $this->whereCompares,
            $this->whereColumns,
            $this->whereJsonContains,
            $this->additionalSorts,
            $this->offsetValue,
            $this->distinctMode,
        ];
        $emptyState = [[], [], [], [], [], [], [], [], [], [], [], [], [], [], [], [], [], [], [], [], null, false];
        $filterHash = md5(serialize($filterState));
        if ($filterHash !== md5(serialize($emptyState))) {
            $parts[] = 'f' . $filterHash;
        }
        if ($this->searchTerm !== null) {
            $parts[] = 'q' . md5($this->searchTerm);
        }
        if ($this->localeValue) {
            $parts[] = 'l' . md5($this->localeValue);
        }

        return implode('.', $parts);
    }

    // ========================================================================
    // INTERNAL: CONDITION GROUP SERIALIZATION
    // ========================================================================

    /**
     * Serialize condition groups into a structure SchemaManager can process.
     *
     * Returns array of condition nodes, each with:
     *   - boolean: 'AND' | 'OR'
     *   - type: 'basic'|'in'|'neq'|'between'|'null'|'not_null'|'nested'
     *   - field, value, values, min, max (depending on type)
     *   - conditions (for nested groups — array of child nodes)
     */
    private function serializeConditionGroups(): array
    {
        $result = [];

        foreach ($this->conditionGroups as $group) {
            if ($group['type'] === 'nested' && isset($group['group'])) {
                /** @var FilterGroup $filterGroup */
                $filterGroup = $group['group'];
                $result[] = [
                    'boolean' => $group['boolean'],
                    'type' => 'nested',
                    'conditions' => $filterGroup->getConditions(),
                ];
            } else {
                // Simple condition (orWhere, orWhereIn, etc.)
                $result[] = $group;
            }
        }

        return $result;
    }

    // ========================================================================
    // INTERNAL: RELATION RESOLUTION
    // ========================================================================

    /**
     * Resolve relations on a set of entries based on with() or withRelations() config.
     */
    private function resolveEntryRelations(array $entries): array
    {
        if (empty($entries)) {
            return $entries;
        }

        $fields = $this->getFields();
        if (empty($fields)) {
            return $entries;
        }

        // Always load media pivot data for multi-image/file fields
        $entries = $this->loadMediaPivotData($entries, $fields);

        // Mode 1: Resolve ALL relations (withRelations(depth))
        if ($this->resolveAll && $this->resolveDepth > 0) {
            return _cms_resolve_relations($entries, $fields, $this->tableName, $this->schema, $this->resolveDepth);
        }

        // Mode 2: Selective relation loading (with('author', 'categories'))
        if (!empty($this->withRelations)) {
            return $this->resolveSelectiveRelations($entries, $fields);
        }

        return $entries;
    }

    /**
     * Load media IDs from pivot tables for multiple image/file fields.
     * Sets each entry's field value to an array of media IDs.
     */
    private function loadMediaPivotData(array $entries, array $fields): array
    {
        if (empty($entries)) {
            return $entries;
        }

        // Collect multi-image/file fields
        $mediaFields = [];
        foreach ($fields as $field) {
            if (in_array($field->getType(), ['image', 'file'])) {
                $options = $field->getOptions() ?? [];
                if (!empty($options['multiple'])) {
                    $mediaFields[] = $field;
                }
            }
        }

        if (empty($mediaFields)) {
            return $entries;
        }

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

        $conn = $this->schema->getConnection();

        foreach ($mediaFields as $field) {
            $fieldSlug = $field->getSlug();
            $pivotTable = $this->schema->getPivotTableName($this->tableName, $fieldSlug);

            if (!$this->schema->tableExists($pivotTable)) {
                // No pivot table — set empty arrays
                foreach ($entries as &$entry) {
                    $entry[$fieldSlug] = [];
                }
                unset($entry);
                continue;
            }

            $srcCol = SchemaManager::validateIdentifier("{$this->tableName}_id", 'pivot column');
            $tgtCol = SchemaManager::validateIdentifier('cms_media_id', 'pivot column');

            // Batch-fetch all pivot rows for these entries
            $pivotRows = $conn->createQueryBuilder()
                ->select("`{$srcCol}`, `{$tgtCol}`")
                ->from($pivotTable)
                ->where("`{$srcCol}` IN (:ids)")
                ->setParameter('ids', $entryIds, \Doctrine\DBAL\ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();

            // Group by source ID
            $pivotMap = [];
            foreach ($pivotRows as $row) {
                $pivotMap[$row[$srcCol]][] = (int) $row[$tgtCol];
            }

            // Set media IDs on entries
            foreach ($entries as &$entry) {
                $entry[$fieldSlug] = $pivotMap[$entry['id']] ?? [];
            }
            unset($entry);
        }

        return $entries;
    }

    /**
     * Resolve only the relations specified via with().
     * Supports dot notation: with('author.role') resolves author, then author's role.
     */
    private function resolveSelectiveRelations(array $entries, array $fields): array
    {
        // Group relations by top-level field and nested path
        // e.g. ['author', 'author.role', 'categories'] → ['author' => ['role'], 'categories' => []]
        $relationMap = [];
        foreach ($this->withRelations as $relation) {
            $parts = explode('.', $relation, 2);
            $topLevel = $parts[0];
            $nested = $parts[1] ?? null;

            if (!isset($relationMap[$topLevel])) {
                $relationMap[$topLevel] = [];
            }
            if ($nested !== null) {
                $relationMap[$topLevel][] = $nested;
            }
        }

        $conn = $this->schema->getConnection();

        foreach ($fields as $field) {
            if ($field->getType() !== 'relation') {
                continue;
            }

            $fieldSlug = $field->getSlug();
            if (!isset($relationMap[$fieldSlug])) {
                continue; // Not requested via with()
            }

            $opts = $field->getOptions();
            $relSlug = $opts['relation_collection'] ?? '';
            $relationType = $opts['relation_type'] ?? 'one_to_one';

            if (empty($relSlug)) {
                continue;
            }

            $relTableName = $this->findRelatedTable($relSlug);
            if (!$relTableName) {
                continue;
            }

            // Resolve this relation
            if ($relationType === 'one_to_one') {
                $entries = _cms_resolve_one_to_one(
                    $entries, $field, $relSlug, $relTableName,
                    $conn, $this->schema, 1, [$this->tableName]
                );
            } else {
                $entries = _cms_resolve_multi(
                    $entries, $field, $relSlug, $relTableName,
                    $this->tableName, $conn, $this->schema, 1, [$this->tableName]
                );
            }

            // Handle nested relations (dot notation)
            $nestedPaths = $relationMap[$fieldSlug];
            if (!empty($nestedPaths)) {
                $entries = $this->resolveNestedRelations($entries, $fieldSlug, $relSlug, $relTableName, $relationType, $nestedPaths);
            }
        }

        return $entries;
    }

    /**
     * Resolve nested relations specified via dot notation.
     * e.g. with('author.role') → after author is resolved, resolve author's role field.
     */
    private function resolveNestedRelations(
        array $entries,
        string $parentField,
        string $relSlug,
        string $relTableName,
        string $relationType,
        array $nestedPaths
    ): array {
        $relFields = _cms_get_fields($relSlug);
        if (empty($relFields)) {
            return $entries;
        }

        // Build a nested with() list for the related collection
        $nestedRelationMap = [];
        foreach ($nestedPaths as $path) {
            $parts = explode('.', $path, 2);
            $nestedRelationMap[$parts[0]] = $parts[1] ?? null;
        }

        $conn = $this->schema->getConnection();

        foreach ($relFields as $relField) {
            if ($relField->getType() !== 'relation') {
                continue;
            }
            if (!isset($nestedRelationMap[$relField->getSlug()])) {
                continue;
            }

            $nestedOpts = $relField->getOptions();
            $nestedRelSlug = $nestedOpts['relation_collection'] ?? '';
            $nestedRelType = $nestedOpts['relation_type'] ?? 'one_to_one';

            if (empty($nestedRelSlug)) {
                continue;
            }

            $nestedRelTable = $this->findRelatedTable($nestedRelSlug);
            if (!$nestedRelTable) {
                continue;
            }

            // Resolve nested relation inside each parent's resolved data
            if ($relationType === 'one_to_one') {
                // entries[].author is a single array — collect them, resolve, put back
                $relEntries = [];
                $relIndexes = [];
                foreach ($entries as $i => $entry) {
                    $relData = $entry[$parentField] ?? null;
                    if (is_array($relData) && isset($relData['id'])) {
                        $relEntries[] = $relData;
                        $relIndexes[] = $i;
                    }
                }

                if (!empty($relEntries)) {
                    if ($nestedRelType === 'one_to_one') {
                        $relEntries = _cms_resolve_one_to_one(
                            $relEntries, $relField, $nestedRelSlug, $nestedRelTable,
                            $conn, $this->schema, 1, [$this->tableName, $relTableName]
                        );
                    } else {
                        $relEntries = _cms_resolve_multi(
                            $relEntries, $relField, $nestedRelSlug, $nestedRelTable,
                            $relTableName, $conn, $this->schema, 1, [$this->tableName, $relTableName]
                        );
                    }

                    // Put resolved data back
                    foreach ($relIndexes as $j => $entryIndex) {
                        $entries[$entryIndex][$parentField] = $relEntries[$j];
                    }
                }
            } else {
                // entries[].categories is an array of arrays — collect all, resolve, put back
                $allRelEntries = [];
                $mapping = []; // [entryIndex => [startIdx, count]]

                foreach ($entries as $i => $entry) {
                    $relArray = $entry[$parentField] ?? [];
                    if (is_array($relArray)) {
                        $start = count($allRelEntries);
                        foreach ($relArray as $relItem) {
                            if (is_array($relItem) && isset($relItem['id'])) {
                                $allRelEntries[] = $relItem;
                            }
                        }
                        $mapping[$i] = [$start, count($allRelEntries) - $start];
                    }
                }

                if (!empty($allRelEntries)) {
                    if ($nestedRelType === 'one_to_one') {
                        $allRelEntries = _cms_resolve_one_to_one(
                            $allRelEntries, $relField, $nestedRelSlug, $nestedRelTable,
                            $conn, $this->schema, 1, [$this->tableName, $relTableName]
                        );
                    } else {
                        $allRelEntries = _cms_resolve_multi(
                            $allRelEntries, $relField, $nestedRelSlug, $nestedRelTable,
                            $relTableName, $conn, $this->schema, 1, [$this->tableName, $relTableName]
                        );
                    }

                    // Put resolved data back
                    foreach ($mapping as $entryIndex => [$start, $count]) {
                        $entries[$entryIndex][$parentField] = array_slice($allRelEntries, $start, $count);
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Find the table name for a related collection slug.
     */
    private function findRelatedTable(string $slug): ?string
    {
        $relCollection = Collection::findOneBy(['slug' => $slug]);
        if (!$relCollection) {
            return null;
        }

        $table = $relCollection->getTableName();
        return $this->schema->tableExists($table) ? $table : null;
    }

    // ========================================================================
    // INTERNAL: RELATION WRITES (PIVOT SYNC)
    // ========================================================================

    /**
     * Separate entry data from pivot relation data.
     *
     * Scalar values for relation fields → entry data (one-to-one FK).
     * Array values for relation fields → pivot data (many-to-many).
     *
     * @return array{0: array, 1: array} [$entryData, $pivotData]
     */
    private function separateRelationData(array $data): array
    {
        $fields = $this->getFields();
        $entryData = [];
        $pivotData = [];

        // Index fields by slug for lookup
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field->getSlug()] = $field;
        }

        foreach ($data as $key => $value) {
            $field = $fieldMap[$key] ?? null;

            if ($field && $field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';

                if ($relationType !== 'one_to_one' && is_array($value)) {
                    // Many-to-many: store for pivot sync
                    $pivotData[$key] = $value;
                    continue;
                }
            }

            // Multiple image/file fields use pivot tables
            if ($field && in_array($field->getType(), ['image', 'file'])) {
                $options = $field->getOptions() ?? [];
                if (!empty($options['multiple']) && is_array($value)) {
                    $pivotData[$key] = $value;
                    continue;
                }
            }

            // Scalar field or one-to-one relation FK
            $entryData[$key] = $value;
        }

        return [$entryData, $pivotData];
    }

    /**
     * Sync pivot tables for many-to-many relation fields.
     */
    private function syncPivots(int|string $entryId, array $pivotData): void
    {
        if (empty($pivotData)) {
            return;
        }

        $fields = $this->getFields();
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field->getSlug()] = $field;
        }

        foreach ($pivotData as $fieldSlug => $relatedIds) {
            $field = $fieldMap[$fieldSlug] ?? null;
            if (!$field) {
                continue;
            }

            $type = $field->getType();
            $options = $field->getOptions() ?? [];

            // Relation many-to-many pivot sync
            if ($type === 'relation') {
                $relSlug = $options['relation_collection'] ?? '';
                if (empty($relSlug)) {
                    continue;
                }

                $targetTable = $this->schema->getTableName($relSlug);

                $this->schema->syncPivotRelations(
                    $this->tableName,
                    $fieldSlug,
                    $targetTable,
                    $entryId,
                    array_map('intval', $relatedIds)
                );
                continue;
            }

            // Multiple image/file pivot sync (target = cms_media)
            if (in_array($type, ['image', 'file']) && !empty($options['multiple'])) {
                $this->schema->syncPivotRelations(
                    $this->tableName,
                    $fieldSlug,
                    'cms_media',
                    $entryId,
                    array_map('intval', $relatedIds)
                );
            }
        }
    }

    // ========================================================================
    // INTERNAL: LOCALE
    // ========================================================================

    /**
     * Apply locale translations if configured.
     */
    private function applyLocale(array $entries): array
    {
        if ($this->localeValue === null || empty($entries)) {
            return $entries;
        }

        $this->boot();
        if (!$this->collection || !$this->collection->isTranslatable()) {
            return $entries;
        }

        return TranslationService::resolveEntries($entries, $this->tableName, $this->localeValue);
    }

    // ========================================================================
    // INTERNAL: CACHE HELPERS
    // ========================================================================

    private function cacheGet(string $key): mixed
    {
        if (!$this->useCache || !class_exists(CacheManager::class)) {
            return null;
        }

        try {
            $cache = CacheManager::getInstance();
            return $cache->get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        if (!class_exists(CacheManager::class)) {
            return;
        }

        try {
            $cache = CacheManager::getInstance();
            $cache->set($key, $value, $ttl);
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal
        }
    }
}
