<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\EntryQuery;

class SearchApiController extends Controller
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * GET /api/search?q=term&collection=blog&page=1&per_page=10
     *
     * Searches across all API-enabled collections (or a specific one).
     * Only returns published entries.
     */
    public function search(): string
    {
        // Rate limit before doing any work
        $rateLimitError = $this->checkSearchRateLimit();
        if ($rateLimitError) {
            $this->json(['error' => $rateLimitError, 'results' => [], 'total' => 0], 429);
            return '';
        }
        $this->recordSearchRateLimit();

        $query = trim($this->input('q', ''));
        $collectionSlug = $this->input('collection');
        $page = max(1, (int) $this->input('page', 1));
        $perPage = min(50, max(1, (int) $this->input('per_page', 10)));

        if (strlen($query) < 2) {
            $this->json(['error' => 'Search query must be at least 2 characters.', 'results' => [], 'total' => 0], 400);
            return '';
        }

        if (strlen($query) > 200) {
            $this->json(['error' => 'Search query too long.', 'results' => [], 'total' => 0], 400);
            return '';
        }

        // Sanitize: strip tags, control characters
        $query = strip_tags($query);
        $query = preg_replace('/[\x00-\x1f\x7f]/', '', $query);
        $query = trim($query);

        if (strlen($query) < 2) {
            $this->json(['error' => 'Search query must be at least 2 characters.', 'results' => [], 'total' => 0], 400);
            return '';
        }

        // Get searchable collections
        if ($collectionSlug) {
            // Whitelist: only alphanumeric, hyphens, underscores
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $collectionSlug)) {
                $this->json(['error' => 'Invalid collection slug.', 'results' => [], 'total' => 0], 400);
                return '';
            }
            $collection = Collection::findOneBy(['slug' => $collectionSlug]);
            $collections = ($collection && $collection->isApiEnabled()) ? [$collection] : [];
        } else {
            $collections = Collection::findBy(['isApiEnabled' => true]);
        }

        if (empty($collections)) {
            $this->json([
                'query' => $query,
                'results' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
            ]);
            return '';
        }

        $allResults = [];
        $totalCount = 0;

        foreach ($collections as $collection) {
            $searchableFields = $collection->getSearchableFields();
            if (empty($searchableFields)) {
                continue;
            }

            $fieldSlugs = array_map(fn($f) => $f->getSlug(), $searchableFields);

            $eq = EntryQuery::collection($collection->getSlug());

            // Only published entries for publishable collections
            if ($collection->isPublishable()) {
                $eq->where('status', 'published');
            }

            // Search across searchable fields
            $eq->search($query, $fieldSlugs);

            $count = $eq->count();
            $totalCount += $count;

            $entries = $eq->latest()->paginate($page, $perPage);

            foreach ($entries['data'] as $entry) {
                // Strip system/internal fields for cleaner response
                unset($entry['created_by'], $entry['deleted_at']);

                $allResults[] = [
                    'collection' => $collection->getSlug(),
                    'collection_name' => $collection->getName(),
                    'entry' => $entry,
                ];
            }
        }

        $this->json([
            'query' => $query,
            'results' => $allResults,
            'total' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
        ]);
        return '';
    }

    /**
     * Resolve the client IP address, preferring trusted proxy headers.
     */
    private function resolveClientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        // Use first IP if X-Forwarded-For contains multiple
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }

        return $ip;
    }

    /**
     * Check search rate limit (30 requests per minute per IP).
     * Uses cache first, falls back to file-based tracking.
     */
    private function checkSearchRateLimit(): ?string
    {
        $ip = $this->resolveClientIp();
        $key = 'search_rate:' . md5($ip);
        $limit = self::RATE_LIMIT_PER_MINUTE;

        // Try cache-based rate limiting first
        try {
            $cache = \ZephyrPHP\Cache\CacheManager::getInstance();
            $current = (int) $cache->get($key, 0);

            if ($current >= $limit) {
                header('X-RateLimit-Limit: ' . $limit);
                header('X-RateLimit-Remaining: 0');
                header('Retry-After: 60');
                return 'Rate limit exceeded. Try again later.';
            }

            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: ' . max(0, $limit - $current - 1));
            return null;
        } catch (\Exception $e) {
            // Cache unavailable — use file-based fallback
        }

        // File-based fallback
        $rateLimitDir = (defined('BASE_PATH') ? BASE_PATH : '.') . '/storage/rate_limits';
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }

        $file = $rateLimitDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && (time() - ($data['start'] ?? 0)) <= 60) {
                if (($data['count'] ?? 0) >= $limit) {
                    header('Retry-After: 60');
                    return 'Rate limit exceeded. Try again later.';
                }
            }
        }

        return null;
    }

    /**
     * Record a rate limit hit using cache or file-based fallback.
     */
    private function recordSearchRateLimit(): void
    {
        $ip = $this->resolveClientIp();
        $key = 'search_rate:' . md5($ip);

        // Try cache first
        try {
            $cache = \ZephyrPHP\Cache\CacheManager::getInstance();
            $current = (int) $cache->get($key, 0);
            $cache->set($key, $current + 1, 60);
            return;
        } catch (\Exception $e) {
            // Cache unavailable — use file-based fallback
        }

        // File-based fallback
        $rateLimitDir = (defined('BASE_PATH') ? BASE_PATH : '.') . '/storage/rate_limits';
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }

        $file = $rateLimitDir . '/' . md5($key) . '.json';
        $data = null;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
        }

        if ($data && (time() - ($data['start'] ?? 0)) <= 60) {
            $data['count'] = ($data['count'] ?? 0) + 1;
        } else {
            $data = ['start' => time(), 'count' => 1];
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
