<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Dashboard Widget Manager.
 *
 * Allows the CMS core and plugins to register dashboard widgets.
 * Widgets are rendered on the CMS home page in a configurable grid.
 *
 * Security:
 * - Widgets are permission-gated at render time
 * - Template paths are validated (no path traversal)
 * - Widget IDs are alphanumeric only
 */
class DashboardManager
{
    private static ?DashboardManager $instance = null;

    /** @var array<string, array> Registered widgets indexed by ID. */
    private array $widgets = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a dashboard widget.
     *
     * @param string $id Unique widget ID
     * @param array{
     *   title: string,
     *   template: string,
     *   permission?: string,
     *   size?: string,
     *   position?: int,
     *   data?: callable|array,
     * } $config
     *
     * size: 'full' (100% width), 'half' (50%), 'third' (33%)
     * data: Static array or callable that returns template variables
     */
    public function register(string $id, array $config): void
    {
        $this->validateId($id);
        $this->validateTemplate($config['template'] ?? '');

        $this->widgets[$id] = array_merge([
            'title' => $id,
            'template' => '',
            'permission' => null,
            'size' => 'half',
            'position' => 50,
            'data' => [],
        ], $config);
    }

    /**
     * Remove a widget by ID.
     */
    public function remove(string $id): void
    {
        unset($this->widgets[$id]);
    }

    /**
     * Get all widgets, filtered by permission, sorted by position.
     *
     * @return array<string, array>
     */
    public function getWidgets(): array
    {
        $widgets = array_filter($this->widgets, function (array $widget): bool {
            $permission = $widget['permission'] ?? null;
            if ($permission === null) {
                return true;
            }
            return PermissionService::can($permission);
        });

        uasort($widgets, fn(array $a, array $b): int => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        // Resolve callable data providers
        foreach ($widgets as $id => &$widget) {
            if (is_callable($widget['data'])) {
                try {
                    $widget['data'] = ($widget['data'])();
                    if (!is_array($widget['data'])) {
                        $widget['data'] = [];
                    }
                } catch (\Throwable $e) {
                    $widget['data'] = ['_error' => 'Widget data failed to load.'];
                }
            }
        }

        return $widgets;
    }

    /**
     * Check if any widgets are registered.
     */
    public function hasWidgets(): bool
    {
        return !empty($this->widgets);
    }

    /**
     * Get the CSS class for a widget size.
     */
    public static function sizeClass(string $size): string
    {
        return match ($size) {
            'full' => 'widget-full',
            'third' => 'widget-third',
            default => 'widget-half',
        };
    }

    private function validateId(string $id): void
    {
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new \InvalidArgumentException(
                "Invalid widget ID '{$id}'. Only letters, numbers, underscores, and hyphens are allowed."
            );
        }
    }

    private function validateTemplate(string $template): void
    {
        if ($template === '') {
            throw new \InvalidArgumentException('Widget template path cannot be empty.');
        }

        if (str_contains($template, '..')) {
            throw new \InvalidArgumentException("Invalid widget template path '{$template}'.");
        }
    }

