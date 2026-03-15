<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms;

use ZephyrPHP\Container\Container;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\SectionManager;
use ZephyrPHP\Cms\Services\SidebarManager;
use ZephyrPHP\Cms\Services\DashboardManager;
use ZephyrPHP\Cms\Services\SettingsManager;
use ZephyrPHP\Cms\Services\ThemeInstaller;
use ZephyrPHP\Cms\Services\AssetBundler;
use ZephyrPHP\Cms\Extensions\ThemeAssetExtension;
use ZephyrPHP\Database\EntityManager;

class CmsServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(SchemaManager::class, function () {
            return new SchemaManager();
        });

        $container->alias('cms.schema', SchemaManager::class);

        $container->singleton(ThemeManager::class, function () {
            return new ThemeManager();
        });

        $container->alias('cms.theme', ThemeManager::class);

        $container->singleton(SectionManager::class, function () use ($container) {
            return new SectionManager($container->make(ThemeManager::class));
        });

        $container->alias('cms.sections', SectionManager::class);

        // Register admin extension managers
        $container->singleton(SidebarManager::class, fn() => SidebarManager::getInstance());
        $container->alias('cms.sidebar', SidebarManager::class);

        $container->singleton(DashboardManager::class, fn() => DashboardManager::getInstance());
        $container->alias('cms.dashboard', DashboardManager::class);

        $container->singleton(SettingsManager::class, fn() => SettingsManager::getInstance());
        $container->alias('cms.settings', SettingsManager::class);

        $container->singleton(ThemeInstaller::class, fn() => new ThemeInstaller(new ThemeManager()));
        $container->alias('cms.theme_installer', ThemeInstaller::class);

        // Register CMS models path with Doctrine
        $em = EntityManager::getInstance();
        $em->addPath(__DIR__ . '/Models');
    }

    public function boot(): void
    {
        $view = \ZephyrPHP\View\View::getInstance();

        // Register Twig namespace for CMS templates
        $view->addNamespace('cms', __DIR__ . '/../views');

        // Register theme namespace (uses effective theme: preview if admin, else live)
        $themeManager = new ThemeManager();
        $themePath = $themeManager->getActiveThemePath();
        if (is_dir($themePath)) {
            $view->addNamespace('theme', $themePath);

            // Prepend theme templates path so render('home') finds theme templates first
            $templatesPath = $themePath . '/templates';
            if (is_dir($templatesPath)) {
                $view->prependTemplatePath($templatesPath);
            }
        }

        // Set Asset path prefix so asset()/css()/js() auto-resolve to the active theme's
        // public directory: public/themes/{slug}/ — no need for a separate theme_asset()
        $effectiveTheme = $themeManager->getEffectiveTheme();
        if ($effectiveTheme) {
            \ZephyrPHP\Asset\Asset::setPathPrefix('themes/' . $effectiveTheme);
        }

        // Register ThemeAssetExtension — provides assets_head(), assets_footer(), assets_csp(), etc.
        $view->addExtension(new ThemeAssetExtension($themeManager));

        // Pass preview state as global Twig vars
        $previewTheme = $themeManager->getPreviewTheme();
        if ($previewTheme) {
            $view->addGlobal('is_theme_preview', true);
            $view->addGlobal('preview_theme_slug', $previewTheme);
        }

        // Load CMS global helper functions (entry, collection)
        require_once __DIR__ . '/helpers.php';

        // Initialize sidebar with default CMS items
        $sidebar = SidebarManager::getInstance();
        $sidebar->registerDefaults();

        // Add Marketplace sidebar item (themes only)
        $sidebar->addItem('content', [
            'id' => 'marketplace',
            'label' => 'Marketplace',
            'url' => '/cms/marketplace',
            'icon' => 'store',
            'match' => 'prefix:/cms/marketplace',
        ]);

        // Add Activity Log sidebar item
        $sidebar->addItem('admin', [
            'id' => 'activity-log',
            'label' => 'Activity Log',
            'url' => '/cms/activity-log',
            'icon' => 'clock',
            'match' => 'prefix:/cms/activity-log',
        ]);

        // Add AI Builder sidebar item
        $sidebar->addItem('content', [
            'id' => 'ai-builder',
            'label' => 'AI Builder',
            'url' => '/cms/ai-builder',
            'icon' => 'sparkles',
            'match' => 'prefix:/cms/ai-builder',
        ]);

        // Add Languages sidebar item
        $sidebar->addItem('admin', [
            'id' => 'languages',
            'label' => 'Languages',
            'url' => '/cms/languages',
            'icon' => 'globe',
            'match' => 'prefix:/cms/languages',
        ]);

        // Add Notifications sidebar item
        $sidebar->addItem('admin', [
            'id' => 'notifications',
            'label' => 'Notifications',
            'url' => '/cms/notifications',
            'icon' => 'bell',
            'match' => 'prefix:/cms/notifications',
        ]);

        // Add Email Templates sidebar item
        $sidebar->addItem('admin', [
            'id' => 'email-templates',
            'label' => 'Email Templates',
            'url' => '/cms/email-templates',
            'icon' => 'mail',
            'match' => 'prefix:/cms/email-templates',
        ]);

        // Add Webhooks sidebar item
        $sidebar->addItem('admin', [
            'id' => 'webhooks',
            'label' => 'Webhooks',
            'url' => '/cms/webhooks',
            'icon' => 'link',
            'match' => 'prefix:/cms/webhooks',
        ]);

        $sidebar->addItem('admin', [
            'id' => 'permission-builder',
            'label' => 'Permission Builder',
            'url' => '/cms/permissions',
            'icon' => 'shield',
            'permission' => 'roles.manage',
            'match' => 'prefix:/cms/permissions',
        ]);

        // Add Mail Settings to settings section
        $sidebar->addItem('settings', [
            'id' => 'mail',
            'label' => 'Mail',
            'url' => '/cms/settings/mail',
            'icon' => 'mail',
            'permission' => 'settings.view',
            'position' => 5,
            'match' => 'prefix:/cms/settings/mail',
        ]);

        // Auth Settings
        $sidebar->addItem('settings', [
            'id' => 'auth-settings',
            'label' => 'Authentication',
            'url' => '/cms/settings/auth',
            'icon' => 'shield',
            'permission' => 'settings.view',
            'position' => 6,
            'match' => 'prefix:/cms/settings/auth',
        ]);

        // API Settings
        $sidebar->addItem('settings', [
            'id' => 'api-settings',
            'label' => 'API',
            'url' => '/cms/settings/api',
            'icon' => 'code',
            'permission' => 'settings.view',
            'position' => 7,
            'match' => 'prefix:/cms/settings/api',
        ]);

        // Cache Settings
        $sidebar->addItem('settings', [
            'id' => 'cache-settings',
            'label' => 'Cache',
            'url' => '/cms/settings/cache',
            'icon' => 'zap',
            'permission' => 'settings.view',
            'position' => 8,
            'match' => 'prefix:/cms/settings/cache',
        ]);

        // Error Pages
        $sidebar->addItem('settings', [
            'id' => 'error-pages',
            'label' => 'Error Pages',
            'url' => '/cms/settings/error-pages',
            'icon' => 'alert-triangle',
            'permission' => 'settings.edit',
            'position' => 9,
            'match' => 'prefix:/cms/settings/error-pages',
        ]);

        // System section
        $sidebar->addSection('system', 'System', 35);

        $sidebar->addItem('system', [
            'id' => 'system-health',
            'label' => 'Health Check',
            'url' => '/cms/system/health',
            'icon' => 'heart',
            'match' => 'exact:/cms/system/health',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-logs',
            'label' => 'Log Viewer',
            'url' => '/cms/system/logs',
            'icon' => 'file-text',
            'match' => 'prefix:/cms/system/logs',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-cache',
            'label' => 'Cache',
            'url' => '/cms/system/cache',
            'icon' => 'zap',
            'match' => 'exact:/cms/system/cache',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-maintenance',
            'label' => 'Maintenance',
            'url' => '/cms/system/maintenance',
            'icon' => 'tool',
            'match' => 'exact:/cms/system/maintenance',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-database',
            'label' => 'Database',
            'url' => '/cms/system/database',
            'icon' => 'database',
            'match' => 'prefix:/cms/system/database',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-backups',
            'label' => 'Backups',
            'url' => '/cms/system/backups',
            'icon' => 'archive',
            'match' => 'prefix:/cms/system/backups',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-files',
            'label' => 'File Manager',
            'url' => '/cms/system/files',
            'icon' => 'folder',
            'match' => 'prefix:/cms/system/files',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-translations',
            'label' => 'Translations',
            'url' => '/cms/system/translations',
            'icon' => 'globe',
            'match' => 'prefix:/cms/system/translations',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-routes',
            'label' => 'Routes',
            'url' => '/cms/system/routes',
            'icon' => 'map',
            'match' => 'exact:/cms/system/routes',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-modules',
            'label' => 'Modules',
            'url' => '/cms/system/modules',
            'icon' => 'package',
            'match' => 'exact:/cms/system/modules',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-scheduled-tasks',
            'label' => 'Scheduled Tasks',
            'url' => '/cms/system/scheduled-tasks',
            'icon' => 'clock',
            'match' => 'prefix:/cms/system/scheduled-tasks',
            'permission' => 'settings.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-sessions',
            'label' => 'Sessions',
            'url' => '/cms/system/sessions',
            'icon' => 'monitor',
            'match' => 'prefix:/cms/system/sessions',
            'permission' => 'users.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-workflow',
            'label' => 'Workflow Visualizer',
            'url' => '/cms/system/workflow',
            'icon' => 'git-branch',
            'match' => 'exact:/cms/system/workflow',
            'permission' => 'entries.view',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-api-analytics',
            'label' => 'API Analytics',
            'url' => '/cms/system/api-analytics',
            'icon' => 'bar-chart',
            'match' => 'exact:/cms/system/api-analytics',
            'permission' => 'api-keys.manage',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-queue',
            'label' => 'Queue Monitor',
            'url' => '/cms/system/queue',
            'icon' => 'layers',
            'match' => 'prefix:/cms/system/queue',
            'permission' => 'settings.edit',
        ]);

        $sidebar->addItem('system', [
            'id' => 'system-monitor',
            'label' => 'System Monitor',
            'url' => '/cms/system/monitor',
            'icon' => 'activity',
            'match' => 'prefix:/cms/system/monitor',
            'permission' => 'settings.view',
        ]);

        // Register built-in dashboard widgets
        DashboardManager::getInstance()->registerBuiltInWidgets();

        // Register Twig helper functions
        $this->registerTwigHelpers($view, $themeManager);

        // Load CMS routes
        $routesFile = __DIR__ . '/../routes/cms.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }

        // Register theme page routes from pages.json
        $this->registerThemePageRoutes($themeManager);

        // Register frontend routes for collections with url_prefix
        $this->registerCollectionRoutes($themeManager);

        // Auto-create CMS tables if they don't exist
        $this->ensureTablesExist();
    }

    private function registerTwigHelpers(\ZephyrPHP\View\View $view, ThemeManager $themeManager): void
    {
        // Register sidebar and dashboard as Twig globals (lazy-loaded)
        $view->addFunction('cms_sidebar', function () {
            return SidebarManager::getInstance()->getSections();
        });

        $view->addFunction('cms_sidebar_active', function (string $currentPath, array $item) {
            return SidebarManager::isActive($currentPath, $item);
        });

        $view->addFunction('cms_dashboard_widgets', function () {
            return DashboardManager::getInstance()->getWidgets();
        });

        $view->addFunction('cms_widget_size', function (string $size) {
            return DashboardManager::sizeClass($size);
        });

        // collection() and entry() - delegate to global helpers from cms/src/helpers.php
        $view->addFunction('collection', function (string $slug, array $options = []) {
            return collection($slug, $options);
        });

        $view->addFunction('entry', function (string $ptSlug, string|int $identifier) {
            return entry($ptSlug, $identifier);
        });

        // Asset functions (assets_head, assets_footer, assets_csp, etc.) are provided
        // by ThemeAssetExtension registered in boot() — no inline functions needed here.

        // theme_config() - Expose theme info to templates
        $view->addFunction('theme_config', function () use ($themeManager) {
            return $themeManager->getThemeConfig();
        });

        // theme_preview(slug) - Generate preview URL with query param
        $view->addFunction('theme_preview_url', function (string $slug) {
            return '/?theme_preview=' . urlencode($slug);
        });

        // theme_settings() - Get global theme settings (merged with defaults)
        $view->addFunction('theme_settings', function () use ($themeManager) {
            try {
                $sectionManager = new SectionManager($themeManager);
                return $sectionManager->getGlobalSettings();
            } catch (\Exception $e) {
                return [];
            }
        });

        // has_sections(pageTemplate) - Check if a page has sections configured
        $view->addFunction('has_sections', function (string $pageTemplate) use ($themeManager) {
            try {
                $sectionManager = new SectionManager($themeManager);
                return $sectionManager->hasSections(null, $pageTemplate);
            } catch (\Exception $e) {
                return false;
            }
        });

        // render_sections(pageTemplate) - Render all sections for a page
        $view->addFunction('render_sections', function (string $pageTemplate) use ($themeManager) {
            try {
                $sectionManager = new SectionManager($themeManager);
                return $sectionManager->renderSections($pageTemplate);
            } catch (\Exception $e) {
                return '<!-- Section render error: ' . htmlspecialchars($e->getMessage()) . ' -->';
            }
        });

        // cms_can(permission) - Check if current user has a CMS permission
        $view->addFunction('cms_can', function (string $permission) {
            return \ZephyrPHP\Cms\Services\PermissionService::can($permission);
        });

        // render_collection_form(slug, options) - Render an HTML form for public collection submission
        $view->addFunction('render_collection_form', function (string $slug, array $options = []) {
            return $this->renderCollectionForm($slug, $options);
        });

        // SEO helpers for theme templates
        $view->addFunction('seo_meta', function (array $entry = [], ?string $collectionSlug = null) {
            return $this->buildSeoOutput('meta', $entry, $collectionSlug);
        });

        $view->addFunction('og_tags', function (array $entry = [], ?string $collectionSlug = null) {
            return $this->buildSeoOutput('og', $entry, $collectionSlug);
        });

        $view->addFunction('json_ld', function (array $entry = [], ?string $collectionSlug = null) {
            return $this->buildSeoOutput('jsonld', $entry, $collectionSlug);
        });

        $view->addFunction('seo_title', function (array $entry = [], ?string $collectionSlug = null) {
            return $this->buildSeoOutput('title', $entry, $collectionSlug);
        });

        $view->addFunction('notification_count', function () {
            if (!\ZephyrPHP\Auth\Auth::check()) return 0;
            $userId = \ZephyrPHP\Auth\Auth::user()?->getId();
            if (!$userId) return 0;
            return Services\NotificationService::getUnreadCount($userId);
        });

        $view->addFunction('current_locale', function () {
            return Services\TranslationService::detectLocale();
        });

        $view->addFunction('available_locales', function () {
            return Services\TranslationService::getActiveLanguages();
        });

        $view->addFunction('translate', function (string $key, ?string $locale = null) {
            // Simple key-based translation — returns key if no translation found
            return $key;
        });

        // Workflow helpers
        $view->addFunction('workflow_stages', function ($collection) {
            if ($collection instanceof \ZephyrPHP\Cms\Models\Collection) {
                return $collection->getWorkflowStages();
            }
            return ['draft', 'review', 'approved', 'published'];
        });

        $view->addFunction('workflow_current', function (array $entry) {
            return $entry['status'] ?? 'draft';
        });

        $view->addFunction('workflow_history', function (string $tableName, $entryId) {
            return Services\WorkflowService::getHistory($tableName, $entryId);
        });

        $view->addFunction('entry_translated', function (array $entry, ?string $locale = null) {
            if ($locale === null) {
                $locale = Services\TranslationService::detectLocale();
            }
            // Need collection context — get table from entry if available
            // This is a convenience function for theme templates
            $tableName = $entry['_table_name'] ?? null;
            if (!$tableName) return $entry;
            return Services\TranslationService::resolveEntry($entry, $tableName, $locale);
        });
    }

    private function registerThemePageRoutes(ThemeManager $themeManager): void
    {
        try {
            $pages = $themeManager->getPages();
            foreach ($pages as $page) {
                $template = $page['template'];
                $slug = $page['slug'] ?? '/';
                $title = $page['title'] ?? '';
                $layout = $page['layout'] ?? 'base';
                $controllerName = $page['controller'] ?? null;
                $authRequired = (bool) ($page['auth_required'] ?? false);
                $allowedRoles = $page['allowed_roles'] ?? [];

                // Check if this route has dynamic parameters like {slug}
                $isDynamic = (bool) preg_match('/\{(\w+)\}/', $slug);

                // Build middleware array
                $middleware = [];
                if ($authRequired) {
                    $middleware[] = \ZephyrPHP\Middleware\AuthMiddleware::class;
                }

                if ($isDynamic) {
                    \ZephyrPHP\Router\Route::get($slug, function (...$args) use ($template, $title, $layout, $controllerName, $themeManager, $authRequired, $allowedRoles) {
                        if ($authRequired) {
                            $this->enforcePageAuth($allowedRoles);
                        }
                        $params = $args;
                        $params['_query'] = $_GET;
                        $this->renderThemePage($themeManager, $template, $title, $layout, $controllerName, $params);
                    }, $middleware);
                } else {
                    \ZephyrPHP\Router\Route::get($slug, function () use ($template, $title, $layout, $controllerName, $themeManager, $authRequired, $allowedRoles) {
                        if ($authRequired) {
                            $this->enforcePageAuth($allowedRoles);
                        }
                        $params = ['_query' => $_GET];
                        $this->renderThemePage($themeManager, $template, $title, $layout, $controllerName, $params);
                    }, $middleware);
                }
            }
        } catch (\Exception $e) {
            // pages.json may not exist yet
        }
    }

    /**
     * Enforce auth and role checks for protected theme pages.
     */
    private function enforcePageAuth(array $allowedRoles): void
    {
        // AuthMiddleware already handles the redirect-to-login,
        // this method adds role-based access on top
        if (empty($allowedRoles)) {
            return; // Any authenticated user can access
        }

        try {
            $user = \ZephyrPHP\Auth\Auth::user();
            if (!$user || !method_exists($user, 'hasAnyRole')) {
                return; // Can't check roles, allow access if authenticated
            }

            if ($user->hasAnyRole($allowedRoles)) {
                return; // User has an allowed role
            }

            // User is logged in but doesn't have the required role
            http_response_code(403);
            $view = \ZephyrPHP\View\View::getInstance();
            if ($view->exists('errors/403')) {
                echo $view->render('errors/403', []);
            } else {
                echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body>';
                echo '<div style="max-width:600px;margin:4rem auto;text-align:center;font-family:system-ui;">';
                echo '<h1>403 — Access Denied</h1>';
                echo '<p>You do not have permission to view this page.</p>';
                echo '<a href="/">Go Home</a>';
                echo '</div></body></html>';
            }
            exit;
        } catch (\Exception $e) {
            // Auth not available, allow through
        }
    }

    private function renderThemePage(ThemeManager $themeManager, string $template, string $title, string $layout, ?string $controllerName, array $params): void
    {
        $view = \ZephyrPHP\View\View::getInstance();
        $sectionManager = new SectionManager($themeManager);

        // Execute controller if one exists
        $controllerData = [];
        if ($controllerName) {
            $controllerPath = $themeManager->getActiveThemePath() . '/controllers/' . $controllerName . '.php';
            if (file_exists($controllerPath)) {
                $handler = require $controllerPath;
                if (is_callable($handler)) {
                    $result = $handler($params);
                    if (is_array($result)) {
                        $controllerData = $result;
                    }
                }
            }
        }

        $pageData = array_merge($controllerData, [
            'page' => array_merge(['title' => $title, 'template' => $template], $controllerData['page'] ?? []),
            'params' => $params,
            'theme_settings' => $sectionManager->getGlobalSettings(),
        ]);

        // Check if this page has sections configured
        if ($sectionManager->hasSections(null, $template)) {
            $sectionsHtml = $sectionManager->renderSections($template);
            $pageData['sections_html'] = $sectionsHtml;
            $pageData['use_sections'] = true;
            $html = $view->render('@theme/layouts/' . $layout, $pageData);
        } else {
            $html = $view->render('@theme/templates/' . $template, $pageData);
        }

        // Bundle companion CSS/JS into single minified files per page
        $themeSlug = $themeManager->getEffectiveTheme();
        $assetsPath = $themeManager->getThemeAssetsPath($themeSlug);
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $bundler = new AssetBundler($basePath . '/public', $themeSlug);

        // Collect all CSS files: section companions + page companion
        $cssFiles = $sectionManager->getCollectedCssPaths();
        $pageCssFile = $assetsPath . '/css/' . $template . '.css';
        if (file_exists($pageCssFile)) {
            $cssFiles[] = $pageCssFile;
        }

        // Collect JS files: page companion
        $jsFiles = [];
        $pageJsFile = $assetsPath . '/js/' . $template . '.js';
        if (file_exists($pageJsFile)) {
            $jsFiles[] = $pageJsFile;
        }

        // Bundle and inject CSS
        $bundleName = 'page-' . preg_replace('/[^a-z0-9_-]/i', '-', $template);
        $cssBundleUrl = $bundler->bundleCss($cssFiles, $bundleName);
        if ($cssBundleUrl) {
            $cssTag = '<link rel="stylesheet" href="' . htmlspecialchars($cssBundleUrl) . '">';
            $html = str_replace('</head>', $cssTag . "\n</head>", $html);
        }

        // Bundle and inject JS
        $jsBundleUrl = $bundler->bundleJs($jsFiles, $bundleName);
        if ($jsBundleUrl) {
            $jsTag = '<script src="' . htmlspecialchars($jsBundleUrl) . '" defer></script>';
            $html = str_replace('</body>', $jsTag . "\n</body>", $html);
        }

        echo $html;
    }

    private function registerCollectionRoutes(ThemeManager $themeManager): void
    {
        try {
            $collections = \ZephyrPHP\Cms\Models\Collection::findAll();
            $view = \ZephyrPHP\View\View::getInstance();
            $schema = new SchemaManager();

            foreach ($collections as $collection) {
                $prefix = $collection->getUrlPrefix();
                if (empty($prefix)) {
                    continue;
                }

                $prefix = '/' . ltrim($prefix, '/');
                $collSlug = $collection->getSlug();
                $perPage = $collection->getItemsPerPage();

                // List route: /prefix
                \ZephyrPHP\Router\Route::get($prefix, function () use ($collSlug, $perPage, $view, $themeManager, $collection) {
                    $page = max(1, (int) ($_GET['page'] ?? 1));
                    $result = collection($collSlug, ['page' => $page, 'per_page' => $perPage]);

                    $sectionManager = new SectionManager($themeManager);
                    $data = [
                        'collection' => [
                            'name' => $collection->getName(),
                            'slug' => $collSlug,
                            'description' => $collection->getDescription(),
                        ],
                        'entries' => $result,
                        'page' => ['title' => $collection->getName()],
                        'theme_settings' => $sectionManager->getGlobalSettings(),
                    ];

                    // Try theme template first, then fallback
                    $templates = [
                        "@theme/templates/collection-{$collSlug}",
                        '@theme/templates/collection',
                    ];

                    foreach ($templates as $tpl) {
                        if ($view->exists($tpl)) {
                            echo $view->render($tpl, $data);
                            return;
                        }
                    }

                    // Generic fallback — render layout with sections if available
                    if ($sectionManager->hasSections(null, "collection-{$collSlug}")) {
                        $data['sections_html'] = $sectionManager->renderSections("collection-{$collSlug}");
                        $data['use_sections'] = true;
                        echo $view->render('@theme/layouts/base', $data);
                    } else {
                        echo $view->render('@theme/layouts/base', $data);
                    }
                });

                // Detail route: /prefix/{slug} (only if collection has slugs)
                if ($collection->hasSlug()) {
                    \ZephyrPHP\Router\Route::get($prefix . '/{entrySlug}', function (string $entrySlug) use ($collSlug, $view, $themeManager, $collection) {
                        $entryData = entry($collSlug, $entrySlug);
                        if (!$entryData) {
                            http_response_code(404);
                            if ($view->exists('errors/404')) {
                                echo $view->render('errors/404', []);
                            } else {
                                echo '<h1>404 — Not Found</h1>';
                            }
                            return;
                        }

                        // For publishable collections, only show published entries
                        if ($collection->isPublishable() && ($entryData['status'] ?? '') !== 'published') {
                            http_response_code(404);
                            if ($view->exists('errors/404')) {
                                echo $view->render('errors/404', []);
                            } else {
                                echo '<h1>404 — Not Found</h1>';
                            }
                            return;
                        }

                        $sectionManager = new SectionManager($themeManager);

                        // Apply locale translations
                        if ($collection->isTranslatable()) {
                            $locale = Services\TranslationService::detectLocale();
                            $entryData = Services\TranslationService::resolveEntry($entryData, $collection->getTableName(), $locale);
                        }

                        // Resolve SEO meta for this entry
                        $seoMeta = [];
                        if ($collection->isSeoEnabled()) {
                            $seoMeta = Services\SeoService::getEntryMeta($entryData, $collection);
                        }

                        $pageTitle = $entryData['title'] ?? $entryData['name'] ?? $collection->getName();
                        if (!empty($seoMeta['title'])) {
                            $pageTitle = $seoMeta['title'];
                        }

                        $data = [
                            'collection' => [
                                'name' => $collection->getName(),
                                'slug' => $collSlug,
                            ],
                            'entry' => $entryData,
                            'page' => ['title' => $pageTitle],
                            'seo' => $seoMeta,
                            'seo_meta_tags' => $collection->isSeoEnabled() ? Services\SeoService::buildMetaTags($seoMeta) : '',
                            'seo_og_tags' => $collection->isSeoEnabled() ? Services\SeoService::buildOgTags($seoMeta) : '',
                            'seo_json_ld' => $collection->isSeoEnabled() ? '<script type="application/ld+json">' . Services\SeoService::generateJsonLd($entryData, $collection, $this->getBaseUrl()) . '</script>' : '',
                            'theme_settings' => $sectionManager->getGlobalSettings(),
                        ];

                        $templates = [
                            "@theme/templates/collection-{$collSlug}-detail",
                            '@theme/templates/collection-detail',
                        ];

                        foreach ($templates as $tpl) {
                            if ($view->exists($tpl)) {
                                echo $view->render($tpl, $data);
                                return;
                            }
                        }

                        echo $view->render('@theme/layouts/base', $data);
                    });
                }
            }
        } catch (\Exception $e) {
            // Collections may not exist yet
        }
    }

    private function buildSeoOutput(string $type, array $entry, ?string $collectionSlug): string
    {
        try {
            if (empty($entry) || empty($collectionSlug)) {
                return '';
            }

            $collection = \ZephyrPHP\Cms\Models\Collection::findOneBy(['slug' => $collectionSlug]);
            if (!$collection || !$collection->isSeoEnabled()) {
                return '';
            }

            $meta = Services\SeoService::getEntryMeta($entry, $collection);

            return match ($type) {
                'meta' => Services\SeoService::buildMetaTags($meta),
                'og' => Services\SeoService::buildOgTags($meta),
                'jsonld' => '<script type="application/ld+json">' . Services\SeoService::generateJsonLd($entry, $collection, $this->getBaseUrl()) . '</script>',
                'title' => $meta['title'],
                default => '',
            };
        } catch (\Exception $e) {
            return '';
        }
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    private function renderCollectionForm(string $slug, array $options = []): string
    {
        try {
            $collection = \ZephyrPHP\Cms\Models\Collection::findOneBy(['slug' => $slug]);
            if (!$collection || !$collection->isSubmittable()) {
                return '<!-- Collection not found or not submittable -->';
            }

            $fields = $collection->getFields()->toArray();
            $settings = $collection->getSubmitSettings() ?? [];
            $cssClass = $options['class'] ?? 'collection-form';
            $buttonText = $options['button'] ?? $settings['submit_button_text'] ?? 'Submit';

            $html = '<form method="POST" action="/collections/' . htmlspecialchars($slug) . '/submit"';
            $html .= ' class="' . htmlspecialchars($cssClass) . '">';
            $html .= \ZephyrPHP\Security\Csrf::getHiddenInput();

            // Honeypot field (hidden from users, catches bots)
            if (!empty($settings['honeypot_enabled'])) {
                $html .= '<div style="position:absolute;left:-9999px;"><input type="text" name="_hp_email" tabindex="-1" autocomplete="off"></div>';
            }

            foreach ($fields as $field) {
                // Skip types not suitable for public forms
                if (in_array($field->getType(), ['relation', 'file', 'image', 'json', 'richtext', 'slug'])) {
                    continue;
                }

                $fieldSlug = htmlspecialchars($field->getSlug());
                $fieldName = htmlspecialchars($field->getName());
                $required = $field->isRequired() ? ' required' : '';
                $requiredMark = $field->isRequired() ? ' <span class="required">*</span>' : '';

                $html .= '<div class="form-group">';
                $html .= '<label for="' . $fieldSlug . '">' . $fieldName . $requiredMark . '</label>';

                switch ($field->getType()) {
                    case 'textarea':
                        $html .= '<textarea id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control" rows="4"' . $required . '></textarea>';
                        break;

                    case 'select':
                        $choices = $field->getOptions()['choices'] ?? [];
                        $html .= '<select id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        $html .= '<option value="">-- Select --</option>';
                        foreach ($choices as $choice) {
                            $html .= '<option value="' . htmlspecialchars($choice) . '">' . htmlspecialchars($choice) . '</option>';
                        }
                        $html .= '</select>';
                        break;

                    case 'boolean':
                        $html .= '<label class="checkbox-label"><input type="checkbox" id="' . $fieldSlug . '" name="' . $fieldSlug . '" value="1"> ' . $fieldName . '</label>';
                        break;

                    case 'email':
                        $html .= '<input type="email" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        break;

                    case 'url':
                        $html .= '<input type="url" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        break;

                    case 'number':
                    case 'decimal':
                        $step = $field->getType() === 'decimal' ? ' step="0.01"' : '';
                        $html .= '<input type="number" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $step . $required . '>';
                        break;

                    case 'date':
                        $html .= '<input type="date" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        break;

                    case 'datetime':
                        $html .= '<input type="datetime-local" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        break;

                    default: // text
                        $html .= '<input type="text" id="' . $fieldSlug . '" name="' . $fieldSlug . '" class="form-control"' . $required . '>';
                        break;
                }

                $html .= '</div>';
            }

            $html .= '<div class="form-actions">';
            $html .= '<button type="submit" class="btn btn-primary">' . htmlspecialchars($buttonText) . '</button>';
            $html .= '</div>';
            $html .= '</form>';

            return $html;
        } catch (\Exception $e) {
            return '<!-- Form render error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    private function ensureTablesExist(): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return;

            $sm = $conn->createSchemaManager();

            // Themes table
            if (!$sm->tablesExist(['cms_themes'])) {
                $conn->executeStatement("CREATE TABLE `cms_themes` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(100) NOT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                    `description` TEXT NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_theme_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // Seed default theme if themes dir exists
                $themeManager = new ThemeManager();
                $defaultPath = $themeManager->getThemesBasePath() . '/default';
                if (is_dir($defaultPath)) {
                    $conn->executeStatement(
                        "INSERT INTO `cms_themes` (`name`, `slug`, `status`, `createdAt`, `updatedAt`) VALUES ('Default', 'default', 'live', NOW(), NOW())"
                    );
                }
            }

            // Migrate cms_collections: add columns for newer features
            if ($sm->tablesExist(['cms_collections'])) {
                $columns = $sm->listTableColumns('cms_collections');
                if (!isset($columns['has_slug'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `has_slug` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['slug_source_field'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `slug_source_field` VARCHAR(100) NULL DEFAULT NULL");
                }
                if (!isset($columns['is_submittable'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `is_submittable` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['submit_settings'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `submit_settings` JSON NULL DEFAULT NULL");
                }
                if (!isset($columns['url_prefix'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `url_prefix` VARCHAR(100) NULL DEFAULT NULL");
                }
                if (!isset($columns['items_per_page'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `items_per_page` INT NOT NULL DEFAULT 10");
                }
                if (!isset($columns['permissions'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `permissions` JSON NULL DEFAULT NULL");
                }
                if (!isset($columns['api_rate_limit'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `api_rate_limit` INT NOT NULL DEFAULT 0");
                }
                if (!isset($columns['seo_enabled'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `seo_enabled` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['is_translatable'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `is_translatable` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['workflow_enabled'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `workflow_enabled` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['workflow_stages'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `workflow_stages` JSON NULL DEFAULT NULL");
                }
                if (!isset($columns['workflow_reviewers'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `workflow_reviewers` JSON NULL DEFAULT NULL");
                }
            }

            // Migrate cms_media: add alt_text and thumbnail_path columns
            if ($sm->tablesExist(['cms_media'])) {
                $columns = $sm->listTableColumns('cms_media');
                if (!isset($columns['alt_text'])) {
                    $conn->executeStatement("ALTER TABLE `cms_media` ADD COLUMN `alt_text` VARCHAR(255) NULL DEFAULT NULL");
                }
                if (!isset($columns['thumbnail_path'])) {
                    $conn->executeStatement("ALTER TABLE `cms_media` ADD COLUMN `thumbnail_path` VARCHAR(500) NULL DEFAULT NULL");
                }
            }

            // API Keys table
            if (!$sm->tablesExist(['cms_api_keys'])) {
                $conn->executeStatement("CREATE TABLE `cms_api_keys` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `key` VARCHAR(64) NOT NULL,
                    `permissions` JSON NULL DEFAULT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `expires_at` DATETIME NULL DEFAULT NULL,
                    `last_used_at` DATETIME NULL DEFAULT NULL,
                    `created_by` INT NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_api_key` (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Revisions table
            if (!$sm->tablesExist(['cms_revisions'])) {
                $conn->executeStatement("CREATE TABLE `cms_revisions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `table_name` VARCHAR(255) NOT NULL,
                    `entry_id` VARCHAR(50) NOT NULL,
                    `data` JSON NOT NULL,
                    `changed_fields` JSON NULL DEFAULT NULL,
                    `action` VARCHAR(20) NOT NULL DEFAULT 'update',
                    `user_id` INT NULL DEFAULT NULL,
                    `user_name` VARCHAR(255) NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_rev_table_entry` (`table_name`, `entry_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Role permissions table
            if (!$sm->tablesExist(['cms_role_permissions'])) {
                $conn->executeStatement("CREATE TABLE `cms_role_permissions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `role_slug` VARCHAR(100) NOT NULL,
                    `permissions` JSON NOT NULL,
                    UNIQUE KEY `uniq_role_slug` (`role_slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // OAuth 2.0 Clients
            if (!$sm->tablesExist(['cms_oauth_clients'])) {
                $conn->executeStatement("CREATE TABLE `cms_oauth_clients` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `client_id` VARCHAR(64) NOT NULL,
                    `client_secret` VARCHAR(128) NOT NULL,
                    `redirect_uri` VARCHAR(2048) NOT NULL,
                    `scopes` JSON NULL DEFAULT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_client_id` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // OAuth Authorization Codes
            if (!$sm->tablesExist(['cms_oauth_auth_codes'])) {
                $conn->executeStatement("CREATE TABLE `cms_oauth_auth_codes` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `code` VARCHAR(128) NOT NULL,
                    `client_id` VARCHAR(64) NOT NULL,
                    `user_id` INT NOT NULL,
                    `scopes` JSON NULL DEFAULT NULL,
                    `redirect_uri` VARCHAR(2048) NOT NULL,
                    `code_challenge` VARCHAR(128) NULL DEFAULT NULL,
                    `code_challenge_method` VARCHAR(10) NULL DEFAULT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `used` TINYINT(1) NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_auth_code` (`code`),
                    INDEX `idx_auth_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // OAuth Access + Refresh Tokens
            if (!$sm->tablesExist(['cms_oauth_tokens'])) {
                $conn->executeStatement("CREATE TABLE `cms_oauth_tokens` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `token` VARCHAR(128) NOT NULL,
                    `type` VARCHAR(10) NOT NULL DEFAULT 'access',
                    `user_id` INT NOT NULL,
                    `client_id` VARCHAR(64) NOT NULL,
                    `scopes` JSON NULL DEFAULT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `revoked` TINYINT(1) NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_token` (`token`),
                    INDEX `idx_token_type` (`type`, `revoked`),
                    INDEX `idx_token_client` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Webhook Subscriptions
            if (!$sm->tablesExist(['cms_webhooks'])) {
                $conn->executeStatement("CREATE TABLE `cms_webhooks` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `topic` VARCHAR(100) NOT NULL,
                    `url` VARCHAR(2048) NOT NULL,
                    `client_id` VARCHAR(64) NOT NULL,
                    `secret` VARCHAR(128) NOT NULL,
                    `format` VARCHAR(10) NOT NULL DEFAULT 'json',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `failure_count` INT NOT NULL DEFAULT 0,
                    `last_error` TEXT NULL DEFAULT NULL,
                    `last_success_at` DATETIME NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_webhook_topic` (`topic`, `is_active`),
                    INDEX `idx_webhook_client` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Marketplace Items
            if (!$sm->tablesExist(['cms_marketplace_items'])) {
                $conn->executeStatement("CREATE TABLE `cms_marketplace_items` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `slug` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `type` VARCHAR(20) NOT NULL DEFAULT 'theme',
                    `category` VARCHAR(100) NULL DEFAULT NULL,
                    `description` TEXT NULL DEFAULT NULL,
                    `version` VARCHAR(20) NOT NULL DEFAULT '1.0.0',
                    `seller_id` INT UNSIGNED NOT NULL,
                    `seller_name` VARCHAR(255) NOT NULL,
                    `pricing` VARCHAR(20) NOT NULL DEFAULT 'free',
                    `price_in_cents` INT UNSIGNED NOT NULL DEFAULT 0,
                    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
                    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                    `package_path` VARCHAR(500) NULL DEFAULT NULL,
                    `preview_image` VARCHAR(500) NULL DEFAULT NULL,
                    `screenshots` JSON NULL DEFAULT NULL,
                    `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                    `avg_rating` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
                    `review_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_mp_slug` (`slug`),
                    INDEX `idx_mp_type` (`type`),
                    INDEX `idx_mp_status` (`status`),
                    INDEX `idx_mp_seller` (`seller_id`),
                    INDEX `idx_mp_pricing` (`pricing`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Marketplace Reviews
            if (!$sm->tablesExist(['cms_marketplace_reviews'])) {
                $conn->executeStatement("CREATE TABLE `cms_marketplace_reviews` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `item_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `user_name` VARCHAR(255) NOT NULL,
                    `rating` TINYINT UNSIGNED NOT NULL,
                    `body` TEXT NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_review_item` (`item_id`),
                    UNIQUE KEY `uniq_review_user_item` (`item_id`, `user_id`),
                    CONSTRAINT `fk_review_item` FOREIGN KEY (`item_id`) REFERENCES `cms_marketplace_items`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Marketplace Licenses (for paid items)
            if (!$sm->tablesExist(['cms_marketplace_licenses'])) {
                $conn->executeStatement("CREATE TABLE `cms_marketplace_licenses` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `item_id` INT UNSIGNED NOT NULL,
                    `buyer_id` INT UNSIGNED NOT NULL,
                    `license_key` VARCHAR(128) NOT NULL,
                    `site_url` VARCHAR(2048) NULL DEFAULT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                    `expires_at` DATETIME NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_license_key` (`license_key`),
                    INDEX `idx_license_item` (`item_id`),
                    INDEX `idx_license_buyer` (`buyer_id`),
                    CONSTRAINT `fk_license_item` FOREIGN KEY (`item_id`) REFERENCES `cms_marketplace_items`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Activity Log (system-wide audit trail)
            if (!$sm->tablesExist(['cms_activity_log'])) {
                $conn->executeStatement("CREATE TABLE `cms_activity_log` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `action` VARCHAR(50) NOT NULL,
                    `resource_type` VARCHAR(50) NOT NULL,
                    `resource_id` VARCHAR(100) NULL DEFAULT NULL,
                    `resource_label` VARCHAR(255) NULL DEFAULT NULL,
                    `user_id` INT NULL DEFAULT NULL,
                    `user_name` VARCHAR(255) NULL DEFAULT NULL,
                    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                    `meta` JSON NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_al_action` (`action`),
                    INDEX `idx_al_resource` (`resource_type`, `resource_id`),
                    INDEX `idx_al_user` (`user_id`),
                    INDEX `idx_al_created` (`createdAt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Languages
            if (!$sm->tablesExist(['cms_languages'])) {
                $conn->executeStatement("CREATE TABLE `cms_languages` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `code` VARCHAR(10) NOT NULL,
                    `name` VARCHAR(100) NOT NULL,
                    `native_name` VARCHAR(100) NOT NULL,
                    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_lang_code` (`code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Translations (EAV-style per-field)
            if (!$sm->tablesExist(['cms_translations'])) {
                $conn->executeStatement("CREATE TABLE `cms_translations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `table_name` VARCHAR(255) NOT NULL,
                    `entry_id` VARCHAR(50) NOT NULL,
                    `locale` VARCHAR(10) NOT NULL,
                    `field_slug` VARCHAR(100) NOT NULL,
                    `value` TEXT NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_translation` (`table_name`, `entry_id`, `locale`, `field_slug`),
                    INDEX `idx_trans_entry` (`table_name`, `entry_id`),
                    INDEX `idx_trans_locale` (`locale`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Saved Views (per-collection filter presets)
            if (!$sm->tablesExist(['cms_saved_views'])) {
                $conn->executeStatement("CREATE TABLE `cms_saved_views` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `collection_slug` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(100) NOT NULL,
                    `filters` JSON NOT NULL,
                    `sort_by` VARCHAR(100) NULL DEFAULT NULL,
                    `sort_dir` VARCHAR(4) NOT NULL DEFAULT 'DESC',
                    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_by` INT NULL DEFAULT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_sv_collection` (`collection_slug`),
                    UNIQUE KEY `uniq_sv_slug` (`collection_slug`, `slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Dashboard Layouts (per-user widget configuration)
            if (!$sm->tablesExist(['cms_dashboard_layouts'])) {
                $conn->executeStatement("CREATE TABLE `cms_dashboard_layouts` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `layout` JSON NOT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_user_layout` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Notifications
            if (!$sm->tablesExist(['cms_notifications'])) {
                $conn->executeStatement("CREATE TABLE `cms_notifications` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `body` TEXT NULL DEFAULT NULL,
                    `link` VARCHAR(500) NULL DEFAULT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `meta` JSON NULL DEFAULT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_notif_user_read` (`user_id`, `is_read`),
                    INDEX `idx_notif_created` (`createdAt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Notification Preferences
            if (!$sm->tablesExist(['cms_notification_preferences'])) {
                $conn->executeStatement("CREATE TABLE `cms_notification_preferences` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `preferences` JSON NOT NULL,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_notif_pref_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Email Templates
            if (!$sm->tablesExist(['cms_email_templates'])) {
                $conn->executeStatement("CREATE TABLE `cms_email_templates` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `slug` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `body_twig` TEXT NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_email_tpl_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // Seed default email templates
                $this->seedEmailTemplates($conn);
            }

            // Workflow Transitions
            if (!$sm->tablesExist(['cms_workflow_transitions'])) {
                $conn->executeStatement("CREATE TABLE `cms_workflow_transitions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `table_name` VARCHAR(255) NOT NULL,
                    `entry_id` VARCHAR(50) NOT NULL,
                    `from_stage` VARCHAR(50) NOT NULL,
                    `to_stage` VARCHAR(50) NOT NULL,
                    `user_id` INT NULL DEFAULT NULL,
                    `user_name` VARCHAR(255) NULL DEFAULT NULL,
                    `comment` TEXT NULL DEFAULT NULL,
                    `action` VARCHAR(20) NOT NULL DEFAULT 'advance',
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_wft_entry` (`table_name`, `entry_id`),
                    INDEX `idx_wft_created` (`createdAt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // AI Builder History
            if (!$sm->tablesExist(['cms_ai_history'])) {
                $conn->executeStatement("CREATE TABLE `cms_ai_history` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `prompt` TEXT NOT NULL,
                    `mode` VARCHAR(20) NOT NULL DEFAULT 'page',
                    `provider` VARCHAR(50) NOT NULL,
                    `result_summary` VARCHAR(255) NULL DEFAULT NULL,
                    `tokens_used` INT UNSIGNED NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    INDEX `idx_ai_user` (`user_id`),
                    INDEX `idx_ai_created` (`createdAt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (\Exception $e) {
            // Silently fail - tables will be created on next request or via CLI
        }
    }

    private function seedEmailTemplates($conn): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $templates = [
            [
                'slug' => 'entry-published',
                'name' => 'Entry Published',
                'subject' => '[{{ app_name }}] "{{ entry_title }}" has been published',
                'body_twig' => '<h2>Entry Published</h2><p>The entry <strong>{{ entry_title }}</strong> in the <em>{{ collection_name }}</em> collection has been published.</p><p><a href="{{ entry_url }}">View Entry</a></p>',
            ],
            [
                'slug' => 'form-submitted',
                'name' => 'Form Submission Received',
                'subject' => '[{{ app_name }}] New submission in "{{ collection_name }}"',
                'body_twig' => '<h2>New Submission</h2><p>A new entry has been submitted to the <em>{{ collection_name }}</em> collection.</p>{% if fields is defined %}{% for key, val in fields %}<p><strong>{{ key }}:</strong> {{ val }}</p>{% endfor %}{% endif %}<p><a href="{{ entry_url }}">Review Submission</a></p>',
            ],
            [
                'slug' => 'user-registered',
                'name' => 'User Registered',
                'subject' => '[{{ app_name }}] New user registration: {{ user_name }}',
                'body_twig' => '<h2>New User Registration</h2><p>A new user has registered:</p><ul><li><strong>Name:</strong> {{ user_name }}</li><li><strong>Email:</strong> {{ user_email }}</li></ul><p><a href="{{ admin_url }}">Go to Admin</a></p>',
            ],
            [
                'slug' => 'scheduled-published',
                'name' => 'Scheduled Entry Published',
                'subject' => '[{{ app_name }}] Scheduled entry "{{ entry_title }}" is now live',
                'body_twig' => '<h2>Scheduled Entry Published</h2><p>The scheduled entry <strong>{{ entry_title }}</strong> in <em>{{ collection_name }}</em> has been automatically published.</p><p><a href="{{ entry_url }}">View Entry</a></p>',
            ],
        ];

        foreach ($templates as $tpl) {
            $conn->insert('cms_email_templates', [
                'slug' => $tpl['slug'],
                'name' => $tpl['name'],
                'subject' => $tpl['subject'],
                'body_twig' => $tpl['body_twig'],
                'is_active' => 1,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }
}
