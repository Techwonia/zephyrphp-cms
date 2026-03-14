<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\WorkflowTransition;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\WorkflowService;

class WorkflowVisualizerController extends Controller
{
    private const STAGE_COLORS = [
        'draft'     => 'gray',
        'review'    => 'yellow',
        'approved'  => 'blue',
        'published' => 'green',
        'rejected'  => 'red',
    ];

    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission('entries.view');

        $schema = new SchemaManager();
        $conn = $schema->getConnection();

        $collections = Collection::findBy(['workflowEnabled' => true]);
        $workflowCollections = [];

        foreach ($collections as $collection) {
            $tableName = $collection->getTableName();

            if (!$schema->tableExists($tableName)) {
                continue;
            }

            $stages = WorkflowService::getStages($collection);
            if (empty($stages)) {
                continue;
            }

            // Query entry counts grouped by status
            $rows = $conn->createQueryBuilder()
                ->select('status', 'COUNT(*) as cnt')
                ->from($tableName)
                ->groupBy('status')
                ->executeQuery()
                ->fetchAllAssociative();

            $statusCounts = [];
            $totalEntries = 0;
            foreach ($rows as $row) {
                $statusCounts[$row['status']] = (int) $row['cnt'];
                $totalEntries += (int) $row['cnt'];
            }

            // Build stage data with counts and colors
            $stageData = [];
            $bottleneckStage = null;
            $bottleneckCount = 0;

            foreach ($stages as $stage) {
                $count = $statusCounts[$stage] ?? 0;
                $stageData[] = [
                    'name'  => $stage,
                    'count' => $count,
                    'color' => self::STAGE_COLORS[$stage] ?? 'gray',
                ];

                if ($count > $bottleneckCount) {
                    $bottleneckCount = $count;
                    $bottleneckStage = $stage;
                }
            }

            $workflowCollections[] = [
                'name'       => $collection->getName(),
                'slug'       => $collection->getSlug(),
                'icon'       => $collection->getIcon(),
                'stages'     => $stageData,
                'total'      => $totalEntries,
                'bottleneck' => $bottleneckStage,
            ];
        }

        // Get recent transitions (last 20)
        $recentTransitions = WorkflowTransition::findBy(
            [],
            ['createdAt' => 'DESC'],
            20
        );

        // Map table names back to collection names for display
        $tableToCollection = [];
        foreach ($collections as $collection) {
            $tableToCollection[$collection->getTableName()] = $collection->getName();
        }

        $transitions = [];
        foreach ($recentTransitions as $transition) {
            $transitions[] = [
                'date'       => $transition->getCreatedAt()?->format('Y-m-d H:i'),
                'collection' => $tableToCollection[$transition->getTableName()] ?? $transition->getTableName(),
                'entry_id'   => $transition->getEntryId(),
                'from'       => $transition->getFromStage(),
                'to'         => $transition->getToStage(),
                'user'       => $transition->getUserName() ?? ('User #' . $transition->getUserId()),
                'action'     => $transition->getAction(),
            ];
        }

        return $this->render('cms::system/workflow-visualizer', [
            'collections'  => $workflowCollections,
            'transitions'  => $transitions,
            'stageColors'  => self::STAGE_COLORS,
            'user'         => Auth::user(),
        ]);
    }
}
