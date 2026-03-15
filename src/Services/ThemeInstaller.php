<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Event\Events\ThemeInstalled;
use ZephyrPHP\Event\Events\ThemeUninstalled;
use ZephyrPHP\Event\Events\ThemeActivating;
use ZephyrPHP\Event\Events\ThemeActivated;

/**
 * Theme Installer — handles ZIP package installation, validation, and asset publishing.
 *
 * Expected theme package format (ZIP):
 *   theme.json          (required — metadata)
 *   layouts/            (required — at least one .twig layout)
 *   templates/          (optional)
 *   sections/           (optional)
 *   snippets/           (optional)
 *   controllers/        (optional — PHP files)
 *   config/             (optional — JSON files)
 *   assets/             (optional — css/, js/, fonts/, images/)
 *   pages.json          (optional — page routing config)
 *   preview.png         (optional — marketplace screenshot)
 *
 * Security:
 * - ZIP contents are validated before extraction (no path traversal)
 * - Only allowed file extensions are extracted
 * - theme.json is validated against required schema
 * - PHP files are only allowed in controllers/ directory
 * - Asset publishing uses realpath verification
 * - File sizes are checked before extraction (max 10MB per file)
 */
class ThemeInstaller
{
    /**
     * Maximum individual file size in bytes (10MB).
     */
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Maximum total extracted size in bytes (50MB).
     */
    private const int MAX_TOTAL_SIZE = 50 * 1024 * 1024;

    /**
     * Allowed file extensions in theme packages.
     */
    private const array ALLOWED_EXTENSIONS = [
        'twig', 'json', 'css', 'js', 'map',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
        'txt', 'md',
    ];

    /**
     * Extensions allowed ONLY inside controllers/ directory.
     */
    private const array CONTROLLER_EXTENSIONS = ['php'];

    /**
     * Required fields in theme.json.
     */
    private const array REQUIRED_FIELDS = ['name'];

    private ThemeManager $themeManager;

    public function __construct(ThemeManager $themeManager)
    {
        $this->themeManager = $themeManager;
    }

