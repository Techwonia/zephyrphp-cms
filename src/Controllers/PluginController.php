<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\PluginManager;
use ZephyrPHP\Cms\Services\HookManager;

/**
 * PluginController — CMS admin panel for managing plugins.
 *
 * Routes:
 *   GET  /admin/plugins              — List installed plugins
 *   GET  /admin/plugins/browse       — Browse marketplace plugins
 *   POST /admin/plugins/install      — Install from URL or upload
 *   POST /admin/plugins/{slug}/uninstall — Uninstall a plugin
 *   POST /admin/plugins/{slug}/toggle    — Enable/disable a plugin
 *   GET  /admin/plugins/{slug}/settings  — Plugin settings page
 *   POST /admin/plugins/{slug}/settings  — Save plugin settings
 */
class PluginController extends Controller
{
    use CmsAccessTrait;

    private PluginManager $pluginManager;

    public function __construct()
    {
        parent::__construct();
        $this->pluginManager = PluginManager::getInstance();
    }

    /**
     * GET /admin/plugins — List installed plugins.
     */
    public function index(): string
    {
        $this->requirePermission('apps.view');

        $plugins = $this->pluginManager->getInstalledPlugins();
        $filter = $this->input('filter', 'all');
        $search = trim((string) $this->input('search', ''));

        // Apply filter
        $filtered = $plugins;
        if ($filter === 'active') {
            $filtered = array_filter($filtered, fn(array $p) => $p['enabled']);
        } elseif ($filter === 'inactive') {
            $filtered = array_filter($filtered, fn(array $p) => !$p['enabled']);
        }

        // Apply search
        if ($search !== '') {
            $searchLower = strtolower($search);
            $filtered = array_filter($filtered, function (array $p) use ($searchLower) {
                $name = strtolower($p['manifest']['name'] ?? '');
                $desc = strtolower($p['manifest']['description'] ?? '');
                $author = strtolower($p['manifest']['author'] ?? '');
                return str_contains($name, $searchLower) || str_contains($desc, $searchLower) || str_contains($author, $searchLower);
            });
        }

        $counts = [
            'all' => count($plugins),
            'active' => count(array_filter($plugins, fn(array $p) => $p['enabled'])),
            'inactive' => count(array_filter($plugins, fn(array $p) => !$p['enabled'])),
        ];

        return $this->render('cms::plugins/index', [
            'plugins' => array_values($filtered),
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
            'user' => Auth::user(),
        ]);
    }

