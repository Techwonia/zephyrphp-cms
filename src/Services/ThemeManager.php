<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Theme;

class ThemeManager
{
    private string $themesBasePath;
    private ?array $themeConfig = null;
    private ?string $effectiveThemeSlug = null;

    public function __construct()
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $viewsPath = $_ENV['VIEWS_PATH'] ?? 'pages';
        $this->themesBasePath = $basePath . '/' . $viewsPath . '/themes';
    }

    /**
     * Get the live (published) theme slug from DB, fallback to env/default.
     */
    public function getActiveTheme(): string
    {
        try {
            $liveTheme = Theme::findOneBy(['status' => 'live']);
            if ($liveTheme) {
                return $liveTheme->getSlug();
            }
        } catch (\Exception $e) {
            // DB not ready yet
        }

        return $_ENV['CMS_THEME'] ?? 'default';
    }

    /**
     * Check if a theme preview is requested via query param.
     */
    public function getPreviewTheme(): ?string
    {
        $preview = $_GET['theme_preview'] ?? null;
        if (!$preview) {
            return null;
        }

        // Validate preview theme exists
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($preview));
        if ($slug && is_dir($this->themesBasePath . '/' . $slug)) {
            return $slug;
        }

        return null;
    }

    /**
     * Get the effective theme: preview if set (and user is admin), otherwise active.
     */
    public function getEffectiveTheme(): string
    {
        if ($this->effectiveThemeSlug !== null) {
            return $this->effectiveThemeSlug;
        }

        $preview = $this->getPreviewTheme();
        if ($preview) {
            // Check if user is authenticated and admin
            try {
                if (class_exists(\ZephyrPHP\Auth\Auth::class) && \ZephyrPHP\Auth\Auth::check()) {
                    $user = \ZephyrPHP\Auth\Auth::user();
                    if ($user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
                        $this->effectiveThemeSlug = $preview;
                        return $preview;
                    }
                }
            } catch (\Exception $e) {
                // Auth not available
            }
        }

        $this->effectiveThemeSlug = $this->getActiveTheme();
        return $this->effectiveThemeSlug;
    }

    /**
     * Get the path for the effective (or specified) theme.
     */
    public function getActiveThemePath(): string
    {
        return $this->themesBasePath . '/' . $this->getEffectiveTheme();
    }

    /**
     * Get the path for a specific theme by slug.
     */
    public function getThemePath(string $slug): string
    {
        // Sanitize slug to prevent path traversal
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (empty($slug)) {
            throw new \InvalidArgumentException('Invalid theme slug.');
        }
        return $this->themesBasePath . '/' . $slug;
    }

    /**
     * Read theme.json config for the effective theme.
     */
    public function getThemeConfig(?string $slug = null): array
    {
        if ($slug === null && $this->themeConfig !== null) {
            return $this->themeConfig;
        }

        $themePath = $slug ? $this->getThemePath($slug) : $this->getActiveThemePath();
        $configFile = $themePath . '/theme.json';

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?: [];
        } else {
            $config = [];
        }

        if ($slug === null) {
            $this->themeConfig = $config;
        }

        return $config;
    }

    /**
     * List all themes (from DB, with filesystem check).
     */
    public function listThemes(): array
    {
        $themes = [];

        try {
            $dbThemes = Theme::findAll();
            foreach ($dbThemes as $theme) {
                $themes[] = $theme;
            }
        } catch (\Exception $e) {
            // DB not ready, fall back to filesystem
            return $this->listThemesFromFilesystem();
        }

        return $themes;
    }

    /**
     * Fallback: list themes from filesystem only.
     */
    private function listThemesFromFilesystem(): array
    {
        $themes = [];
        if (!is_dir($this->themesBasePath)) {
            return $themes;
        }

        $dirs = scandir($this->themesBasePath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $path = $this->themesBasePath . '/' . $dir;
            if (!is_dir($path)) continue;

            $configFile = $path . '/theme.json';
            $name = $dir;
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
                $name = $config['name'] ?? $dir;
            }

            $themes[$dir] = $name;
        }

        return $themes;
    }

    /**
     * Get layout options from theme.json.
     */
    public function getLayouts(?string $slug = null): array
    {
        $config = $this->getThemeConfig($slug);
        return $config['layouts'] ?? [];
    }

    /**
     * Get layout names as key => display name.
     */
    public function getLayoutNames(?string $slug = null): array
    {
        $layouts = $this->getLayouts($slug);
        $names = [];
        foreach ($layouts as $key => $layout) {
            $names[$key] = is_array($layout) ? ($layout['name'] ?? $key) : $layout;
        }
        return $names;
    }

    /**
     * Get the templates directory path for the effective theme.
     */
    public function getTemplatesPath(?string $slug = null): string
    {
        $themePath = $slug ? $this->getThemePath($slug) : $this->getActiveThemePath();
        return $themePath . '/templates';
    }

    /**
     * Read a template file from the effective theme.
     */
    public function readTemplate(string $filename, ?string $slug = null): ?string
    {
        // Block path traversal in filename
        if (str_contains($filename, '..') || str_starts_with($filename, '/')) {
            return null;
        }

        $path = $this->getTemplatesPath($slug) . '/' . $filename;
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    /**
     * Write a template file to the effective theme.
     */
    public function writeTemplate(string $filename, string $content, ?string $slug = null): bool
    {
        // Block path traversal in filename
        if (str_contains($filename, '..') || str_starts_with($filename, '/')) {
            return false;
        }

        $path = $this->getTemplatesPath($slug) . '/' . $filename;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($path, $content) !== false;
    }

    /**
     * Check if a template exists.
     */
    public function templateExists(string $filename, ?string $slug = null): bool
    {
        return file_exists($this->getTemplatesPath($slug) . '/' . $filename);
    }

    /**
     * Read any file from a theme (layouts, snippets, templates).
     */
    public function readFile(string $relativePath, string $slug): ?string
    {
        $themePath = realpath($this->getThemePath($slug));
        if (!$themePath) {
            return null;
        }

        $path = $themePath . '/' . $relativePath;
        $realPath = realpath($path);
        if (!$realPath || !str_starts_with($realPath, $themePath . DIRECTORY_SEPARATOR)) {
            return null; // Path traversal blocked
        }

        return file_get_contents($realPath);
    }

    /**
     * Write any file to a theme.
     */
    public function writeFile(string $relativePath, string $content, string $slug): bool
    {
        $themePath = realpath($this->getThemePath($slug));
        if (!$themePath) {
            return false;
        }

        // Block path traversal: ensure relative path doesn't escape theme dir
        $normalized = str_replace('\\', '/', $relativePath);
        if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            return false;
        }

        // Only allow safe file extensions
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $allowedExts = ['twig', 'json', 'css', 'js', 'html', 'txt', 'svg'];
        if (!in_array($ext, $allowedExts, true)) {
            return false;
        }

        $path = $themePath . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // After mkdir, verify final path is still within theme
        $realDir = realpath($dir);
        if (!$realDir || !str_starts_with($realDir, $themePath)) {
            return false;
        }

        return file_put_contents($path, $content) !== false;
    }

    /**
     * List all editable files in a theme (layouts, templates, snippets).
     */
    public function listFiles(string $slug): array
    {
        $themePath = $this->getThemePath($slug);
        $files = [];

        // Twig template directories
        $twigDirs = ['layouts', 'templates', 'snippets', 'sections'];
        foreach ($twigDirs as $dir) {
            $dirPath = $themePath . '/' . $dir;
            if (!is_dir($dirPath)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $relative = $dir . '/' . str_replace('\\', '/', $iterator->getSubPathName());
                    $files[$dir][] = $relative;
                }
            }
        }

        // Asset directories (CSS, JS)
        $assetDirs = ['assets/css' => ['css'], 'assets/js' => ['js']];
        foreach ($assetDirs as $assetDir => $extensions) {
            $dirPath = $themePath . '/' . $assetDir;
            if (!is_dir($dirPath)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $relative = $assetDir . '/' . str_replace('\\', '/', $iterator->getSubPathName());
                    $files[$assetDir][] = $relative;
                }
            }
        }

        // Controllers (PHP)
        $controllersDir = $themePath . '/controllers';
        if (is_dir($controllersDir)) {
            foreach (scandir($controllersDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (str_ends_with($file, '.php')) {
                    $files['controllers'][] = 'controllers/' . $file;
                }
            }
        }

        // Config files (JSON)
        $configDir = $themePath . '/config';
        if (is_dir($configDir)) {
            foreach (scandir($configDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (str_ends_with($file, '.json')) {
                    $files['config'][] = 'config/' . $file;
                }
            }
        }

        return $files;
    }

    /**
     * Create a new theme with starter files.
     */
    public function createTheme(string $name, string $slug, ?string $description = null, ?string $copyFrom = null): Theme
    {
        $themePath = $this->getThemePath($slug);

        if ($copyFrom && is_dir($this->getThemePath($copyFrom))) {
            // Duplicate from existing theme
            $this->copyDirectory($this->getThemePath($copyFrom), $themePath);

            // Update theme.json with new name
            $configFile = $themePath . '/theme.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
                $config['name'] = $name;
                file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        } else {
            // Create fresh theme with starter files
            $this->createStarterTheme($themePath, $name);
        }

        // Create DB record
        $theme = new Theme();
        $theme->setName($name);
        $theme->setSlug($slug);
        $theme->setStatus('draft');
        $theme->setDescription($description);
        $theme->save();

        return $theme;
    }

    /**
     * Publish a theme (make it live, set all others to draft).
     */
    public function publishTheme(string $slug): bool
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return false;

            // Set all themes to draft
            $conn->executeStatement("UPDATE `cms_themes` SET `status` = 'draft', `updatedAt` = NOW()");

            // Set target theme to live
            $conn->executeStatement(
                "UPDATE `cms_themes` SET `status` = 'live', `updatedAt` = NOW() WHERE `slug` = ?",
                [$slug]
            );

            // Reset cached effective theme
            $this->effectiveThemeSlug = null;
            $this->themeConfig = null;

            // Auto-publish assets to /public/theme/
            $this->publishAssets($slug);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Publish theme assets to /public/theme/ for direct web server serving.
     * Clears old assets and copies the current theme's assets/ folder.
     */
    public function publishAssets(string $slug): bool
    {
        $themePath = $this->getThemePath($slug);
        $sourceDir = $themePath . '/assets';
        $publicDir = $this->getPublicThemeAssetsPath();

        // Clean existing published assets
        if (is_dir($publicDir)) {
            $this->deleteDirectory($publicDir);
        }

        // If theme has no assets, just clean up
        if (!is_dir($sourceDir)) {
            return true;
        }

        // Copy theme assets to /public/theme/
        $this->copyDirectory($sourceDir, $publicDir);

        return true;
    }

    /**
     * Get the path to /public/theme/ where published assets are served.
     */
    public function getPublicThemeAssetsPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        return $basePath . '/public/theme';
    }

    /**
     * Check if a specific theme is currently live.
     */
    public function isThemeLive(string $slug): bool
    {
        $theme = Theme::findOneBy(['slug' => $slug]);
        return $theme && $theme->isLive();
    }

    /**
     * Delete a theme (files + DB record). Cannot delete live theme.
     */
    public function deleteTheme(string $slug): bool
    {
        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme || $theme->isLive()) {
            return false;
        }

        // Delete files
        $themePath = $this->getThemePath($slug);
        if (is_dir($themePath)) {
            $this->deleteDirectory($themePath);
        }

        // Delete DB record
        $theme->delete();

        return true;
    }

    /**
     * Get the themes base path.
     */
    public function getThemesBasePath(): string
    {
        return $this->themesBasePath;
    }

    // --- Pages Management ---

    /**
     * Get the path to a theme's pages.json config.
     */
    public function getPagesConfigPath(string $slug): string
    {
        return $this->getThemePath($slug) . '/pages.json';
    }

    /**
     * Get all pages from a theme's pages.json.
     */
    public function getPages(?string $slug = null): array
    {
        $slug = $slug ?? $this->getEffectiveTheme();
        $path = $this->getPagesConfigPath($slug);

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        return $data['pages'] ?? [];
    }

    /**
     * Save a page entry to pages.json (add or update by template name).
     */
    public function savePage(string $slug, array $page): void
    {
        $path = $this->getPagesConfigPath($slug);
        $data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        $pages = $data['pages'] ?? [];

        // Update existing or add new
        $found = false;
        foreach ($pages as $i => $existing) {
            if ($existing['template'] === $page['template']) {
                $pages[$i] = $page;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $pages[] = $page;
        }

        $data['pages'] = $pages;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Delete a page from pages.json and optionally delete the template file.
     */
    public function deletePage(string $slug, string $template, bool $deleteFile = true): bool
    {
        $path = $this->getPagesConfigPath($slug);
        if (!file_exists($path)) {
            return false;
        }

        $data = json_decode(file_get_contents($path), true);
        $pages = $data['pages'] ?? [];

        $filtered = array_values(array_filter($pages, function ($p) use ($template) {
            return $p['template'] !== $template;
        }));

        if (count($filtered) === count($pages)) {
            return false; // Not found
        }

        $data['pages'] = $filtered;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Delete template file
        if ($deleteFile) {
            $templateFile = $this->getTemplatesPath($slug) . '/' . $template . '.twig';
            if (file_exists($templateFile)) {
                unlink($templateFile);
            }
        }

        return true;
    }

    /**
     * Get available layouts for a theme (from filesystem).
     */
    public function getLayoutFiles(string $slug): array
    {
        $layoutsDir = $this->getThemePath($slug) . '/layouts';
        $layouts = [];

        if (!is_dir($layoutsDir)) {
            return $layouts;
        }

        foreach (scandir($layoutsDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (str_ends_with($file, '.twig')) {
                $name = basename($file, '.twig');
                $layouts[] = $name;
            }
        }

        return $layouts;
    }

    // --- Private Helpers ---

    private function createStarterTheme(string $path, string $name): void
    {
        $dirs = ['layouts', 'templates', 'snippets', 'sections', 'config', 'assets/css', 'assets/js', 'assets/images', 'assets/fonts'];
        foreach ($dirs as $dir) {
            mkdir($path . '/' . $dir, 0755, true);
        }

        // Copy starter sections from stubs
        $this->copyStarterSections($path);

        // Copy config stubs (settings_schema.json, settings_data.json)
        $this->copyConfigStubs($path);

        // theme.json
        $config = [
            'name' => $name,
            'version' => '1.0.0',
            'layouts' => [
                'base' => ['name' => 'Base Layout', 'description' => 'Default page layout'],
                'full-width' => ['name' => 'Full Width', 'description' => 'No container constraints'],
            ],
        ];
        file_put_contents($path . '/theme.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Base layout
        $baseLayout = <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}{{ config('app.name', 'ZephyrPHP') }}{% endblock %}</title>
    {% block meta %}{% endblock %}
    <link rel="stylesheet" href="/assets/css/app.css">
    {% block styles %}{% endblock %}
</head>
<body>
    {% block body %}
    <main>
        {% block content %}{% endblock %}
    </main>
    {% endblock %}
    {% block scripts %}{% endblock %}
</body>
</html>
TWIG;
        file_put_contents($path . '/layouts/base.twig', $baseLayout);

        // Full-width layout
        $fullWidth = <<<'TWIG'
{% extends "@theme/layouts/base.twig" %}

{% block body %}
<main class="full-width">
    {% block content %}{% endblock %}
</main>
{% endblock %}
TWIG;
        file_put_contents($path . '/layouts/full-width.twig', $fullWidth);

        // Header snippet
        $header = <<<'TWIG'
{# Header snippet - include with: {% include "@theme/snippets/header.twig" %} #}
<header>
    <nav>
        <a href="/">{{ config('app.name', 'ZephyrPHP') }}</a>
    </nav>
</header>
TWIG;
        file_put_contents($path . '/snippets/header.twig', $header);

        // Footer snippet
        $footer = <<<'TWIG'
{# Footer snippet - include with: {% include "@theme/snippets/footer.twig" %} #}
<footer>
    <p>&copy; {{ "now"|date("Y") }} {{ config('app.name', 'ZephyrPHP') }}</p>
</footer>
TWIG;
        file_put_contents($path . '/snippets/footer.twig', $footer);

        // Home page template
        $home = <<<'TWIG'
{% extends "@theme/layouts/base.twig" %}

{% block title %}{{ page.title ?? config('app.name', 'ZephyrPHP') }}{% endblock %}

{% block content %}
<div class="container" style="padding: 60px 20px; text-align: center;">
    <h1>{{ page.title ?? config('app.name', 'ZephyrPHP') }}</h1>
    <p>Your site is ready. Edit this page from the theme editor.</p>
</div>
{% endblock %}
TWIG;
        file_put_contents($path . '/templates/home.twig', $home);

        // pages.json
        $pagesConfig = [
            'pages' => [
                [
                    'title' => 'Home',
                    'slug' => '/',
                    'template' => 'home',
                    'layout' => 'base',
                ],
            ],
        ];
        file_put_contents($path . '/pages.json', json_encode($pagesConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Copy all starter section .twig files from the stubs directory into a theme.
     */
    private function copyStarterSections(string $themePath): void
    {
        $stubsDir = __DIR__ . '/../../stubs/sections';
        if (!is_dir($stubsDir)) {
            return;
        }

        $targetDir = $themePath . '/sections';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        foreach (scandir($stubsDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (str_ends_with($file, '.twig')) {
                copy($stubsDir . '/' . $file, $targetDir . '/' . $file);
            }
        }
    }

    /**
     * Copy config stubs (settings_schema.json, settings_data.json) into a theme.
     */
    private function copyConfigStubs(string $themePath): void
    {
        $stubsDir = __DIR__ . '/../../stubs/config';
        if (!is_dir($stubsDir)) {
            return;
        }

        $targetDir = $themePath . '/config';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        foreach (scandir($stubsDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (str_ends_with($file, '.json')) {
                copy($stubsDir . '/' . $file, $targetDir . '/' . $file);
            }
        }
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
