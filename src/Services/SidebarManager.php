<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Dynamic Sidebar Manager.
 *
 * Allows the CMS core and plugins to register sidebar items.
 * Items are grouped into sections with labels, sorted by position,
 * and permission-gated.
 *
 * Security:
 * - All items are permission-checked at render time
 * - Icon values are validated (alphanumeric + hyphens only)
 * - URLs must start with / (internal) or be absolute https
 * - No user input is rendered without escaping (handled in Twig)
 */
class SidebarManager
{
    private static ?SidebarManager $instance = null;

    /**
     * @var array<string, array{label: string, position: int, items: array}>
     * Sections indexed by ID with label, position, and items.
     */
    private array $sections = [];

    /**
     * Allowed SVG icon identifiers — maps to SVG markup in the template.
     * Plugins reference icons by name; the template resolves to actual SVG.
     */
    private const BUILT_IN_ICONS = [
        'home', 'folder', 'image', 'palette', 'users', 'shield',
        'key', 'user', 'database', 'settings', 'file', 'arrow-left',
        'code', 'globe', 'mail', 'bell', 'search', 'chart',
        'puzzle', 'package', 'terminal', 'link', 'layers', 'grid',
        'zap', 'heart', 'star', 'tag', 'bookmark', 'clock',
        'upload', 'download', 'trash', 'edit', 'eye', 'lock',
        'unlock', 'cloud', 'server', 'cpu', 'activity', 'bar-chart',
    ];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a sidebar section (group header).
     *
     * @param string $id Unique section ID (e.g., 'content', 'admin', 'plugin-seo')
     * @param string $label Display label (e.g., 'Content', 'Admin')
     * @param int $position Sort order (lower = higher in sidebar)
     */
    public function addSection(string $id, string $label, int $position = 50, string $icon = '', string $defaultUrl = ''): void
    {
        $this->validateId($id);

        $this->sections[$id] = [
            'label' => $label,
            'position' => $position,
            'icon' => $icon,
            'default_url' => $defaultUrl,
            'items' => $this->sections[$id]['items'] ?? [],
        ];
    }

    /**
     * Register a sidebar navigation item.
     *
     * @param string $section Section ID this item belongs to (e.g., 'content')
     * @param array{
     *   id: string,
     *   label: string,
     *   url: string,
     *   icon?: string,
     *   permission?: string,
     *   position?: int,
     *   badge?: string,
     *   match?: string,
     *   children?: array
     * } $item
     */
    public function addItem(string $section, array $item): void
    {
        $this->validateId($item['id'] ?? '');
        $this->validateUrl($item['url'] ?? '');

        if (isset($item['icon']) && $item['icon'] !== '') {
            $this->validateIcon($item['icon']);
        }

        $item = array_merge([
            'position' => 50,
            'permission' => null,
            'icon' => null,
            'badge' => null,
            'match' => null,
            'children' => [],
        ], $item);

        // Ensure section exists
        if (!isset($this->sections[$section])) {
            $this->sections[$section] = [
                'label' => ucfirst($section),
                'position' => 50,
                'items' => [],
            ];
        }

        $this->sections[$section]['items'][] = $item;
    }

    /**
     * Register a child/sub-item under an existing parent item.
     *
     * @param string $parentId The parent item's ID
     * @param array{id: string, label: string, url: string, permission?: string, match?: string} $child
     */
    public function addChild(string $parentId, array $child): void
    {
        $this->validateId($child['id'] ?? '');
        $this->validateUrl($child['url'] ?? '');

        foreach ($this->sections as &$section) {
            foreach ($section['items'] as &$item) {
                if (($item['id'] ?? '') === $parentId) {
                    $child = array_merge([
                        'permission' => null,
                        'match' => null,
                    ], $child);
                    $item['children'][] = $child;
                    return;
                }
            }
        }
    }

    /**
     * Remove a sidebar item by ID.
     */
    public function removeItem(string $itemId): void
    {
        foreach ($this->sections as &$section) {
            $section['items'] = array_values(array_filter(
                $section['items'],
                fn(array $item): bool => ($item['id'] ?? '') !== $itemId
            ));
        }
    }

