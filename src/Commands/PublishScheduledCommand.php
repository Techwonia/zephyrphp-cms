<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\NotificationService;

#[AsCommand(
    name: 'cms:publish-scheduled',
    description: 'Publish all scheduled entries whose publish time has arrived'
)]
class PublishScheduledCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = new SchemaManager();
        $conn = $schema->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $totalPublished = 0;

        // Process collections
        try {
            $collections = Collection::findBy(['isPublishable' => true]);
            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                if (!$schema->tableExists($tableName)) continue;

                $sm = $conn->createSchemaManager();
                $columns = $sm->listTableColumns($tableName);
                if (!isset($columns['scheduled_at'])) continue;

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
                    $totalPublished++;

                    // Notify admins about scheduled publish
                    try {
                        $full = $schema->findEntry($tableName, $entry['id']);
                        $entryTitle = $full['title'] ?? $full['name'] ?? "#{$entry['id']}";
                        NotificationService::notifyAdmins(
                            'scheduled_published',
                            "Scheduled entry published: {$entryTitle}",
                            "The scheduled entry \"{$entryTitle}\" in {$collection->getName()} has been automatically published.",
                            "/cms/collections/{$collection->getSlug()}/entries/{$entry['id']}",
                            ['collection' => $collection->getSlug(), 'entry_id' => $entry['id']],
                            [
                                'entry_title' => $entryTitle,
                                'collection_name' => $collection->getName(),
                                'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$collection->getSlug()}/entries/{$entry['id']}",
                            ]
                        );
                    } catch (\Exception $ne) {
                        // Notification failure should not break scheduled publishing
                    }
                }

                if (count($entries) > 0) {
                    $output->writeln("  Published " . count($entries) . " entries in {$collection->getName()}");
                }
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Error processing collections: {$e->getMessage()}</error>");
        }

        if ($totalPublished > 0) {
            $output->writeln("<info>Total published: {$totalPublished} entries</info>");
        } else {
            $output->writeln("No scheduled entries to publish.");
        }

        return Command::SUCCESS;
    }
}
