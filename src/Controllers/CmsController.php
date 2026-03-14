<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\DashboardManager;

class CmsController extends Controller
{
    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect('/login');
        }
    }

    public function dashboard(): string
    {
        $this->requireCmsAccess();

        $userId = Auth::user()?->getId() ?? 0;
        $dashboard = DashboardManager::getInstance();
        $widgets = $dashboard->getWidgetsForUser($userId);
        $layout = $dashboard->getUserLayout($userId);

        $collections = Collection::findAll();
        $schema = new SchemaManager();

        $stats = [];
        $totalEntries = 0;
        foreach ($collections as $collection) {
            $count = $schema->countEntries($collection->getTableName());
            $stats[$collection->getSlug()] = $count;
            $totalEntries += $count;
        }

        $totalMedia = Media::count();

        return $this->render('cms::dashboard', [
            'collections' => $collections,
            'stats' => $stats,
            'totalCollections' => count($collections),
            'totalEntries' => $totalEntries,
            'totalMedia' => $totalMedia,
            'widgets' => $widgets,
            'layout' => $layout,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Save dashboard widget layout (AJAX).
     */
    public function saveLayout(): void
    {
        $this->requireCmsAccess();

        $userId = Auth::user()?->getId();
        if (!$userId) {
            $this->json(['error' => 'Not authenticated.'], 401);
            return;
        }

        $layoutJson = $this->input('layout', '');
        $layout = json_decode($layoutJson, true);

        if (!is_array($layout)) {
            $this->json(['error' => 'Invalid layout data.'], 400);
            return;
        }

        // Sanitize layout items
        $sanitized = [];
        foreach ($layout as $item) {
            if (empty($item['widget_id']) || !is_string($item['widget_id'])) continue;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $item['widget_id'])) continue;

            $sanitized[] = [
                'widget_id' => $item['widget_id'],
                'position' => (int) ($item['position'] ?? 0),
                'size' => in_array($item['size'] ?? '', ['full', 'half', 'third']) ? $item['size'] : 'half',
                'visible' => (bool) ($item['visible'] ?? true),
            ];
        }

        DashboardManager::getInstance()->saveUserLayout($userId, $sanitized);
        $this->json(['success' => true]);
    }

    /**
     * Manually trigger scheduled entry publishing via GUI.
     */
    public function publishScheduled(): void
    {
        $this->requireCmsAccess();

        if (!PermissionService::can('entries.edit')) {
            $this->flash('errors', ['You do not have permission to publish entries.']);
            $this->redirect('/cms');
            return;
        }

        $collections = Collection::findAll();
        $schema = new SchemaManager();
        $conn = \ZephyrPHP\Database\DB::connection();
        $now = date('Y-m-d H:i:s');
        $published = 0;

        foreach ($collections as $collection) {
            $tableName = $collection->getTableName();
            $sm = $conn->createSchemaManager();

            if (!$sm->tablesExist([$tableName])) {
                continue;
            }

            $columns = $sm->listTableColumns($tableName);
            if (!isset($columns['scheduled_at'])) {
                continue;
            }

            try {
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
                    ], ['id' => $entry['id']]);
                    $published++;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($published > 0) {
            $this->flash('success', "{$published} scheduled " . ($published === 1 ? 'entry' : 'entries') . ' published.');
        } else {
            $this->flash('success', 'No scheduled entries are due for publishing.');
        }

        $this->redirect('/cms');
    }
}
