<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\WorkflowTransition;
use ZephyrPHP\Database\Connection;

class WorkflowService
{
    /**
     * Get the workflow stages for a collection.
     */
    public static function getStages(Collection $collection): array
    {
        if (!$collection->isWorkflowEnabled()) {
            return [];
        }
        return $collection->getWorkflowStages();
    }

    /**
     * Get the current stage of an entry.
     */
    public static function getCurrentStage(array $entry): string
    {
        return $entry['status'] ?? 'draft';
    }

    /**
     * Get the next stage in the workflow.
     */
    public static function getNextStage(Collection $collection, string $currentStage): ?string
    {
        $stages = $collection->getWorkflowStages();
        $idx = array_search($currentStage, $stages);
        if ($idx === false || $idx >= count($stages) - 1) {
            return null;
        }
        return $stages[$idx + 1];
    }

    /**
     * Get the previous stage in the workflow.
     */
    public static function getPreviousStage(Collection $collection, string $currentStage): ?string
    {
        $stages = $collection->getWorkflowStages();
        $idx = array_search($currentStage, $stages);
        if ($idx === false || $idx <= 0) {
            return null;
        }
        return $stages[$idx - 1];
    }

    /**
     * Check if a user can advance an entry to the next stage.
     */
    public static function canAdvance(Collection $collection, string $currentStage, int $userId): bool
    {
        if (!$collection->isWorkflowEnabled()) {
            return false;
        }

        $nextStage = self::getNextStage($collection, $currentStage);
        if ($nextStage === null) {
            return false;
        }

        // Check if user is a reviewer for the current stage
        return self::isReviewerForStage($collection, $currentStage, $userId);
    }

    /**
     * Check if a user can reject an entry (send it back).
     */
    public static function canReject(Collection $collection, string $currentStage, int $userId): bool
    {
        if (!$collection->isWorkflowEnabled()) {
            return false;
        }

        $prevStage = self::getPreviousStage($collection, $currentStage);
        if ($prevStage === null) {
            return false;
        }

        return self::isReviewerForStage($collection, $currentStage, $userId);
    }

    /**
     * Check if a user is a reviewer for a given stage.
     * If no reviewers are configured for a stage, any authenticated user can act.
     */
    public static function isReviewerForStage(Collection $collection, string $stage, int $userId): bool
    {
        $reviewers = $collection->getWorkflowReviewers();
        if (empty($reviewers[$stage])) {
            return true; // No specific reviewers = anyone can review
        }
        return in_array($userId, $reviewers[$stage]);
    }