    /**
     * GET /admin/plugins/browse — Browse marketplace for available plugins.
     */
    public function browse(): string
    {
        $this->requirePermission('apps.view');

        $marketplace = [];

        // Try to fetch from marketplace API if the client exists
        if (class_exists(\ZephyrPHP\Marketplace\MarketplaceClient::class)) {
            try {
                $client = \ZephyrPHP\Marketplace\MarketplaceClient::getInstance();
                $filters = [
                    'type' => 'plugin',
                    'search' => $this->input('search', ''),
                    'sort' => $this->input('sort', 'popular'),
                    'page' => max(1, (int) $this->input('page', 1)),
                ];
                $result = $client->browse(array_filter($filters));
                $marketplace = $result['items'] ?? [];
            } catch (\Throwable $e) {
                // Marketplace unavailable
            }
        }

        // Mark which ones are already installed
        $installed = $this->pluginManager->getInstalledPlugins();
        $installedSlugs = array_column(
            array_map(fn(array $p) => ['slug' => $p['slug']], $installed),
            'slug'
        );

        return $this->render('cms::plugins/browse', [
            'items' => $marketplace,
            'installedSlugs' => $installedSlugs,
            'search' => $this->input('search', ''),
            'sort' => $this->input('sort', 'popular'),
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /admin/plugins/install — Install a plugin from URL or ZIP upload.
     */
    public function install(): void
    {
        $this->requirePermission('apps.manage');

        $url = trim((string) $this->input('download_url', ''));

        // Check for file upload first
        if (!empty($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
            $this->handleZipUpload();
            return;
        }

        // Install from URL
        if ($url !== '') {
            $result = $this->pluginManager->installFromUrl($url);
            if ($result['success']) {
                $this->flash('success', 'Plugin installed successfully.');
            } else {
                $this->flash('errors', [$result['error'] ?? 'Installation failed.']);
            }
            $this->redirect(admin_url('plugins'));
            return;
        }

        $this->flash('errors', ['Please provide a download URL or upload a ZIP file.']);
        $this->redirect(admin_url('plugins'));
    }

    /**
     * Handle ZIP file upload for plugin installation.
     */
    private function handleZipUpload(): void
    {
        $file = $_FILES['plugin_zip'];

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

        if (!in_array($mimeType, $allowedMimes, true)) {
            $this->flash('errors', ['Only ZIP files are allowed.']);
            $this->redirect(admin_url('plugins'));
            return;
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('errors', ['Only .zip files are allowed.']);
            $this->redirect(admin_url('plugins'));
            return;
        }

        // Validate file size (50 MB max)
        if ($file['size'] > 50 * 1024 * 1024) {
            $this->flash('errors', ['Plugin archive exceeds maximum size (50 MB).']);
            $this->redirect(admin_url('plugins'));
            return;
        }

        // Move to temp and install
        $tempPath = sys_get_temp_dir() . '/zephyr_plugin_upload_' . bin2hex(random_bytes(8)) . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            $this->flash('errors', ['Failed to process uploaded file.']);
            $this->redirect(admin_url('plugins'));
            return;
        }

        $result = $this->pluginManager->installFromZip($tempPath);

        // Clean up
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        if ($result['success']) {
            $this->flash('success', 'Plugin installed successfully.');
        } else {
            $this->flash('errors', [$result['error'] ?? 'Installation failed.']);
        }

        $this->redirect(admin_url('plugins'));
    }

    /**
     * POST /admin/plugins/{slug}/uninstall — Remove a plugin.
     */
    public function uninstall(string $slug): void
    {
        $this->requirePermission('apps.manage');

        $result = $this->pluginManager->uninstall($slug);

        if ($result['success']) {
            $this->flash('success', 'Plugin uninstalled successfully.');
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to uninstall plugin.']);
        }

        $this->redirect(admin_url('plugins'));
    }

    /**
     * POST /admin/plugins/{slug}/toggle — Enable or disable a plugin.
     */
    public function toggle(string $slug): void
    {
        $this->requirePermission('apps.manage');

        $result = $this->pluginManager->toggle($slug);

        if ($result['success']) {
            $state = ($result['enabled'] ?? false) ? 'enabled' : 'disabled';
            $this->flash('success', "Plugin {$state} successfully.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to toggle plugin.']);
        }

        $this->redirect(admin_url('plugins'));
    }

    /**
     * GET /admin/plugins/{slug}/settings — Plugin settings page.
     */
    public function settings(string $slug): string
    {
        $this->requirePermission('apps.manage');

        $manifest = $this->pluginManager->readManifest($slug);
        if (!$manifest) {
            $this->flash('errors', ['Plugin not found.']);
            $this->redirect(admin_url('plugins'));
            return '';
        }

        $settings = $this->pluginManager->getSettings($slug);
        $schema = $manifest['settings'] ?? [];

        return $this->render('cms::plugins/settings', [
            'plugin' => $manifest,
            'slug' => $slug,
            'settings' => $settings,
            'schema' => $schema,
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /admin/plugins/{slug}/settings — Save plugin settings.
     */
    public function saveSettings(string $slug): void
    {
        $this->requirePermission('apps.manage');

        $manifest = $this->pluginManager->readManifest($slug);
        if (!$manifest) {
            $this->flash('errors', ['Plugin not found.']);
            $this->redirect(admin_url('plugins'));
            return;
        }

        // Collect settings from POST data based on the plugin's schema
        $schema = $manifest['settings'] ?? [];
        $settings = [];

        foreach ($schema as $key => $field) {
            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
            if ($key === '') continue;

            $value = $this->input('setting_' . $key, $field['default'] ?? '');

            // Basic type coercion based on schema
            $type = $field['type'] ?? 'text';
            switch ($type) {
                case 'boolean':
                case 'checkbox':
                    $settings[$key] = (bool) $value;
                    break;
                case 'number':
                case 'integer':
                    $settings[$key] = (int) $value;
                    break;
                default:
                    $settings[$key] = is_string($value) ? trim($value) : (string) $value;
            }
        }

        $this->pluginManager->saveSettings($slug, $settings);

        HookManager::doAction('plugin.settings_saved', $slug, $settings);

        $this->flash('success', 'Plugin settings saved.');
        $this->redirect(admin_url('plugins/' . urlencode($slug) . '/settings'));
    }
}
