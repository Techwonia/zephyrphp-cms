<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Database\Connection;

class AnalyticsService
{
    /** @var array|null Per-request cache for status counts */
    private static ?array $statusCountsCache = null;

    /**
     * Get the display field for a collection (first text/email field, or 'id').
     */
    private static function getDisplayField(Collection $collection): string
    {
        foreach ($collection->getFields() as $field) {
            if (in_array($field->getType(), ['text', 'email'])) {
                return $field->getSlug();
            }
        }
        return 'id';
    }

    /**
     * Get recent entries across all collections, sorted by created_at DESC.
     */
    public static function getRecentEntries(int $limit = 10): array
    {
        try {
            $collections = Collection::findAll();
            $allEntries = [];

            foreach ($collections as $collection) {
                $displayField = self::getDisplayField($collection);

                $fields = [$displayField, 'created_at'];
                if ($collection->isPublishable()) {
                    $fields[] = 'status';
                }

                $rows = EntryQuery::collection($collection->getSlug())
                    ->onlyFields(...$fields)
                    ->latest('created_at')
                    ->limit($limit)
                    ->get();

                foreach ($rows as $row) {
                    $allEntries[] = [
                        'id' => $row['id'],
                        'label' => $row[$displayField] ?? "#{$row['id']}",
                        'collection_name' => $collection->getName(),
                        'collection_slug' => $collection->getSlug(),
                        'status' => $row['status'] ?? null,
                        'created_at' => $row['created_at'] ?? null,
                    ];
                }
            }

            usort($allEntries, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

            return array_slice($allEntries, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get entry counts per collection using direct SQL COUNT.
     */
    public static function getEntryCountsByCollection(): array
    {
        try {
            $collections = Collection::findAll();
            if (empty($collections)) {
                return [];
            }

            $conn = Connection::getInstance()->getConnection();
            $counts = [];

            foreach ($collections as $collection) {
                try {
                    $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$collection->getTableName()}`");
                } catch (\Throwable $e) {
                    $count = 0;
                }
                $counts[] = [
                    'name' => $collection->getName(),
                    'slug' => $collection->getSlug(),
                    'count' => $count,
                ];
            }

            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Count draft and scheduled entries across all publishable collections in one pass.
     * Cached per-request so getDraftCount() + getScheduledCount() only run one query set.
     *
     * @return array{draft: int, scheduled: int}
     */
    private static function getStatusCounts(): array
    {
        if (self::$statusCountsCache !== null) {
            return self::$statusCountsCache;
        }

        $result = ['draft' => 0, 'scheduled' => 0];
        try {
            $collections = Collection::findAll();
            $conn = Connection::getInstance()->getConnection();

            $publishableTables = [];
            foreach ($collections as $collection) {
                if ($collection->isPublishable()) {
                    $publishableTables[] = $collection->getTableName();
                }
            }

            if (empty($publishableTables)) {
                self::$statusCountsCache = $result;
                return $result;
            }

            $unions = [];
            foreach ($publishableTables as $table) {
                $unions[] = "SELECT status, COUNT(*) AS cnt FROM `{$table}` WHERE status IN ('draft', 'scheduled') GROUP BY status";
            }

            $sql = implode(' UNION ALL ', $unions);
            $rows = $conn->fetchAllAssociative($sql);

            foreach ($rows as $row) {
                $status = $row['status'] ?? '';
                if (isset($result[$status])) {
                    $result[$status] += (int) $row['cnt'];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        self::$statusCountsCache = $result;
        return $result;
    }

    public static function getDraftCount(): int
    {
        return self::getStatusCounts()['draft'];
    }

    public static function getScheduledCount(): int
    {
        return self::getStatusCounts()['scheduled'];
    }

    /**
     * Get media usage statistics.
     */
    public static function getMediaUsageStats(): array
    {
        try {
            $conn = Connection::getInstance()->getConnection();

            // Single query: get total + type breakdown together
            $rows = $conn->fetchAllAssociative(
                "SELECT SUBSTRING_INDEX(mime_type, '/', 1) as type_group, COUNT(*) as cnt FROM cms_media GROUP BY type_group"
            );

            $total = 0;
            $types = [];
            foreach ($rows as $row) {
                $cnt = (int) $row['cnt'];
                $types[$row['type_group'] ?? 'other'] = $cnt;
                $total += $cnt;
            }

            return ['total' => $total, 'types' => $types];
        } catch (\Exception $e) {
            return ['total' => 0, 'types' => []];
        }
    }

    /**
     * Get daily entry creation counts for the past N days (for chart).
     */
    public static function getEntriesOverTime(int $days = 30): array
    {
        try {
            $conn = Connection::getInstance()->getConnection();
            $collections = Collection::findAll();

            $dates = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $dates[date('Y-m-d', strtotime("-{$i} days"))] = 0;
            }

            $startDate = date('Y-m-d', strtotime("-{$days} days"));

            if (!empty($collections)) {
                $unions = [];
                foreach ($collections as $collection) {
                    $unions[] = "SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM `{$collection->getTableName()}` WHERE created_at >= ? GROUP BY day";
                }

                $sql = implode(' UNION ALL ', $unions);
                $params = array_fill(0, count($collections), $startDate);
                $rows = $conn->fetchAllAssociative($sql, $params);

                foreach ($rows as $row) {
                    $day = $row['day'] ?? '';
                    if (isset($dates[$day])) {
                        $dates[$day] += (int) $row['cnt'];
                    }
                }
            }

            return $dates;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get upcoming scheduled entries.
     */
    public static function getScheduledEntries(int $limit = 5): array
    {
        try {
            $collections = Collection::findAll();
            $scheduled = [];

            foreach ($collections as $collection) {
                if (!$collection->isPublishable()) continue;

                $displayField = self::getDisplayField($collection);

                $rows = EntryQuery::collection($collection->getSlug())
                    ->where('status', 'scheduled')
                    ->whereCompare('scheduled_at', '>', date('Y-m-d H:i:s'))
                    ->onlyFields($displayField, 'scheduled_at')
                    ->orderBy('scheduled_at', 'ASC')
                    ->limit($limit)
                    ->get();

                foreach ($rows as $row) {
                    $scheduled[] = [
                        'id' => $row['id'],
                        'label' => $row[$displayField] ?? "#{$row['id']}",
                        'collection_name' => $collection->getName(),
                        'collection_slug' => $collection->getSlug(),
                        'scheduled_at' => $row['scheduled_at'],
                    ];
                }
            }

            usort($scheduled, fn($a, $b) => strcmp($a['scheduled_at'] ?? '', $b['scheduled_at'] ?? ''));

            return array_slice($scheduled, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }
}