    /**
     * Install a theme from a ZIP file.
     *
     * @param string $zipPath Path to the uploaded ZIP file
     * @param bool $overwrite Whether to overwrite an existing theme with the same slug
     * @return array{success: bool, slug?: string, name?: string, error?: string}
     */
    public function install(string $zipPath, bool $overwrite = false): array
    {
        // Validate ZIP file exists and is readable
        if (!file_exists($zipPath) || !is_readable($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found or not readable.'];
        }

        // Open ZIP archive
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file. Error code: ' . $result];
        }

        try {
            // Find and validate theme.json inside ZIP
            $themeJson = $this->findThemeJson($zip);
            if ($themeJson === null) {
                return ['success' => false, 'error' => 'theme.json not found in ZIP archive.'];
            }

            $config = json_decode($themeJson, true);
            if (!is_array($config)) {
                return ['success' => false, 'error' => 'theme.json contains invalid JSON.'];
            }

            // Validate theme.json schema
            $validationError = $this->validateThemeJson($config);
            if ($validationError !== null) {
                return ['success' => false, 'error' => $validationError];
            }

            // Determine slug
            $slug = $config['slug'] ?? $this->slugify($config['name']);
            if (empty($slug) || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
                return ['success' => false, 'error' => 'Invalid theme slug derived from name.'];
            }

            // Check if theme already exists
            $themePath = $this->themeManager->getThemePath($slug);
            if (is_dir($themePath) && !$overwrite) {
                return ['success' => false, 'error' => "Theme '{$slug}' already exists. Set overwrite to replace."];
            }

            // Validate all ZIP entries before extraction
            $validationResult = $this->validateZipContents($zip);
            if ($validationResult !== null) {
                return ['success' => false, 'error' => $validationResult];
            }

            // Extract to theme directory (assets go to public/themes/{slug}/)
            $assetsPath = $this->themeManager->getThemeAssetsPath($slug);
            $extractResult = $this->extractTheme($zip, $themePath, $assetsPath);
            if ($extractResult !== null) {
                return ['success' => false, 'error' => $extractResult];
            }

            // Write validated theme.json (in case slug was generated)
            $config['slug'] = $slug;
            file_put_contents(
                $themePath . '/theme.json',
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // Create or update DB record
            $this->ensureDbRecord($slug, $config);

            // Fire installed event
            $events = EventDispatcher::getInstance();
            $events->dispatch(new ThemeInstalled($slug, $config['name'], $themePath));

            return [
                'success' => true,
                'slug' => $slug,
                'name' => $config['name'],
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Uninstall a theme — removes files, published assets, and DB record.
     * Cannot uninstall the live theme.
     *
     * @return array{success: bool, error?: string}
     */
    public function uninstall(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);

        try {
            $theme = Theme::findOneBy(['slug' => $slug]);
            if ($theme && $theme->isLive()) {
                return ['success' => false, 'error' => 'Cannot uninstall the active (live) theme.'];
            }
        } catch (\Exception $e) {
            // DB might not be available
        }

        // Remove public assets directory
        $this->removeAssets($slug);

        // Remove theme files
        $themePath = $this->themeManager->getThemePath($slug);
        if (is_dir($themePath)) {
            $this->deleteDirectory($themePath);
        }

        // Remove DB record
        try {
            $theme = Theme::findOneBy(['slug' => $slug]);
            if ($theme) {
                $theme->delete();
            }
        } catch (\Exception $e) {
            // DB might not be available
        }

        // Fire uninstalled event
        EventDispatcher::getInstance()->dispatch(new ThemeUninstalled($slug));

        return ['success' => true];
    }

    /**
     * Ensure the public assets directory exists for a theme.
     * Assets live directly at public/themes/{slug}/ — no publish/copy needed.
     */
    public function ensureAssetsDir(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        $assetsPath = $this->themeManager->getThemeAssetsPath($slug);

        if (!is_dir($assetsPath)) {
            return mkdir($assetsPath, 0755, true);
        }

        return true;
    }

    /**
     * Remove public assets directory for a theme.
     */
    public function removeAssets(string $slug): void
    {
        $slug = $this->sanitizeSlug($slug);
        $assetsPath = $this->themeManager->getThemeAssetsPath($slug);

        if (is_dir($assetsPath)) {
            // Verify path is within public/themes before deleting
            $realPublicThemes = realpath($this->themeManager->getPublicThemesPath());
            $realAssets = realpath($assetsPath);
            if ($realPublicThemes && $realAssets && str_starts_with($realAssets, $realPublicThemes)) {
                $this->deleteDirectory($assetsPath);
            }
        }
    }

    /**
     * Activate a theme: publish assets, fire events, update DB.
     *
     * @return array{success: bool, error?: string}
     */
    public function activate(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        $themePath = $this->themeManager->getThemePath($slug);

        if (!is_dir($themePath)) {
            return ['success' => false, 'error' => "Theme '{$slug}' not found."];
        }

        // Get current live theme
        $currentSlug = null;
        try {
            $current = Theme::findOneBy(['status' => 'live']);
            $currentSlug = $current?->getSlug();
        } catch (\Exception $e) {
            // DB not available
        }

        // Fire activating event (can be stopped)
        $activating = new ThemeActivating($slug, $currentSlug);
        EventDispatcher::getInstance()->dispatch($activating);
        if ($activating->isPropagationStopped()) {
            return ['success' => false, 'error' => 'Theme activation was blocked by a listener.'];
        }

        // Ensure public assets directory exists
        if (!$this->ensureAssetsDir($slug)) {
            return ['success' => false, 'error' => 'Failed to create theme assets directory.'];
        }

        // Register theme asset collection
        $this->registerThemeAssetCollection($slug);

        // Update DB
        if (!$this->themeManager->publishTheme($slug)) {
            return ['success' => false, 'error' => 'Failed to update theme status in database.'];
        }

        // Fire activated event
        EventDispatcher::getInstance()->dispatch(new ThemeActivated($slug));

        return ['success' => true];
    }

    /**
     * Register the theme's assets as a collection in the Asset system.
     */
    /**
     * Register the theme's assets as a collection in the Asset system.
     * Supports both simple string paths and object entries with attributes.
     */
    private function registerThemeAssetCollection(string $slug): void
    {
        $themePath = $this->themeManager->getThemePath($slug);
        $configFile = $themePath . '/theme.json';

        if (!file_exists($configFile)) {
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!is_array($config)) {
            return;
        }

        $assets = [];
        $prefix = 'themes/' . $slug . '/';

        // Register CSS assets from theme.json (supports string or {path, media, ...})
        foreach ($config['assets']['css'] ?? [] as $entry) {
            $rawPath = is_string($entry) ? $entry : ($entry['path'] ?? '');
            $safePath = $this->sanitizeAssetPath($rawPath, $prefix);
            if ($safePath) {
                $item = ['path' => $safePath, 'priority' => 5];
                if (is_array($entry)) {
                    $attrs = [];
                    if (!empty($entry['media'])) $attrs['media'] = $entry['media'];
                    if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $entry['crossorigin'] === true ? 'anonymous' : $entry['crossorigin'];
                    if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
                    if (!empty($attrs)) $item['attributes'] = $attrs;
                }
                $assets[] = $item;
            }
        }

        // Register JS assets from theme.json (supports string or {path, defer, async, ...})
        foreach ($config['assets']['js'] ?? [] as $entry) {
            $rawPath = is_string($entry) ? $entry : ($entry['path'] ?? '');
            $safePath = $this->sanitizeAssetPath($rawPath, $prefix);
            if ($safePath) {
                $item = ['path' => $safePath, 'priority' => 100];
                if (is_array($entry)) {
                    $attrs = [];
                    if (!empty($entry['defer'])) $attrs['defer'] = true;
                    if (!empty($entry['async'])) $attrs['async'] = true;
                    if (!empty($entry['type'])) $attrs['type'] = $entry['type'];
                    if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $entry['crossorigin'] === true ? 'anonymous' : $entry['crossorigin'];
                    if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
                    if (!empty($attrs)) $item['attributes'] = $attrs;
                }
                $assets[] = $item;
            }
        }

        if (!empty($assets) && class_exists(\ZephyrPHP\Asset\Asset::class)) {
            \ZephyrPHP\Asset\Asset::collection('theme:' . $slug, $assets);
        }
    }

    /**
     * Sanitize an asset path, prepending the theme prefix.
     */
    private function sanitizeAssetPath(string $path, string $prefix): ?string
    {
        if (empty($path)) {
            return null;
        }

        // Remove leading slashes, prevent traversal
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        // Strip "assets/" prefix if present (since we're already in the published dir)
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }

        return $prefix . $path;
    }

    // ========================================================================
    // VALIDATION
    // ========================================================================

    /**
     * Validate theme.json contents against required schema.
     *
     * @return string|null Error message or null if valid
     */
    public function validateThemeJson(array $config): ?string
    {
        // Required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($config[$field])) {
                return "theme.json is missing required field: '{$field}'.";
            }
        }

        // Name must be a string, max 100 chars
        if (!is_string($config['name']) || strlen($config['name']) > 100) {
            return 'theme.json name must be a string of max 100 characters.';
        }

        // Version must match semver-like pattern if provided
        if (isset($config['version'])) {
            if (!is_string($config['version']) || !preg_match('/^\d+\.\d+(\.\d+)?(-[\w.]+)?$/', $config['version'])) {
                return "theme.json version must be a valid semver string (e.g., '1.0.0').";
            }
        }

        // Slug must be valid if provided
        if (isset($config['slug'])) {
            if (!is_string($config['slug']) || !preg_match('/^[a-z0-9_-]+$/', $config['slug'])) {
                return 'theme.json slug must contain only lowercase letters, numbers, underscores, and hyphens.';
            }
            if (strlen($config['slug']) > 64) {
                return 'theme.json slug must be 64 characters or fewer.';
            }
        }

        // Assets must be arrays of strings if provided
        if (isset($config['assets'])) {
            if (!is_array($config['assets'])) {
                return 'theme.json assets must be an object.';
            }
            foreach (['css', 'js'] as $type) {
                if (isset($config['assets'][$type])) {
                    if (!is_array($config['assets'][$type])) {
                        return "theme.json assets.{$type} must be an array.";
                    }
                    foreach ($config['assets'][$type] as $path) {
                        if (!is_string($path) || str_contains($path, '..')) {
                            return "theme.json assets.{$type} contains invalid path.";
                        }
                    }
                }
            }
        }

        // Requires must be valid if provided
        if (isset($config['requires'])) {
            if (!is_array($config['requires'])) {
                return 'theme.json requires must be an object.';
            }
        }

        return null;
    }