    /**
     * Remove an entire section.
     */
    public function removeSection(string $sectionId): void
    {
        unset($this->sections[$sectionId]);
    }

    /**
     * Get all sections with items, sorted and permission-filtered.
     *
     * @return array<int, array{id: string, label: string, items: array}>
     */
    public function getSections(): array
    {
        $sections = [];

        // Sort sections by position
        $sorted = $this->sections;
        uasort($sorted, fn(array $a, array $b): int => $a['position'] <=> $b['position']);

        foreach ($sorted as $id => $section) {
            // Filter items by permission
            $items = $this->filterByPermission($section['items']);

            // Sort items by position
            usort($items, fn(array $a, array $b): int => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

            // Filter children by permission
            foreach ($items as &$item) {
                if (!empty($item['children'])) {
                    $item['children'] = $this->filterByPermission($item['children']);
                }
            }

            // Only include section if it has visible items
            if (!empty($items)) {
                $sections[] = [
                    'id' => $id,
                    'label' => $section['label'],
                    'icon' => $section['icon'] ?? '',
                    'default_url' => $section['default_url'] ?? '',
                    'items' => $items,
                ];
            }
        }

        return $sections;
    }

    /**
     * Get a flat list of all items (for search/lookup).
     *
     * @return array<int, array>
     */
    public function getAllItems(): array
    {
        $items = [];
        foreach ($this->sections as $section) {
            foreach ($section['items'] as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Register the default CMS sidebar items.
     * Called by CmsServiceProvider during boot.
     */
    public function registerDefaults(): void
    {
        // ── Home (direct link — no sidebar panel) ──
        $this->addSection('home', '', 0, 'home', admin_url());

        // ── Collections (main content management) ──
        $this->addSection('collections', 'Collections', 5, 'database', admin_url('collections'));

        // ── Media (direct link — no sidebar panel) ──
        $this->addSection('media', '', 8, 'image', admin_url('media'));

        // ── Design (theming, AI, marketplace) ──
        $this->addSection('design', 'Design', 20, 'palette', admin_url('themes'));
        $this->addItem('design', [
            'id' => 'themes',
            'label' => 'Themes',
            'url' => admin_url('themes'),
            'icon' => 'palette',
            'position' => 1,
            'match' => 'prefix:' . admin_url('themes'),
        ]);

        // ── Admin (users, roles, permissions, keys) ──
        $this->addSection('admin', 'Admin', 30, 'users', admin_url('users'));
        $this->addItem('admin', [
            'id' => 'users',
            'label' => 'Users',
            'url' => admin_url('users'),
            'icon' => 'users',
            'permission' => 'users.view',
            'position' => 1,
            'match' => 'prefix:' . admin_url('users'),
        ]);
        $this->addItem('admin', [
            'id' => 'roles',
            'label' => 'Roles',
            'url' => admin_url('roles'),
            'icon' => 'shield',
            'permission' => 'roles.manage',
            'position' => 2,
            'match' => 'prefix:' . admin_url('roles'),
        ]);
        $this->addItem('admin', [
            'id' => 'api-keys',
            'label' => 'API Keys',
            'url' => admin_url('api-keys'),
            'icon' => 'key',
            'permission' => 'api-keys.manage',
            'position' => 3,
            'match' => 'prefix:' . admin_url('api-keys'),
        ]);

        // ── Settings (app configuration) ──
        $this->addSection('settings', 'Settings', 40, 'settings', admin_url('settings/system'));
        $this->addItem('settings', [
            'id' => 'system',
            'label' => 'System',
            'url' => admin_url('settings/system'),
            'icon' => 'settings',
            'permission' => 'settings.view',
            'position' => 1,
            'match' => 'prefix:' . admin_url('settings/system'),
        ]);
        $this->addItem('settings', [
            'id' => 'database',
            'label' => 'Database',
            'url' => admin_url('settings/database'),
            'icon' => 'database',
            'permission' => 'settings.view',
            'position' => 2,
            'match' => 'prefix:' . admin_url('settings/database'),
        ]);
        $this->addItem('settings', [
            'id' => 'assets',
            'label' => 'Assets',
            'url' => admin_url('settings/assets'),
            'icon' => 'file',
            'permission' => 'settings.view',
            'position' => 3,
            'match' => 'prefix:' . admin_url('settings/assets'),
        ]);
        $this->addItem('settings', [
            'id' => 'profile',
            'label' => 'Profile',
            'url' => admin_url('settings/profile'),
            'icon' => 'user',
            'position' => 10,
            'match' => 'prefix:' . admin_url('settings/profile'),
        ]);

        // ── System (developer tools) ──
        $this->addSection('system', 'System', 45, 'terminal', admin_url('system/health'));

        // ── Footer (back to site — globe icon) ──
        $this->addSection('footer', '', 100, 'globe', '/');
        $this->addItem('footer', [
            'id' => 'back-to-site',
            'label' => 'Visit Site',
            'url' => '/',
            'icon' => 'globe',
            'position' => 1,
        ]);
    }

    /**
     * Check if a URL matches the current request path.
     *
     * @param string $currentPath The current request path
     * @param array $item The sidebar item
     */
    public static function isActive(string $currentPath, array $item): bool
    {
        $match = $item['match'] ?? null;
        $url = $item['url'] ?? '';

        if ($match === null) {
            // Default: prefix match
            return str_starts_with($currentPath, $url) && $url !== '/';
        }

        if (str_starts_with($match, 'exact:')) {
            return $currentPath === substr($match, 6);
        }

        if (str_starts_with($match, 'prefix:')) {
            $prefix = substr($match, 7);
            return str_starts_with($currentPath, $prefix);
        }

        // Regex match
        if (str_starts_with($match, 'regex:')) {
            $pattern = substr($match, 6);
            return (bool) preg_match($pattern, $currentPath);
        }

        return false;
    }

    /**
     * Filter items by permission using PermissionService.
     */
    private function filterByPermission(array $items): array
    {
        return array_values(array_filter($items, function (array $item): bool {
            $permission = $item['permission'] ?? null;
            if ($permission === null) {
                return true;
            }
            return PermissionService::can($permission);
        }));
    }

    /**
     * Validate an item/section ID.
     *
     * @throws \InvalidArgumentException
     */
    private function validateId(string $id): void
    {
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new \InvalidArgumentException(
                "Invalid sidebar item ID '{$id}'. Only letters, numbers, underscores, and hyphens are allowed."
            );
        }
    }

    /**
     * Validate a URL (must be internal path or absolute https).
     *
     * @throws \InvalidArgumentException
     */
    private function validateUrl(string $url): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException('Sidebar item URL cannot be empty.');
        }

        // Allow anchor-only URLs (used for section headers)
        if ($url === '#') {
            return;
        }

        // Internal paths must start with /
        if (str_starts_with($url, '/')) {
            // Prevent path traversal
            if (str_contains($url, '..') || str_contains($url, '//')) {
                throw new \InvalidArgumentException("Invalid sidebar URL '{$url}'.");
            }
            return;
        }

        // External URLs must be https
        if (str_starts_with($url, 'https://')) {
            return;
        }

        throw new \InvalidArgumentException(
            "Sidebar URL '{$url}' must start with / (internal) or https:// (external)."
        );
    }

    /**
     * Validate an icon name.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIcon(string $icon): void
    {
        if (!preg_match('/^[a-z0-9-]+$/', $icon)) {
            throw new \InvalidArgumentException(
                "Invalid icon name '{$icon}'. Only lowercase letters, numbers, and hyphens are allowed."
            );
        }
    }

    /**
     * Get list of built-in icon names.
     *
     * @return string[]
     */
    public static function getBuiltInIcons(): array
    {
        return self::BUILT_IN_ICONS;
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->sections = [];
        }
        self::$instance = null;
    }
}
