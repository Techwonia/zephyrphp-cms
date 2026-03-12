<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Settings Page Manager.
 *
 * Allows plugins to register their own settings pages
 * that appear under the Settings section in the CMS sidebar.
 *
 * Each registered settings page gets a route at /cms/settings/{slug}
 * and a sidebar item under the Settings section.
 *
 * Security:
 * - All settings pages require authentication + permission check
 * - Template paths validated (no traversal)
 * - Slugs validated (alphanumeric + hyphens only)
 */
class SettingsManager
{
    private static ?SettingsManager $instance = null;

    /** @var array<string, array> Registered settings pages indexed by slug. */
    private array $pages = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a settings page.
     *
     * @param string $slug URL slug (e.g., 'seo' → /cms/settings/seo)
     * @param array{
     *   label: string,
     *   template: string,
     *   permission?: string,
     *   icon?: string,
     *   position?: int,
     *   data?: callable|array,
     *   saveHandler?: callable,
     * } $config
     *
     * data: Static array or callable returning template variables
     * saveHandler: Callable that processes POST data for this settings page
     */
    public function register(string $slug, array $config): void
    {
        $this->validateSlug($slug);

        if (empty($config['template'])) {
            throw new \InvalidArgumentException("Settings page '{$slug}' must have a template.");
        }

        if (str_contains($config['template'], '..')) {
            throw new \InvalidArgumentException("Invalid template path for settings page '{$slug}'.");
        }

        $this->pages[$slug] = array_merge([
            'label' => ucfirst($slug),
            'template' => '',
            'permission' => 'settings.view',
            'icon' => 'settings',
            'position' => 50,
            'data' => [],
            'saveHandler' => null,
        ], $config);
    }

    /**
     * Remove a settings page by slug.
     */
    public function remove(string $slug): void
    {
        unset($this->pages[$slug]);
    }

    /**
     * Get a specific settings page config by slug.
     *
     * @return array|null
     */
    public function getPage(string $slug): ?array
    {
        return $this->pages[$slug] ?? null;
    }

    /**
     * Get all registered settings pages, filtered by permission.
     *
     * @return array<string, array>
     */
    public function getPages(): array
    {
        $pages = array_filter($this->pages, function (array $page): bool {
            $permission = $page['permission'] ?? null;
            if ($permission === null) {
                return true;
            }
            return PermissionService::can($permission);
        });

        uasort($pages, fn(array $a, array $b): int => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        return $pages;
    }

    /**
     * Resolve data for a settings page (call data callable if needed).
     */
    public function resolveData(string $slug): array
    {
        $page = $this->pages[$slug] ?? null;
        if ($page === null) {
            return [];
        }

        $data = $page['data'] ?? [];
        if (is_callable($data)) {
            try {
                $data = $data();
                if (!is_array($data)) {
                    $data = [];
                }
            } catch (\Throwable $e) {
                $data = ['_error' => 'Failed to load settings data.'];
            }
        }

        return $data;
    }

    /**
     * Execute the save handler for a settings page.
     *
     * @param string $slug The settings page slug
     * @param array $input The POST data to save
     * @return bool True if saved successfully
     */
    public function save(string $slug, array $input): bool
    {
        $page = $this->pages[$slug] ?? null;
        if ($page === null || !is_callable($page['saveHandler'] ?? null)) {
            return false;
        }

        return (bool) ($page['saveHandler'])($input);
    }

    /**
     * Register settings pages as sidebar items in the SidebarManager.
     */
    public function registerSidebarItems(SidebarManager $sidebar): void
    {
        foreach ($this->pages as $slug => $config) {
            $sidebar->addItem('settings', [
                'id' => 'settings-' . $slug,
                'label' => $config['label'],
                'url' => '/cms/settings/' . $slug,
                'icon' => $config['icon'] ?? 'settings',
                'permission' => $config['permission'] ?? 'settings.view',
                'position' => $config['position'] ?? 50,
                'match' => 'prefix:/cms/settings/' . $slug,
            ]);
        }
    }

    private function validateSlug(string $slug): void
    {
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new \InvalidArgumentException(
                "Invalid settings page slug '{$slug}'. Only lowercase letters, numbers, and hyphens are allowed."
            );
        }

        if (strlen($slug) > 64) {
            throw new \InvalidArgumentException("Settings page slug '{$slug}' exceeds 64 characters.");
        }

        // Prevent overriding built-in settings pages
        $reserved = ['profile', 'database', 'system', 'assets'];
        if (in_array($slug, $reserved, true)) {
            throw new \InvalidArgumentException(
                "Settings page slug '{$slug}' is reserved by the CMS core."
            );
        }
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->pages = [];
        }
        self::$instance = null;
    }
}
