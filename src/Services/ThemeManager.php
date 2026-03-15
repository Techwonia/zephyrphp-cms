<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Theme;

class ThemeManager
{
    private string $themesBasePath;
    private string $publicThemesPath;
    private ?array $themeConfig = null;
    private ?string $effectiveThemeSlug = null;

    public function __construct()
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $viewsPath = $_ENV['VIEWS_PATH'] ?? 'pages';
        $this->themesBasePath = $basePath . '/' . $viewsPath . '/themes';
        $this->publicThemesPath = $basePath . '/public/themes';
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

        return $_ENV['CMS_THEME'] ?? 'starter';
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
            // Duplicate from existing theme (templates, layouts, etc.)
            $this->copyDirectory($this->getThemePath($copyFrom), $themePath);

            // Duplicate public assets
            $sourceAssets = $this->getThemeAssetsPath($copyFrom);
            $targetAssets = $this->getThemeAssetsPath($slug);
            if (is_dir($sourceAssets)) {
                $this->copyDirectory($sourceAssets, $targetAssets);
            }

            // Update theme.json with new name
            $configFile = $themePath . '/theme.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
                $config['name'] = $name;
                file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        } else {
            // Create fresh theme with starter files
            $this->createStarterTheme($themePath, $name, $slug);
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

        // Delete template files
        $themePath = $this->getThemePath($slug);
        if (is_dir($themePath)) {
            $this->deleteDirectory($themePath);
        }

        // Delete public assets (public/themes/{slug}/)
        $assetsPath = $this->getThemeAssetsPath($slug);
        if (is_dir($assetsPath)) {
            $this->deleteDirectory($assetsPath);
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

    /**
     * Get the public assets path for a specific theme.
     * Assets live directly at public/themes/{slug}/ — served by the web server.
     */
    public function getThemeAssetsPath(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (empty($slug)) {
            throw new \InvalidArgumentException('Invalid theme slug.');
        }
        return $this->publicThemesPath . '/' . $slug;
    }

    /**
     * Get the public themes base path.
     */
    public function getPublicThemesPath(): string
    {
        return $this->publicThemesPath;
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

    private function createStarterTheme(string $path, string $name, string $slug): void
    {
        $dirs = ['layouts', 'templates', 'snippets', 'sections', 'config'];
        foreach ($dirs as $dir) {
            mkdir($path . '/' . $dir, 0755, true);
        }

        // Create public assets directory at public/themes/{slug}/
        $assetsPath = $this->getThemeAssetsPath($slug);
        $assetDirs = ['css', 'js', 'images', 'fonts'];
        foreach ($assetDirs as $dir) {
            mkdir($assetsPath . '/' . $dir, 0755, true);
        }

        // Copy starter sections from stubs
        $this->copyStarterSections($path);

        // Copy config stubs (settings_schema.json, settings_data.json)
        $this->copyConfigStubs($path);

        // theme.json — enhanced asset schema with defer, preload, preconnect, CSP support
        $config = [
            'name' => $name,
            'version' => '1.0.0',
            'layouts' => [
                'base' => ['name' => 'Base Layout', 'description' => 'Default page layout'],
            ],
            'assets' => [
                'css' => [
                    'css/base.css',
                ],
                'js' => [
                    ['path' => 'js/base.js', 'defer' => true],
                ],
                'preload' => [
                ],
                'preconnect' => [
                ],
                'external_css' => [
                ],
                'external_js' => [
                ],
                'csp' => [
                    'default-src' => "'self'",
                    'script-src' => "'self'",
                    'style-src' => "'self' 'unsafe-inline'",
                    'img-src' => "'self' data:",
                    'font-src' => "'self'",
                ],
            ],
        ];
        file_put_contents($path . '/theme.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Base layout — uses assets_head()/assets_footer() for automatic asset management
        $baseLayout = <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{ assets_csp()|raw }}
    <title>{% block title %}{{ seo.title ?? page.title ?? config('app.name', 'ZephyrPHP') }}{% endblock %}</title>
    {% block meta %}{% endblock %}
    {{ assets_head()|raw }}
    {% if theme_settings is defined and theme_settings %}
    <style>
        :root {
            --color-primary: {{ theme_settings.primary_color|default('#111111') }};
            --color-accent: {{ theme_settings.secondary_color|default('#555555') }};
            --color-bg: {{ theme_settings.bg_color|default('#ffffff') }};
            --color-text: {{ theme_settings.text_color|default('#111111') }};
            --color-muted: {{ theme_settings.text_secondary_color|default('#666666') }};
            --font-heading: '{{ theme_settings.heading_font|default('Inter') }}', system-ui, sans-serif;
            --font-body: '{{ theme_settings.body_font|default('Inter') }}', system-ui, sans-serif;
            --font-size: {{ theme_settings.base_font_size|default(16) }}px;
        }
    </style>
    {% endif %}
    {% block styles %}{% endblock %}
</head>
<body>
    {% include "@theme/snippets/header.twig" %}

    {% block body %}
    {% if use_sections is defined and use_sections %}
        {{ sections_html|raw }}
    {% else %}
    <main>
        {% block content %}{% endblock %}
    </main>
    {% endif %}
    {% endblock %}

    {% include "@theme/snippets/footer.twig" %}
    {{ assets_footer()|raw }}
    {% block scripts %}{% endblock %}
</body>
</html>
TWIG;
        file_put_contents($path . '/layouts/base.twig', $baseLayout);

        // Header snippet
        $header = <<<'TWIG'
<header class="site-header">
    <div class="container">
        <nav class="nav">
            <a href="/" class="nav__logo">{{ config('app.name', 'ZephyrPHP') }}</a>
            <ul class="nav__menu">
                <li><a href="/">Home</a></li>
                {% if auth_check() %}
                    <li><a href="/cms">Dashboard</a></li>
                {% else %}
                    <li><a href="/login">Sign In</a></li>
                {% endif %}
            </ul>
        </nav>
    </div>
</header>
TWIG;
        file_put_contents($path . '/snippets/header.twig', $header);

        // Footer snippet
        $footer = <<<'TWIG'
<footer class="site-footer">
    <div class="container">
        <div class="footer__inner">
            <p class="footer__copy">&copy; {{ "now"|date("Y") }} {{ config('app.name', 'ZephyrPHP') }}</p>
        </div>
    </div>
</footer>
TWIG;
        file_put_contents($path . '/snippets/footer.twig', $footer);

        // Home page template
        $home = <<<'TWIG'
{% extends "@theme/layouts/base.twig" %}

{% block title %}{{ page.title ?? config('app.name', 'ZephyrPHP') }}{% endblock %}

{% block content %}
<section class="hero">
    <h1 class="hero__title">{{ config('app.name', 'ZephyrPHP') }}</h1>
    <p class="hero__subtitle">Your site is ready. Sign in to start building.</p>
    <div class="hero__actions">
        <a href="/login" class="btn btn--primary">Sign In</a>
        <a href="/register" class="btn btn--outline">Create Account</a>
    </div>
</section>
{% endblock %}
TWIG;
        file_put_contents($path . '/templates/home.twig', $home);

        // Starter CSS (in public/themes/{slug}/css/)
        $starterCss = <<<'CSS'
/* Theme Stylesheet */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --color-primary: #111;
    --color-accent: #555;
    --color-bg: #fff;
    --color-text: #111;
    --color-muted: #666;
    --color-border: #e5e5e5;
    --color-surface: #fafafa;
    --font-heading: var(--font-body);
    --font-body: 'Inter', system-ui, -apple-system, sans-serif;
    --font-size: 16px;
    --max-width: 1120px;
    --gap: 24px;
    --radius: 6px;
    --transition: 0.2s ease;
}

html { font-size: var(--font-size); scroll-behavior: smooth; }

body {
    font-family: var(--font-body);
    background: var(--color-bg);
    color: var(--color-text);
    line-height: 1.65;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    -webkit-font-smoothing: antialiased;
}

.container { max-width: var(--max-width); margin: 0 auto; padding: 0 var(--gap); width: 100%; }

.site-header { border-bottom: 1px solid var(--color-border); }
.nav { display: flex; justify-content: space-between; align-items: center; height: 56px; }
.nav__logo { font-size: 1.125rem; font-weight: 700; color: var(--color-text); text-decoration: none; letter-spacing: -0.02em; }
.nav__menu { display: flex; list-style: none; gap: 28px; align-items: center; }
.nav__menu a { color: var(--color-muted); text-decoration: none; font-size: 0.875rem; font-weight: 500; transition: color var(--transition); }
.nav__menu a:hover { color: var(--color-text); }

.hero { padding: 100px 0 80px; text-align: center; }
.hero__title { font-family: var(--font-heading); font-size: clamp(2.25rem, 5vw, 3.5rem); font-weight: 800; letter-spacing: -0.03em; line-height: 1.1; margin-bottom: 16px; }
.hero__subtitle { font-size: 1.125rem; color: var(--color-muted); max-width: 480px; margin: 0 auto 32px; }
.hero__actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

.btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 24px; border-radius: var(--radius); font-size: 0.875rem; font-weight: 600; text-decoration: none; border: 1px solid transparent; cursor: pointer; transition: all var(--transition); }
.btn--primary { background: var(--color-text); color: var(--color-bg); border-color: var(--color-text); }
.btn--primary:hover { opacity: 0.85; }
.btn--outline { background: transparent; color: var(--color-text); border-color: var(--color-border); }
.btn--outline:hover { border-color: var(--color-text); }

.site-footer { border-top: 1px solid var(--color-border); padding: 32px 0; margin-top: auto; }
.footer__inner { display: flex; justify-content: space-between; align-items: center; }
.footer__copy { font-size: 0.8125rem; color: var(--color-muted); }

.prose { max-width: 680px; }
.prose p { margin-bottom: 1em; }
.prose h2 { font-size: 1.5rem; margin: 2em 0 0.75em; font-weight: 700; }
.prose h3 { font-size: 1.25rem; margin: 1.5em 0 0.5em; font-weight: 600; }

@media (max-width: 640px) {
    .nav__menu { gap: 16px; }
    .hero { padding: 60px 0 48px; }
    .hero__actions { flex-direction: column; align-items: stretch; }
    .footer__inner { flex-direction: column; gap: 12px; text-align: center; }
}
CSS;
        file_put_contents($assetsPath . '/css/base.css', $starterCss);

        // Starter JS (in public/themes/{slug}/js/)
        file_put_contents($assetsPath . '/js/base.js', "/* Theme JavaScript */\n");

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