    /**
     * Register the built-in CMS dashboard widgets.
     */
    public function registerBuiltInWidgets(): void
    {
        $this->register('recent-entries', [
            'title' => 'Recent Entries',
            'template' => '@cms/widgets/recent-entries.twig',
            'size' => 'half',
            'position' => 10,
            'data' => fn() => ['entries' => AnalyticsService::getRecentEntries(8)],
        ]);

        $this->register('entry-counts', [
            'title' => 'Collection Stats',
            'template' => '@cms/widgets/entry-counts.twig',
            'size' => 'half',
            'position' => 20,
            'data' => fn() => ['counts' => AnalyticsService::getEntryCountsByCollection()],
        ]);

        $this->register('recent-activity', [
            'title' => 'Recent Activity',
            'template' => '@cms/widgets/recent-activity.twig',
            'size' => 'half',
            'position' => 30,
            'data' => fn() => ['logs' => ActivityLogger::recent(1, 8)['data'] ?? []],
        ]);

        $this->register('draft-count', [
            'title' => 'Drafts',
            'template' => '@cms/widgets/draft-count.twig',
            'size' => 'third',
            'position' => 40,
            'data' => fn() => ['count' => AnalyticsService::getDraftCount()],
        ]);

        $this->register('scheduled-posts', [
            'title' => 'Scheduled',
            'template' => '@cms/widgets/scheduled-posts.twig',
            'size' => 'third',
            'position' => 50,
            'data' => fn() => [
                'count' => AnalyticsService::getScheduledCount(),
                'entries' => AnalyticsService::getScheduledEntries(5),
            ],
        ]);

        $this->register('media-usage', [
            'title' => 'Media',
            'template' => '@cms/widgets/media-usage.twig',
            'size' => 'third',
            'position' => 60,
            'data' => fn() => ['stats' => AnalyticsService::getMediaUsageStats()],
        ]);

        $this->register('entries-chart', [
            'title' => 'Entries (Last 30 Days)',
            'template' => '@cms/widgets/entries-chart.twig',
            'size' => 'full',
            'position' => 70,
            'data' => fn() => ['chart_data' => AnalyticsService::getEntriesOverTime(30)],
        ]);

        $this->register('quick-actions', [
            'title' => 'Quick Actions',
            'template' => '@cms/widgets/quick-actions.twig',
            'size' => 'half',
            'position' => 5,
            'data' => fn() => ['collections' => \ZephyrPHP\Cms\Models\Collection::findAll()],
        ]);

        $this->register('pending-reviews', [
            'title' => 'Pending Reviews',
            'template' => '@cms/widgets/pending-reviews.twig',
            'size' => 'half',
            'position' => 25,
            'data' => fn() => [
                'reviews' => WorkflowService::getPendingReviews(
                    \ZephyrPHP\Auth\Auth::user()?->getId()
                ),
            ],
        ]);
    }

    /**
     * Get a user's dashboard layout, or return default layout.
     */
    public function getUserLayout(int $userId): array
    {
        try {
            $layout = \ZephyrPHP\Cms\Models\DashboardLayout::findOneBy(['userId' => $userId]);
            if ($layout) {
                return $layout->getLayout();
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        // Default: all widgets visible in their registered order
        $defaults = [];
        $position = 0;
        foreach ($this->widgets as $id => $widget) {
            $defaults[] = [
                'widget_id' => $id,
                'position' => $position++,
                'size' => $widget['size'] ?? 'half',
                'visible' => true,
            ];
        }
        return $defaults;
    }

    /**
     * Save a user's dashboard layout.
     */
    public function saveUserLayout(int $userId, array $layout): void
    {
        try {
            $existing = \ZephyrPHP\Cms\Models\DashboardLayout::findOneBy(['userId' => $userId]);
            if ($existing) {
                $existing->setLayout($layout);
                $existing->save();
            } else {
                $dl = new \ZephyrPHP\Cms\Models\DashboardLayout();
                $dl->setUserId($userId);
                $dl->setLayout($layout);
                $dl->save();
            }
        } catch (\Exception $e) {
            // Silently fail — dashboard layout is non-critical
        }
    }

    /**
     * Get widgets ordered and filtered by user layout.
     */
    public function getWidgetsForUser(int $userId): array
    {
        $allWidgets = $this->getWidgets();
        $layout = $this->getUserLayout($userId);

        if (empty($layout)) {
            return $allWidgets;
        }

        // Build ordered list from layout
        $ordered = [];
        foreach ($layout as $item) {
            $wid = $item['widget_id'] ?? '';
            if (!isset($allWidgets[$wid])) continue;
            if (!($item['visible'] ?? true)) continue;

            $widget = $allWidgets[$wid];
            $widget['size'] = $item['size'] ?? $widget['size'];
            $ordered[$wid] = $widget;
        }

        // Add any new widgets not in the layout
        foreach ($allWidgets as $id => $widget) {
            if (!isset($ordered[$id])) {
                $ordered[$id] = $widget;
            }
        }

        return $ordered;
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->widgets = [];
        }
        self::$instance = null;
    }
}