    /**
     * Find theme.json in the ZIP, handling both root-level and nested structures.
     */
    private function findThemeJson(\ZipArchive $zip): ?string
    {
        // Try root level
        $content = $zip->getFromName('theme.json');
        if ($content !== false) {
            return $content;
        }

        // Try one level deep (common: theme-name/theme.json)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^[^/]+/theme\.json$#', $name)) {
                return $zip->getFromName($name);
            }
        }

        return null;
    }

    /**
     * Validate all entries in the ZIP before extraction.
     *
     * @return string|null Error message or null if valid
     */
    private function validateZipContents(\ZipArchive $zip): ?string
    {
        $totalSize = 0;
        $prefix = $this->detectZipPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];

            // Remove common prefix if ZIP wraps everything in a directory
            $relativePath = $prefix ? substr($name, strlen($prefix)) : $name;

            // Skip directories
            if (str_ends_with($name, '/')) {
                continue;
            }

            // Block path traversal
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
                return "Dangerous path detected: '{$relativePath}'.";
            }

            // Check file size
            if ($stat['size'] > self::MAX_FILE_SIZE) {
                return "File '{$relativePath}' exceeds maximum size of 10MB.";
            }

            $totalSize += $stat['size'];
            if ($totalSize > self::MAX_TOTAL_SIZE) {
                return 'Total extracted size exceeds maximum of 50MB.';
            }

            // Check file extension
            $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $isController = str_starts_with($relativePath, 'controllers/');

            if ($isController) {
                if (!in_array($ext, self::CONTROLLER_EXTENSIONS, true) && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    return "File type '.{$ext}' not allowed: '{$relativePath}'.";
                }
            } else {
                // PHP files not allowed outside controllers/
                if ($ext === 'php') {
                    return "PHP files are only allowed in the controllers/ directory: '{$relativePath}'.";
                }
                if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    return "File type '.{$ext}' not allowed: '{$relativePath}'.";
                }
            }
        }

        return null;
    }

    /**
     * Detect if the ZIP wraps everything in a single root directory.
     */
    private function detectZipPrefix(\ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            return '';
        }

        $firstName = $zip->getNameIndex(0);

        // Check if first entry is a directory
        if (str_ends_with($firstName, '/')) {
            $prefix = $firstName;
            // Verify all entries share this prefix
            for ($i = 1; $i < $zip->numFiles; $i++) {
                if (!str_starts_with($zip->getNameIndex($i), $prefix)) {
                    return '';
                }
            }
            return $prefix;
        }

        return '';
    }

    /**
     * Extract theme ZIP to the target directory.
     * Files under assets/ are extracted to the public assets path instead.
     *
     * @return string|null Error message or null on success
     */
    private function extractTheme(\ZipArchive $zip, string $targetPath, string $assetsPath): ?string
    {
        // Clean target if it exists
        if (is_dir($targetPath)) {
            $this->deleteDirectory($targetPath);
        }

        if (!mkdir($targetPath, 0755, true)) {
            return 'Failed to create theme directory.';
        }

        // Ensure public assets directory exists
        if (!is_dir($assetsPath)) {
            mkdir($assetsPath, 0755, true);
        }

        $prefix = $this->detectZipPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $relativePath = $prefix ? substr($name, strlen($prefix)) : $name;

            // Skip empty paths and directories
            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }

            // Skip hidden files
            if (str_starts_with(basename($relativePath), '.')) {
                continue;
            }

            // Route assets/ files to public/themes/{slug}/ instead of theme template dir
            if (str_starts_with($relativePath, 'assets/')) {
                $assetRelative = substr($relativePath, 7); // strip 'assets/'
                $destFile = $assetsPath . '/' . $assetRelative;
                $destDir = dirname($destFile);

                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                $realAssets = realpath($assetsPath);
                $realDest = realpath($destDir);
                if (!$realAssets || !$realDest || !str_starts_with($realDest, $realAssets)) {
                    continue;
                }
            } else {
                $destFile = $targetPath . '/' . $relativePath;
                $destDir = dirname($destFile);

                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                $realTarget = realpath($targetPath);
                $realDest = realpath($destDir);
                if (!$realTarget || !$realDest || !str_starts_with($realDest, $realTarget)) {
                    continue;
                }
            }

            // Extract file
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($destFile, $content);
            }
        }

        return null;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Create or update the DB record for an installed theme.
     */
    private function ensureDbRecord(string $slug, array $config): void
    {
        try {
            $theme = Theme::findOneBy(['slug' => $slug]);
            if ($theme) {
                $theme->setName($config['name']);
                $theme->setDescription($config['description'] ?? null);
                $theme->save();
            } else {
                $theme = new Theme();
                $theme->setName($config['name']);
                $theme->setSlug($slug);
                $theme->setStatus('draft');
                $theme->setDescription($config['description'] ?? null);
                $theme->save();
            }
        } catch (\Exception $e) {
            // DB not available — theme is still installed on filesystem
        }
    }

    /**
     * Sanitize a theme slug.
     */
    private function sanitizeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($slug)));
        if (empty($slug)) {
            throw new \InvalidArgumentException('Invalid theme slug.');
        }
        return $slug;
    }

    /**
     * Generate a slug from a theme name.
     */
    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'theme';
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

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
