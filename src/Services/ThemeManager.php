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
        $path = $this->getThemePath($slug) . '/' . $relativePath;
        if (file_exists($path) && !str_contains(realpath($path), '..')) {
            return file_get_contents($path);
        }
        return null;
    }

    /**
     * Write any file to a theme.
     */
    public function writeFile(string $relativePath, string $content, string $slug): bool
    {
        $path = $this->getThemePath($slug) . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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

        $dirs = ['layouts', 'templates', 'snippets', 'sections'];
        foreach ($dirs as $dir) {
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

            return true;
        } catch (\Exception $e) {
            return false;
        }
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

    // --- Private Helpers ---

    private function createStarterTheme(string $path, string $name): void
    {
        $dirs = ['layouts', 'templates', 'snippets', 'sections'];
        foreach ($dirs as $dir) {
            mkdir($path . '/' . $dir, 0755, true);
        }

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
