<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * PluginManager — Manages plugin lifecycle: install, uninstall, enable, disable, boot.
 *
 * Plugins live in {BASE_PATH}/plugins/{slug}/ and are registered in
 * {BASE_PATH}/config/plugins.json.
 *
 * Each plugin directory must contain a plugin.json manifest:
 * {
 *   "name": "My Plugin",
 *   "slug": "my-plugin",
 *   "version": "1.0.0",
 *   "description": "...",
 *   "author": "...",
 *   "type": "plugin",
 *   "hooks": ["before_render", "content"],
 *   "settings": {}
 * }
 *
 * And optionally a boot.php file that is loaded when the plugin is enabled.
 */
class PluginManager
{
    private static ?self $instance = null;

    private string $basePath;
    private string $pluginsDir;
    private string $configPath;

    /** @var array<string, array> Cached registry from plugins.json */
    private ?array $registry = null;

    private function __construct()
    {
        $this->basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $this->pluginsDir = $this->basePath . '/plugins';
        $this->configPath = $this->basePath . '/config/plugins.json';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Registry (plugins.json)
    // -------------------------------------------------------------------------

    /**
     * Read the plugins registry from config/plugins.json.
     *
     * @return array<string, array{enabled: bool, installed_at: string, version: string}>
     */
    public function getRegistry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        if (!file_exists($this->configPath)) {
            $this->registry = [];
            return $this->registry;
        }

        $contents = file_get_contents($this->configPath);
        $data = json_decode($contents, true);

        $this->registry = is_array($data) ? $data : [];
        return $this->registry;
    }

