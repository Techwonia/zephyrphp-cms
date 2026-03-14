<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Database\Connection;

class AnalyticsService
{
    /**
     * Get recent entries across all collections, sorted by created_at DESC.
     */
    public static function getRecentEntries(int $limit = 10): array
    {
        try {
            $conn = Connection::getInstance()->getConnection();
            $collections = Collection::findAll();
            $allEntries = [];

            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                $schema = new SchemaManager();
                if (!$schema->tableExists($tableName)) continue;

                // Get a display field (first text/email field, or 'id')
                $displayField = 'id';
                foreach ($collection->getFields() as $field) {
                    if (in_array($field->getType(), ['text', 'email'])) {
                        $displayField = $field->getSlug();
                        break;
                    }
                }

                $selectCols = "id, `{$displayField}` as display_value, created_at";
                if ($collection->isPublishable()) {
                    $selectCols .= ', status';
                }

                $rows = $conn->createQueryBuilder()
                    ->select($selectCols)
                    ->from($tableName)
                    ->orderBy('created_at', 'DESC')
                    ->setMaxResults($limit)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($rows as $row) {
                    $allEntries[] = [
                        'id' => $row['id'],
                        'label' => $row['display_value'] ?? "#{$row['id']}",
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
     */
    public static function getEntryCountsByCollection(): array
    {
        try {
            $schema = new SchemaManager();
            $collections = Collection::findAll();
            $counts = [];

            foreach ($collections as $collection) {
                $counts[] = [
                    'name' => $collection->getName(),
                    'slug' => $collection->getSlug(),
                    'count' => $schema->countEntries($collection->getTableName()),
                ];
            }

            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Count draft entries across all publishable collections.
     */
    public static function getDraftCount(): int
    {
        try {
            $conn = Connection::getInstance()->getConnection();
            $collections = Collection::findAll();
            $total = 0;

            foreach ($collections as $collection) {
                if (!$collection->isPublishable()) continue;
                $tableName = $collection->getTableName();
                $schema = new SchemaManager();
                if (!$schema->tableExists($tableName)) continue;

                $count = (int) $conn->createQueryBuilder()
                    ->select('COUNT(*)')
                    ->from($tableName)
                    ->where('status = :status')
                    ->setParameter('status', 'draft')
                    ->executeQuery()
                    ->fetchOne();

                $total += $count;
            }

            return $total;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Count scheduled entries across all publishable collections.
     */
    public static function getScheduledCount(): int
    {
        try {
            $conn = Connection::getInstance()->getConnection();
            $collections = Collection::findAll();
            $total = 0;

            foreach ($collections as $collection) {
                if (!$collection->isPublishable()) continue;
                $tableName = $collection->getTableName();
                $schema = new SchemaManager();
                if (!$schema->tableExists($tableName)) continue;

                $count = (int) $conn->createQueryBuilder()
                    ->select('COUNT(*)')
                    ->from($tableName)
                    ->where('status = :status')
                    ->setParameter('status', 'scheduled')
                    ->executeQuery()
                    ->fetchOne();

                $total += $count;
            }

            return $total;
        } catch (\Exception $e) {
            return 0;
        }
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

            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                $schema = new SchemaManager();
                if (!$schema->tableExists($tableName)) continue;

                $rows = $conn->createQueryBuilder()
                    ->select("DATE(created_at) as day, COUNT(*) as cnt")
                    ->from($tableName)
                    ->where("created_at >= :start")
                    ->setParameter('start', date('Y-m-d', strtotime("-{$days} days")))
                    ->groupBy('day')
                    ->executeQuery()
                    ->fetchAllAssociative();

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
            $conn = Connection::getInstance()->getConnection();
            $collections = Collection::findAll();
            $scheduled = [];

            foreach ($collections as $collection) {
                if (!$collection->isPublishable()) continue;
                $tableName = $collection->getTableName();
                $schema = new SchemaManager();
                if (!$schema->tableExists($tableName)) continue;

                $displayField = 'id';
                foreach ($collection->getFields() as $field) {
                    if (in_array($field->getType(), ['text', 'email'])) {
                        $displayField = $field->getSlug();
                        break;
                    }
                }

                $rows = $conn->createQueryBuilder()
                    ->select("id, `{$displayField}` as display_value, scheduled_at")
                    ->from($tableName)
                    ->where('status = :status')
                    ->andWhere('scheduled_at > :now')
                    ->setParameter('status', 'scheduled')
                    ->setParameter('now', date('Y-m-d H:i:s'))
                    ->orderBy('scheduled_at', 'ASC')
                    ->setMaxResults($limit)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($rows as $row) {
                    $scheduled[] = [
                        'id' => $row['id'],
                        'label' => $row['display_value'] ?? "#{$row['id']}",
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
