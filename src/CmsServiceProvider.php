<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms;

use ZephyrPHP\Container\Container;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\SectionManager;
use ZephyrPHP\Cms\Models\PageType;
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

        // Pass preview state as global Twig vars
        $previewTheme = $themeManager->getPreviewTheme();
        if ($previewTheme) {
            $view->addGlobal('is_theme_preview', true);
            $view->addGlobal('preview_theme_slug', $previewTheme);
        }

        // Load CMS global helper functions (entry, collection)
        require_once __DIR__ . '/helpers.php';

        // Register Twig helper functions
        $this->registerTwigHelpers($view, $themeManager);

        // Load CMS routes
        $routesFile = __DIR__ . '/../routes/cms.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }

        // Register dynamic page routes
        $this->registerDynamicRoutes();

        // Register theme page routes from pages.json
        $this->registerThemePageRoutes($themeManager);

        // Auto-create CMS tables if they don't exist
        $this->ensureTablesExist();
    }

    private function registerTwigHelpers(\ZephyrPHP\View\View $view, ThemeManager $themeManager): void
    {
        // collection() and entry() - delegate to global helpers from cms/src/helpers.php
        $view->addFunction('collection', function (string $slug, array $options = []) {
            return collection($slug, $options);
        });

        $view->addFunction('entry', function (string $ptSlug, string|int $identifier) {
            return entry($ptSlug, $identifier);
        });

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
            echo $view->render('@theme/layouts/' . $layout, $pageData);
        } else {
            echo $view->render('@theme/templates/' . $template, $pageData);
        }
    }

    private function registerDynamicRoutes(): void
    {
        try {
            $pageTypes = PageType::findAll();

            foreach ($pageTypes as $pageType) {
                $prefix = $pageType->getUrlPrefix();
                if (empty($prefix)) continue;

                $prefix = '/' . ltrim(rtrim($prefix, '/'), '/');

                // Skip root prefix — a catch-all /{slug} would break other routes
                if ($prefix === '/' || $prefix === '') continue;

                $ptSlug = $pageType->getSlug();

                if ($pageType->isDynamic()) {
                    // Dynamic: listing + detail
                    \ZephyrPHP\Router\Route::get($prefix, function () use ($ptSlug) {
                        $controller = new \ZephyrPHP\Cms\Controllers\PageFrontendController();
                        return $controller->listing($ptSlug);
                    });
                    \ZephyrPHP\Router\Route::get($prefix . '/{slug}', function (string $slug) use ($ptSlug) {
                        $controller = new \ZephyrPHP\Cms\Controllers\PageFrontendController();
                        return $controller->detail($ptSlug, $slug);
                    });
                } else {
                    // Static: show pages at this prefix
                    \ZephyrPHP\Router\Route::get($prefix, function () use ($ptSlug) {
                        $controller = new \ZephyrPHP\Cms\Controllers\PageFrontendController();
                        return $controller->staticPage($ptSlug);
                    });
                    \ZephyrPHP\Router\Route::get($prefix . '/{slug}', function (string $slug) use ($ptSlug) {
                        $controller = new \ZephyrPHP\Cms\Controllers\PageFrontendController();
                        return $controller->staticPage($ptSlug, $slug);
                    });
                }
            }
        } catch (\Exception $e) {
            // Tables may not exist yet on first load
        }
    }

    private function ensureTablesExist(): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return;

            $sm = $conn->createSchemaManager();

            // Fix: if tables exist with wrong column names (created_at instead of createdAt), drop and recreate
            if ($sm->tablesExist(['cms_page_types'])) {
                $columns = $sm->listTableColumns('cms_page_types');
                if (isset($columns['created_at']) && !isset($columns['createdat'])) {
                    $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                    $conn->executeStatement('DROP TABLE IF EXISTS `cms_page_type_fields`');
                    $conn->executeStatement('DROP TABLE IF EXISTS `cms_page_types`');
                    $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                }
            }

            if (!$sm->tablesExist(['cms_page_types'])) {
                $conn->executeStatement("CREATE TABLE `cms_page_types` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(100) NOT NULL,
                    `template` VARCHAR(255) NOT NULL,
                    `description` TEXT NULL DEFAULT NULL,
                    `has_seo` TINYINT(1) NOT NULL DEFAULT 1,
                    `is_publishable` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_by` INT NULL DEFAULT NULL,
                    `page_mode` VARCHAR(20) NOT NULL DEFAULT 'static',
                    `layout` VARCHAR(100) NOT NULL DEFAULT 'base',
                    `url_prefix` VARCHAR(255) NULL DEFAULT NULL,
                    `items_per_page` INT NOT NULL DEFAULT 10,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY `uniq_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                // Migration: add new columns if they don't exist
                $columns = $sm->listTableColumns('cms_page_types');
                if (!isset($columns['page_mode'])) {
                    $conn->executeStatement("ALTER TABLE `cms_page_types` ADD COLUMN `page_mode` VARCHAR(20) NOT NULL DEFAULT 'static'");
                }
                if (!isset($columns['layout'])) {
                    $conn->executeStatement("ALTER TABLE `cms_page_types` ADD COLUMN `layout` VARCHAR(100) NOT NULL DEFAULT 'base'");
                }
                if (!isset($columns['url_prefix'])) {
                    $conn->executeStatement("ALTER TABLE `cms_page_types` ADD COLUMN `url_prefix` VARCHAR(255) NULL DEFAULT NULL");
                }
                if (!isset($columns['items_per_page'])) {
                    $conn->executeStatement("ALTER TABLE `cms_page_types` ADD COLUMN `items_per_page` INT NOT NULL DEFAULT 10");
                }
            }

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

            if (!$sm->tablesExist(['cms_page_type_fields'])) {
                $conn->executeStatement("CREATE TABLE `cms_page_type_fields` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `page_type_id` INT UNSIGNED NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(100) NOT NULL,
                    `type` VARCHAR(50) NOT NULL DEFAULT 'text',
                    `options` JSON NULL DEFAULT NULL,
                    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_searchable` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_listable` TINYINT(1) NOT NULL DEFAULT 1,
                    `default_value` TEXT NULL DEFAULT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `createdAt` DATETIME NULL DEFAULT NULL,
                    `updatedAt` DATETIME NULL DEFAULT NULL,
                    CONSTRAINT `fk_ptf_page_type` FOREIGN KEY (`page_type_id`) REFERENCES `cms_page_types`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            // Migrate cms_collections: add has_slug and slug_source_field columns
            if ($sm->tablesExist(['cms_collections'])) {
                $columns = $sm->listTableColumns('cms_collections');
                if (!isset($columns['has_slug'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `has_slug` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!isset($columns['slug_source_field'])) {
                    $conn->executeStatement("ALTER TABLE `cms_collections` ADD COLUMN `slug_source_field` VARCHAR(100) NULL DEFAULT NULL");
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
        } catch (\Exception $e) {
            // Silently fail - tables will be created on next request or via CLI
        }
    }
}
