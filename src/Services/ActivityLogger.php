<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\ActivityLog;

/**
 * Activity Logger — records CMS actions for audit trail.
 *
 * Usage:
 *   ActivityLogger::log('created', 'entry', $id, 'Blog Post Title', ['collection' => 'posts']);
 *   ActivityLogger::log('updated', 'collection', $slug, 'Products');
 *   ActivityLogger::log('login', 'user', $userId, $email);
 */
class ActivityLogger
{
    /**
     * Record an activity log entry.
     *
     * @param string      $action        Action performed: created, updated, deleted, published, login, etc.
     * @param string      $resourceType  Resource type: entry, collection, theme, user, role, media, settings
     * @param string|null $resourceId    ID or slug of the resource
     * @param string|null $resourceLabel Human-readable label (name/title)
     * @param array|null  $meta          Extra context (collection slug, old values, etc.)
     */
    public static function log(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?string $resourceLabel = null,
        ?array $meta = null
    ): void {
        try {
            $log = new ActivityLog();
            $log->setAction($action);
            $log->setResourceType($resourceType);
            $log->setResourceId($resourceId);
            $log->setResourceLabel($resourceLabel);
            $log->setMeta($meta);
            $log->setIpAddress($_SERVER['REMOTE_ADDR'] ?? null);

            // Capture current user
            if (Auth::check()) {
                $user = Auth::user();
                $log->setUserId($user->getId());
                $log->setUserName($user->getEmail() ?? $user->getName() ?? null);
            }

            $log->save();
        } catch (\Exception $e) {
            // Activity logging should never break the application
        }
    }

    /**
     * Get recent activity logs with pagination.
     */
    public static function recent(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $sm = $conn->createSchemaManager();

            if (!$sm->tablesExist(['cms_activity_log'])) {
                return ['data' => [], 'total' => 0, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => 1];
            }

            $qb = $conn->createQueryBuilder()
                ->select('*')
                ->from('cms_activity_log');

            // Apply filters
            if (!empty($filters['action'])) {
                $qb->andWhere('action = :action')
                    ->setParameter('action', $filters['action']);
            }
            if (!empty($filters['resource_type'])) {
                $qb->andWhere('resource_type = :resource_type')
                    ->setParameter('resource_type', $filters['resource_type']);
            }
            if (!empty($filters['user_id'])) {
                $qb->andWhere('user_id = :user_id')
                    ->setParameter('user_id', $filters['user_id']);
            }
            if (!empty($filters['search'])) {
                $qb->andWhere('(resource_label LIKE :search OR user_name LIKE :search)')
                    ->setParameter('search', '%' . $filters['search'] . '%');
            }

            // Count
            $countQb = clone $qb;
            $countQb->select('COUNT(*)');
            $total = (int) $countQb->executeQuery()->fetchOne();

            // Paginate
            $offset = ($page - 1) * $perPage;
            $qb->orderBy('createdAt', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($perPage);

            $data = $qb->executeQuery()->fetchAllAssociative();

            return [
                'data' => $data,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ];
        } catch (\Exception $e) {
            return ['data' => [], 'total' => 0, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => 1];
        }
    }
}
