<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\PageType;
use ZephyrPHP\Cms\Services\SchemaManager;

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
                }

                if (count($entries) > 0) {
                    $output->writeln("  Published " . count($entries) . " entries in {$collection->getName()}");
                }
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Error processing collections: {$e->getMessage()}</error>");
        }

        // Process page types
        try {
            $pageTypes = PageType::findAll();
            foreach ($pageTypes as $pt) {
                $tableName = $pt->getTableName();
                if (!$schema->tableExists($tableName)) continue;

                $sm = $conn->createSchemaManager();
                $columns = $sm->listTableColumns($tableName);
                if (!isset($columns['scheduled_at']) || !isset($columns['status'])) continue;

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
                }

                if (count($entries) > 0) {
                    $output->writeln("  Published " . count($entries) . " pages in {$pt->getName()}");
                }
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Error processing page types: {$e->getMessage()}</error>");
        }

        if ($totalPublished > 0) {
            $output->writeln("<info>Total published: {$totalPublished} entries</info>");
        } else {
            $output->writeln("No scheduled entries to publish.");
        }

        return Command::SUCCESS;
    }
}
