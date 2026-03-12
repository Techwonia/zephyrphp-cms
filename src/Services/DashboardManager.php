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
