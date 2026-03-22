<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Middleware;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\NotificationService;
use ZephyrPHP\Cms\Services\AutomationService;

/**
 * Lightweight middleware that checks for overdue scheduled entries
 * and publishes them automatically on each CMS request.
 *
 * Replaces the need for a cron-based cms:publish-scheduled command.
 * Uses a file-based throttle to avoid running on every single request.
 */
class ScheduledPublishMiddleware
{
    private const THROTTLE_SECONDS = 60;

    public function handle($request, callable $next)
    {
        // Run the check in the background (non-blocking)
        $this->publishOverdueEntries();

        return $next($request);
    }

    private function publishOverdueEntries(): void
    {
        // Throttle: only check once per minute
        $lockFile = $this->getLockPath();
        if ($lockFile && file_exists($lockFile)) {
            $lastRun = (int) file_get_contents($lockFile);
            if (time() - $lastRun < self::THROTTLE_SECONDS) {
                return;
            }
        }

        // Update throttle timestamp
        if ($lockFile) {
            $dir = dirname($lockFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($lockFile, (string) time(), LOCK_EX);
        }

        try {
            $schema = new SchemaManager();
            $conn = $schema->getConnection();
            $now = (new \DateTime())->format('Y-m-d H:i:s');

            $collections = Collection::findBy(['isPublishable' => true]);

            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                if (!$schema->tableExists($tableName)) {
                    continue;
                }

                $sm = $conn->createSchemaManager();
                $columns = $sm->listTableColumns($tableName);
                if (!isset($columns['scheduled_at'])) {
                    continue;
                }

                $entries = $conn->createQueryBuilder()
                    ->select('id')
                    ->from($tableName)
                    ->where('status = :status')
                    ->andWhere('scheduled_at IS NOT NULL')
                    ->andWhere('scheduled_at <= :now')
                    ->setParameter('status', 'scheduled')
                    ->setParameter('now', $now)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($entries as $entry) {
                    $conn->update($tableName, [
                        'status' => 'published',
                        'published_at' => $now,
                    ], ['id' => $entry['id']]);

                    // Notify admins
                    try {
                        $full = EntryQuery::collection($collection->getSlug())->noCache()->find($entry['id']);
                        $entryTitle = $full['title'] ?? $full['name'] ?? "#{$entry['id']}";
                        NotificationService::notifyAdmins(
                            'scheduled_published',
                            "Scheduled entry published: {$entryTitle}",
                            "The scheduled entry \"{$entryTitle}\" in {$collection->getName()} has been automatically published.",
                            admin_url("collections/{$collection->getSlug()}/entries/{$entry['id']}"),
                            ['collection' => $collection->getSlug(), 'entry_id' => $entry['id']],
                            [
                                'entry_title' => $entryTitle,
                                'collection_name' => $collection->getName(),
                                'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . admin_url("collections/{$collection->getSlug()}/entries/{$entry['id']}"),
                            ]
                        );
                    } catch (\Exception $e) {
                        // Notification failure should not break publishing
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail — scheduled publishing should not break the request
        }

        // Run scheduled automation rules (uses same throttle window)
        try {
            AutomationService::runScheduledRules();
        } catch (\Exception $e) {
            // Silently fail — automation should not break the request
        }
    }

    private function getLockPath(): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : null;
        if (!$basePath) {
            return null;
        }
        return $basePath . '/storage/cms/.scheduled-publish-lock';
    }
}