    /**
     * Advance an entry to the next workflow stage.
     */
    public static function advance(
        Collection $collection,
        string $entryId,
        int $userId,
        ?string $userName = null,
        ?string $comment = null
    ): ?string {
        $tableName = $collection->getTableName();
        $conn = Connection::getInstance()->getConnection();

        $entry = $conn->createQueryBuilder()
            ->select('status')
            ->from($tableName)
            ->where('id = :id')
            ->setParameter('id', $entryId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$entry) {
            return null;
        }

        $currentStage = $entry['status'] ?? 'draft';

        if (!self::canAdvance($collection, $currentStage, $userId)) {
            return null;
        }

        $nextStage = self::getNextStage($collection, $currentStage);
        if (!$nextStage) {
            return null;
        }

        // Update entry status
        $updateData = ['status' => $nextStage];

        // If advancing to 'published', set published_at
        if ($nextStage === 'published') {
            $updateData['published_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }

        $conn->update($tableName, $updateData, ['id' => $entryId]);

        // Record transition
        self::recordTransition($tableName, $entryId, $currentStage, $nextStage, $userId, $userName, $comment, 'advance');

        // Invalidate cache
        cms_invalidate_cache($collection->getSlug());

        return $nextStage;
    }

    /**
     * Reject an entry (send it back to the previous stage).
     */
    public static function reject(
        Collection $collection,
        string $entryId,
        int $userId,
        ?string $userName = null,
        ?string $comment = null
    ): ?string {
        $tableName = $collection->getTableName();
        $conn = Connection::getInstance()->getConnection();

        $entry = $conn->createQueryBuilder()
            ->select('status')
            ->from($tableName)
            ->where('id = :id')
            ->setParameter('id', $entryId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$entry) {
            return null;
        }

        $currentStage = $entry['status'] ?? 'draft';

        if (!self::canReject($collection, $currentStage, $userId)) {
            return null;
        }

        $prevStage = self::getPreviousStage($collection, $currentStage);
        if (!$prevStage) {
            return null;
        }

        $conn->update($tableName, ['status' => $prevStage], ['id' => $entryId]);

        // Record transition
        self::recordTransition($tableName, $entryId, $currentStage, $prevStage, $userId, $userName, $comment, 'reject');

        cms_invalidate_cache($collection->getSlug());

        return $prevStage;
    }

    /**
     * Record a workflow transition.
     */
    private static function recordTransition(
        string $tableName,
        string $entryId,
        string $fromStage,
        string $toStage,
        int $userId,
        ?string $userName,
        ?string $comment,
        string $action
    ): void {
        $transition = new WorkflowTransition();
        $transition->setTableName($tableName);
        $transition->setEntryId($entryId);
        $transition->setFromStage($fromStage);
        $transition->setToStage($toStage);
        $transition->setUserId($userId);
        $transition->setUserName($userName);
        $transition->setComment($comment);
        $transition->setAction($action);
        $transition->save();
    }

    /**
     * Get transition history for an entry.
     */
    public static function getHistory(string $tableName, string $entryId): array
    {
        return WorkflowTransition::findBy(
            ['tableName' => $tableName, 'entryId' => $entryId],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Get all entries pending review for a specific user across all collections.
     */
    public static function getPendingReviews(int $userId, int $limit = 10): array
    {
        $pending = [];

        try {
            $collections = Collection::findBy(['workflowEnabled' => true]);
            $schema = new SchemaManager();

            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                if (!$schema->tableExists($tableName)) continue;

                $stages = $collection->getWorkflowStages();
                $reviewers = $collection->getWorkflowReviewers();

                // Find stages where this user is a reviewer
                $reviewableStages = [];
                foreach ($stages as $stage) {
                    if ($stage === end($stages)) continue; // Skip final stage
                    if (empty($reviewers[$stage]) || in_array($userId, $reviewers[$stage])) {
                        $reviewableStages[] = $stage;
                    }
                }

                if (empty($reviewableStages)) continue;

                $conn = $schema->getConnection();
                $qb = $conn->createQueryBuilder()
                    ->select('id, status, createdAt')
                    ->from($tableName)
                    ->where('status IN (:stages)')
                    ->setParameter('stages', $reviewableStages, \Doctrine\DBAL\ArrayParameterType::STRING)
                    ->orderBy('createdAt', 'DESC')
                    ->setMaxResults($limit);

                // Try to select title/name if columns exist
                try {
                    $sm = $conn->createSchemaManager();
                    $columns = $sm->listTableColumns($tableName);
                    if (isset($columns['title'])) {
                        $qb->addSelect('title');
                    } elseif (isset($columns['name'])) {
                        $qb->addSelect('name');
                    }
                } catch (\Exception $e) {}

                $entries = $qb->executeQuery()->fetchAllAssociative();

                foreach ($entries as $entry) {
                    $pending[] = [
                        'collection_name' => $collection->getName(),
                        'collection_slug' => $collection->getSlug(),
                        'entry_id' => $entry['id'],
                        'entry_title' => $entry['title'] ?? $entry['name'] ?? "#{$entry['id']}",
                        'status' => $entry['status'],
                        'created_at' => $entry['createdAt'] ?? null,
                    ];
                }
            }

            // Sort by date, limit
            usort($pending, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
            return array_slice($pending, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get count of pending reviews for a user.
     */
    public static function getPendingReviewCount(int $userId): int
    {
        return count(self::getPendingReviews($userId, 100));
    }
}
