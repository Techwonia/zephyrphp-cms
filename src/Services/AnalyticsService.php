<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Database\Connection;

class AnalyticsService
{
    /**
     * Get recent entries across all collections, sorted by created_at DESC.
     */
    public static function getRecentEntries(int $limit = 10): array
    {
        try {
            $collections = Collection::findAll();
            $allEntries = [];

            foreach ($collections as $collection) {
                // Determine display field (first text/email field, or 'id')
                $displayField = 'id';
                foreach ($collection->getFields() as $field) {
                    if (in_array($field->getType(), ['text', 'email'])) {
                        $displayField = $field->getSlug();
                        break;
                    }
                }

                $fields = [$displayField, 'created_at'];
                if ($collection->isPublishable()) {
                    $fields[] = 'status';
                }

                $rows = EntryQuery::collection($collection->getSlug())
                    ->onlyFields(...$fields)
                    ->latest('created_at')
                    ->noCache()
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

            // Sort all by created_at DESC and limit
            usort($allEntries, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            return array_slice($allEntries, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get entry counts per collection.
     * Uses a single query per collection via direct SQL COUNT to minimize overhead.
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
                $tableName = $collection->getTableName();
                try {
                    $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");
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
     *
     * @return array{draft: int, scheduled: int}
     */
    private static function getStatusCounts(): array
    {
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
                return $result;
            }

            // Build a UNION ALL query to count both statuses across all publishable tables at once
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

        return $result;
    }

    /**
     * Count draft entries across all publishable collections.
     */
    public static function getDraftCount(): int
    {
        return self::getStatusCounts()['draft'];
    }

    /**
     * Count scheduled entries across all publishable collections.
     */
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
            $sm = $conn->createSchemaManager();

            if (!$sm->tablesExist(['cms_media'])) {
                return ['total' => 0, 'types' => []];
            }

            $total = (int) $conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('cms_media')
                ->executeQuery()
                ->fetchOne();

            // Group by MIME type prefix (image, document, video, audio, other)
            $rows = $conn->createQueryBuilder()
                ->select("SUBSTRING_INDEX(mime_type, '/', 1) as type_group, COUNT(*) as cnt")
                ->from('cms_media')
                ->groupBy('type_group')
                ->executeQuery()
                ->fetchAllAssociative();

            $types = [];
            foreach ($rows as $row) {
                $types[$row['type_group'] ?? 'other'] = (int) $row['cnt'];
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

            // Build a date range
            $dates = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $dates[$date] = 0;
            }

            $startDate = date('Y-m-d', strtotime("-{$days} days"));

            if (!empty($collections)) {
                // Build a single UNION ALL query across all collection tables
                $unions = [];
                foreach ($collections as $collection) {
                    $table = $collection->getTableName();
                    $unions[] = "SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM `{$table}` WHERE created_at >= ? GROUP BY day";
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

                $displayField = 'id';
                foreach ($collection->getFields() as $field) {
                    if (in_array($field->getType(), ['text', 'email'])) {
                        $displayField = $field->getSlug();
                        break;
                    }
                }

                $rows = EntryQuery::collection($collection->getSlug())
                    ->where('status', 'scheduled')
                    ->whereCompare('scheduled_at', '>', date('Y-m-d H:i:s'))
                    ->onlyFields($displayField, 'scheduled_at')
                    ->orderBy('scheduled_at', 'ASC')
                    ->noCache()
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

            usort($scheduled, function ($a, $b) {
                return strcmp($a['scheduled_at'] ?? '', $b['scheduled_at'] ?? '');
            });

            return array_slice($scheduled, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }
}