    /**
     * Write the plugins registry to config/plugins.json with file locking.
     */
    private function saveRegistry(array $registry): void
    {
        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->configPath, $json, LOCK_EX);
        $this->registry = $registry;
    }

    // -------------------------------------------------------------------------
    // Plugin Discovery
    // -------------------------------------------------------------------------

    /**
     * Get all installed plugins with their manifest data and enabled status.
     *
     * @return array<int, array{slug: string, manifest: array, enabled: bool, installed_at: string, has_settings: bool}>
     */
    public function getInstalledPlugins(): array
    {
        $registry = $this->getRegistry();
        $plugins = [];

        if (!is_dir($this->pluginsDir)) {
            return $plugins;
        }

        $dirs = scandir($this->pluginsDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($this->pluginsDir . '/' . $dir)) {
                continue;
            }

            $slug = $this->sanitizeSlug($dir);
            if ($slug === '' || $slug !== $dir) {
                continue; // Skip directories with invalid names
            }

            $manifest = $this->readManifest($slug);
            if ($manifest === null) {
                continue; // No valid plugin.json
            }

            $regEntry = $registry[$slug] ?? [];

            $plugins[] = [
                'slug' => $slug,
                'manifest' => $manifest,
                'enabled' => (bool) ($regEntry['enabled'] ?? false),
                'installed_at' => $regEntry['installed_at'] ?? '',
                'has_settings' => !empty($manifest['settings']) || file_exists($this->pluginsDir . '/' . $slug . '/settings.php'),
            ];
        }

        // Sort alphabetically by name
        usort($plugins, fn(array $a, array $b) => strcasecmp($a['manifest']['name'] ?? $a['slug'], $b['manifest']['name'] ?? $b['slug']));

        return $plugins;
    }

    /**
     * Read the plugin.json manifest for a plugin slug.
     */
    public function readManifest(string $slug): ?array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $path = $this->pluginsDir . '/' . $slug . '/plugin.json';
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || empty($data['name'])) {
            return null;
        }

        return $data;
    }

    /**
     * Check if a plugin is installed (directory + manifest exist).
     */
    public function isInstalled(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        return $slug !== '' && is_dir($this->pluginsDir . '/' . $slug) && $this->readManifest($slug) !== null;
    }

    /**
     * Check if a plugin is enabled.
     */
    public function isEnabled(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        $registry = $this->getRegistry();
        return (bool) ($registry[$slug]['enabled'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    /**
     * Install a plugin from a ZIP file path.
     *
     * @param string $zipPath Absolute path to the plugin ZIP file.
     * @return array{success: bool, error?: string, slug?: string}
     */
    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found.'];
        }

        if (!extension_loaded('zip')) {
            return ['success' => false, 'error' => 'PHP zip extension is required.'];
        }

        // Create a temp directory for extraction
        $tempDir = sys_get_temp_dir() . '/zephyr_plugin_' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0755, true);

        try {
            $zip = new \ZipArchive();
            $result = $zip->open($zipPath);

            if ($result !== true) {
                return ['success' => false, 'error' => 'Failed to open ZIP archive.'];
            }

            // Security: validate all entries before extraction
            $validationResult = $this->validateZipContents($zip);
            if ($validationResult !== null) {
                $zip->close();
                return ['success' => false, 'error' => $validationResult];
            }

            $zip->extractTo($tempDir);
            $zip->close();

            // Find the plugin.json — it might be in a subdirectory
            $manifestPath = $this->findManifestInDir($tempDir);
            if ($manifestPath === null) {
                $this->deleteDirectory($tempDir);
                return ['success' => false, 'error' => 'No valid plugin.json found in archive.'];
            }

            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!is_array($manifest) || empty($manifest['name'])) {
                $this->deleteDirectory($tempDir);
                return ['success' => false, 'error' => 'Invalid plugin.json manifest.'];
            }

            // Determine the slug
            $slug = $this->sanitizeSlug($manifest['slug'] ?? '');
            if ($slug === '') {
                $slug = $this->sanitizeSlug(strtolower(str_replace(' ', '-', $manifest['name'])));
            }
            if ($slug === '') {
                $this->deleteDirectory($tempDir);
                return ['success' => false, 'error' => 'Could not determine a valid plugin slug.'];
            }

            // Check if already installed
            if ($this->isInstalled($slug)) {
                $this->deleteDirectory($tempDir);
                return ['success' => false, 'error' => "Plugin \"{$slug}\" is already installed."];
            }

            // Move from temp to plugins directory
            if (!is_dir($this->pluginsDir)) {
                mkdir($this->pluginsDir, 0755, true);
            }

            $pluginSourceDir = dirname($manifestPath);
            $targetDir = $this->pluginsDir . '/' . $slug;

            rename($pluginSourceDir, $targetDir);
            $this->deleteDirectory($tempDir);

            // Register in plugins.json
            $registry = $this->getRegistry();
            $registry[$slug] = [
                'enabled' => false,
                'installed_at' => date('Y-m-d H:i:s'),
                'version' => $manifest['version'] ?? '0.0.0',
            ];
            $this->saveRegistry($registry);

            // Fire hook
            HookManager::doAction('plugin.installed', $slug, $manifest);

            return ['success' => true, 'slug' => $slug];
        } catch (\Throwable $e) {
            $this->deleteDirectory($tempDir);
            return ['success' => false, 'error' => 'Installation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Install a plugin from a remote URL (downloads ZIP first).
     *
     * @param string $url Download URL for the plugin ZIP.
     * @return array{success: bool, error?: string, slug?: string}
     */
    public function installFromUrl(string $url): array
    {
        // Validate URL
        $url = filter_var($url, FILTER_VALIDATE_URL);
        if ($url === false) {
            return ['success' => false, 'error' => 'Invalid download URL.'];
        }

        // Only allow HTTPS
        if (!str_starts_with($url, 'https://')) {
            return ['success' => false, 'error' => 'Only HTTPS download URLs are allowed.'];
        }

        $tempFile = sys_get_temp_dir() . '/zephyr_plugin_dl_' . bin2hex(random_bytes(8)) . '.zip';

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'max_redirects' => 3,
                    'user_agent' => 'ZephyrPHP-CMS/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $contents = @file_get_contents($url, false, $context);
            if ($contents === false) {
                return ['success' => false, 'error' => 'Failed to download plugin from URL.'];
            }

            // Limit file size (50 MB max)
            if (strlen($contents) > 50 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Plugin archive exceeds maximum size (50 MB).'];
            }

            file_put_contents($tempFile, $contents);

            $result = $this->installFromZip($tempFile);

            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return $result;
        } catch (\Throwable $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return ['success' => false, 'error' => 'Download failed: ' . $e->getMessage()];
        }
    }

    /**
     * Uninstall a plugin by removing its directory and registry entry.
     *
     * @return array{success: bool, error?: string}
     */
    public function uninstall(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return ['success' => false, 'error' => 'Invalid plugin slug.'];
        }

        if (!$this->isInstalled($slug)) {
            return ['success' => false, 'error' => 'Plugin is not installed.'];
        }

        // Disable first if enabled
        if ($this->isEnabled($slug)) {
            $this->disable($slug);
        }

        $manifest = $this->readManifest($slug);

        // Run uninstall hook if plugin has one
        $uninstallFile = $this->pluginsDir . '/' . $slug . '/uninstall.php';
        if (file_exists($uninstallFile)) {
            try {
                require $uninstallFile;
            } catch (\Throwable $e) {
                // Log but don't block uninstall
            }
        }

        // Delete directory
        $pluginDir = $this->pluginsDir . '/' . $slug;

        // Safety: ensure the directory is within the plugins directory
        $realPluginDir = realpath($pluginDir);
        $realPluginsDir = realpath($this->pluginsDir);

        if (!$realPluginDir || !$realPluginsDir || !str_starts_with($realPluginDir, $realPluginsDir . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'Security error: path traversal detected.'];
        }

        $this->deleteDirectory($realPluginDir);

        // Remove from registry
        $registry = $this->getRegistry();
        unset($registry[$slug]);
        $this->saveRegistry($registry);

        // Fire hook
        HookManager::doAction('plugin.uninstalled', $slug, $manifest);

        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Enable / Disable
    // -------------------------------------------------------------------------

    /**
     * Enable a plugin.
     */
    public function enable(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '' || !$this->isInstalled($slug)) {
            return ['success' => false, 'error' => 'Plugin is not installed.'];
        }

        $registry = $this->getRegistry();
        $registry[$slug] = array_merge($registry[$slug] ?? [], [
            'enabled' => true,
        ]);
        $this->saveRegistry($registry);

        // Boot the plugin immediately
        $this->bootPlugin($slug);

        HookManager::doAction('plugin.enabled', $slug);

        return ['success' => true];
    }

    /**
     * Disable a plugin.
     */
    public function disable(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return ['success' => false, 'error' => 'Invalid plugin slug.'];
        }

        $registry = $this->getRegistry();
        if (isset($registry[$slug])) {
            $registry[$slug]['enabled'] = false;
            $this->saveRegistry($registry);
        }

        HookManager::doAction('plugin.disabled', $slug);

        return ['success' => true];
    }

    /**
     * Toggle a plugin's enabled/disabled state.
     *
     * @return array{success: bool, enabled?: bool, error?: string}
     */
    public function toggle(string $slug): array
    {
        if ($this->isEnabled($slug)) {
            $result = $this->disable($slug);
            return array_merge($result, ['enabled' => false]);
        } else {
            $result = $this->enable($slug);
            return array_merge($result, ['enabled' => true]);
        }
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    /**
     * Boot all enabled plugins (load their boot.php files).
     * Call this once during application bootstrap.
     */
    public function bootAll(): void
    {
        $registry = $this->getRegistry();

        foreach ($registry as $slug => $entry) {
            if (empty($entry['enabled'])) {
                continue;
            }

            $slug = $this->sanitizeSlug($slug);
            if ($slug === '' || !$this->isInstalled($slug)) {
                continue;
            }

            $this->bootPlugin($slug);
        }

        HookManager::doAction('plugins.booted');
    }

    /**
     * Boot a single plugin (load its boot.php).
     */
    private function bootPlugin(string $slug): void
    {
        $bootFile = $this->pluginsDir . '/' . $slug . '/boot.php';
        if (!file_exists($bootFile)) {
            return;
        }

        // Verify boot file is within the expected plugin directory
        $realBoot = realpath($bootFile);
        $realPluginDir = realpath($this->pluginsDir . '/' . $slug);

        if (!$realBoot || !$realPluginDir || !str_starts_with($realBoot, $realPluginDir . DIRECTORY_SEPARATOR)) {
            return; // Path traversal protection
        }

        try {
            require_once $realBoot;
        } catch (\Throwable $e) {
            // Plugin boot failure should not crash the application.
            // In production, this should be logged.
            error_log("Plugin '{$slug}' boot failed: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Get plugin settings (stored in plugin's settings.json within the plugin dir).
     */
    public function getSettings(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return [];
        }

        $path = $this->pluginsDir . '/' . $slug . '/settings.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save plugin settings.
     */
    public function saveSettings(string $slug, array $settings): bool
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '' || !$this->isInstalled($slug)) {
            return false;
        }

        $path = $this->pluginsDir . '/' . $slug . '/settings.json';
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return file_put_contents($path, $json, LOCK_EX) !== false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the plugins directory path.
     */
    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }

    /**
     * Sanitize a plugin slug to prevent directory traversal and invalid names.
     * Only allows lowercase alphanumeric characters, hyphens, and underscores.
     */
    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]/', '', $slug);

        // Block dangerous patterns
        if ($slug === '' || $slug === '.' || $slug === '..' || str_contains($slug, '..')) {
            return '';
        }

        // Max length 64 characters
        return substr($slug, 0, 64);
    }

    /**
     * Validate ZIP contents before extraction (security).
     *
     * @return string|null Error message if invalid, null if OK.
     */
    private function validateZipContents(\ZipArchive $zip): ?string
    {
        $maxEntries = 5000;
        $maxTotalSize = 100 * 1024 * 1024; // 100 MB
        $totalSize = 0;

        if ($zip->numFiles > $maxEntries) {
            return "Archive contains too many files ({$zip->numFiles}).";
        }

        $dangerousExtensions = ['php', 'phtml', 'phar'];
        $allowedPhpFiles = ['boot.php', 'uninstall.php', 'settings.php'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];

            // Block path traversal in archive entries
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                return "Archive contains suspicious path: \"{$name}\".";
            }

            // Block absolute Windows paths
            if (preg_match('/^[A-Za-z]:/', $name)) {
                return "Archive contains absolute path: \"{$name}\".";
            }

            $totalSize += $stat['size'];
            if ($totalSize > $maxTotalSize) {
                return 'Archive exceeds maximum uncompressed size (100 MB).';
            }

            // Allow PHP files only if they match known patterns
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, $dangerousExtensions, true)) {
                $basename = basename($name);
                // Allow PHP files in the plugin (boot.php, settings.php, uninstall.php, src/*.php)
                // but block .phtml and .phar entirely
                if ($ext !== 'php') {
                    return "Archive contains disallowed file type: \"{$name}\".";
                }
            }
        }

        return null;
    }

    /**
     * Find plugin.json in a directory (might be at root or one level deep).
     */
    private function findManifestInDir(string $dir): ?string
    {
        // Check root
        $rootManifest = $dir . '/plugin.json';
        if (file_exists($rootManifest)) {
            return $rootManifest;
        }

        // Check one level deep (common in ZIP archives)
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $subPath = $dir . '/' . $entry;
            if (is_dir($subPath) && file_exists($subPath . '/plugin.json')) {
                return $subPath . '/plugin.json';
            }
        }

        return null;
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
