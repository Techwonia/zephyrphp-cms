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

    private const AVAILABLE_COLORS = [
        'gray', 'yellow', 'blue', 'green', 'red', 'purple', 'orange', 'teal', 'pink', 'indigo',
    ];

    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect(login_url());
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect(admin_url());
        }
    }

    public function index(): string
    {
        $this->requirePermission('entries.view');

        $schema = SchemaManager::getInstance();
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

            $reviewers = $collection->getWorkflowReviewers();

            $workflowCollections[] = [
                'name'       => $collection->getName(),
                'slug'       => $collection->getSlug(),
                'icon'       => $collection->getIcon(),
                'stages'     => $stageData,
                'total'      => $totalEntries,
                'bottleneck' => $bottleneckStage,
                'reviewers'  => $reviewers,
            ];
        }

        // Get all collections (for the "enable workflow" dropdown)
        $allCollections = Collection::findAll();

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

        // Get all users for reviewer assignment
        $users = [];
        try {
            $userModel = '\\ZephyrPHP\\Auth\\Models\\User';
            if (class_exists($userModel)) {
                $allUsers = $userModel::findAll();
                foreach ($allUsers as $u) {
                    $users[] = [
                        'id'   => $u->getId(),
                        'name' => $u->getName(),
                    ];
                }
            }
        } catch (\Exception $e) {
            // Users unavailable
        }

        return $this->render('cms::system/workflow-visualizer', [
            'collections'     => $workflowCollections,
            'allCollections'  => $allCollections,
            'transitions'     => $transitions,
            'stageColors'     => self::STAGE_COLORS,
            'availableColors' => self::AVAILABLE_COLORS,
            'users'           => $users,
            'user'            => Auth::user(),
        ]);
    }

    /**
     * Enable workflow on a collection.
     */
    public function enable(): void
    {
        $this->requirePermission('entries.edit');

        $slug = $this->input('slug', '');
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid collection.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['Collection not found.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection->setWorkflowEnabled(true);

        // Set default stages if none exist
        if (empty($collection->getWorkflowStages())) {
            $collection->setWorkflowStages(['draft', 'review', 'approved', 'published']);
        }

        $collection->save();

        $this->flash('success', "Workflow enabled for '{$collection->getName()}'.");
        $this->redirect(admin_url('system/workflow'));
    }

    /**
     * Disable workflow on a collection.
     */
    public function disable(): void
    {
        $this->requirePermission('entries.edit');

        $slug = $this->input('slug', '');
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid collection.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['Collection not found.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection->setWorkflowEnabled(false);
        $collection->save();

        $this->flash('success', "Workflow disabled for '{$collection->getName()}'.");
        $this->redirect(admin_url('system/workflow'));
    }

    /**
     * Save workflow stages configuration for a collection.
     */
    public function saveStages(): void
    {
        $this->requirePermission('entries.edit');

        $slug = $this->input('slug', '');
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid collection.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection || !$collection->isWorkflowEnabled()) {
            $this->flash('errors', ['Collection not found or workflow not enabled.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $stagesInput = $this->input('stages', []);
        if (!is_array($stagesInput) || empty($stagesInput)) {
            $this->flash('errors', ['At least one stage is required.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        // Validate and sanitize stage names
        $stages = [];
        foreach ($stagesInput as $stage) {
            $stage = trim((string) $stage);
            // Only allow alphanumeric, underscores, hyphens
            $stage = preg_replace('/[^a-z0-9_-]/', '', strtolower($stage));
            if ($stage !== '' && !in_array($stage, $stages, true)) {
                $stages[] = $stage;
            }
        }

        if (count($stages) < 2) {
            $this->flash('errors', ['At least 2 unique stages are required.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        if (count($stages) > 20) {
            $this->flash('errors', ['Maximum 20 stages allowed.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection->setWorkflowStages($stages);
        $collection->save();

        $this->flash('success', "Workflow stages updated for '{$collection->getName()}'.");
        $this->redirect(admin_url('system/workflow'));
    }

    /**
     * Save reviewer assignments for workflow stages.
     */
    public function saveReviewers(): void
    {
        $this->requirePermission('entries.edit');

        $slug = $this->input('slug', '');
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid collection.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection || !$collection->isWorkflowEnabled()) {
            $this->flash('errors', ['Collection not found or workflow not enabled.']);
            $this->redirect(admin_url('system/workflow'));
            return;
        }

        $reviewersInput = $this->input('reviewers', []);
        if (!is_array($reviewersInput)) {
            $reviewersInput = [];
        }

        // Sanitize: reviewers is keyed by stage name, value is array of user IDs (ints)
        $stages = $collection->getWorkflowStages();
        $reviewers = [];
        foreach ($stages as $stage) {
            if (isset($reviewersInput[$stage]) && is_array($reviewersInput[$stage])) {
                $reviewers[$stage] = array_map('intval', array_filter($reviewersInput[$stage], 'is_numeric'));
            }
        }

        $collection->setWorkflowReviewers($reviewers);
        $collection->save();

        $this->flash('success', "Reviewers updated for '{$collection->getName()}'.");
        $this->redirect(admin_url('system/workflow'));
    }
}
