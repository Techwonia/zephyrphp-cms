<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms;

use ZephyrPHP\Container\Container;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;
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
        // collection(slug, options) - Query collection/page type entries from templates
        $view->addFunction('collection', function (string $slug, array $options = []) {
            try {
                $schema = new SchemaManager();
                $pageType = PageType::findOneBy(['slug' => $slug]);

                if (!$pageType) {
                    return ['data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1];
                }

                $tableName = $pageType->getTableName();
                if (!$schema->tableExists($tableName)) {
                    return ['data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1];
                }

                // Default to published-only entries
                if (!isset($options['filters'])) {
                    $options['filters'] = [];
                }
                if (!isset($options['filters']['status'])) {
                    $options['filters']['status'] = 'published';
                }

                // Map friendly option names
                if (isset($options['per_page'])) {
                    $options['per_page'] = (int) $options['per_page'];
                } else {
                    $options['per_page'] = $pageType->getItemsPerPage();
                }

                if (!isset($options['sort_by'])) {
                    $options['sort_by'] = 'id';
                }
                if (!isset($options['sort_dir'])) {
                    $options['sort_dir'] = 'DESC';
                }

                // Support page from query string
                if (!isset($options['page'])) {
                    $options['page'] = max(1, (int) ($_GET['page'] ?? 1));
                }

                return $schema->listEntries($tableName, $options);
            } catch (\Exception $e) {
                return ['data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1];
            }
        });

        // entry(slug, identifier) - Fetch a single entry by slug or ID
        $view->addFunction('entry', function (string $ptSlug, string|int $identifier) {
            try {
                $schema = new SchemaManager();
                $pageType = PageType::findOneBy(['slug' => $ptSlug]);

                if (!$pageType) return null;

                $tableName = $pageType->getTableName();
                if (!$schema->tableExists($tableName)) return null;

                $conn = $schema->getConnection();

                // Try by slug first if string, otherwise by ID
                if (is_string($identifier) && !is_numeric($identifier)) {
                    $entry = $conn->createQueryBuilder()
                        ->select('*')
                        ->from($tableName)
                        ->where('slug = :slug')
                        ->setParameter('slug', $identifier)
                        ->executeQuery()
                        ->fetchAssociative();
                } else {
                    $entry = $conn->createQueryBuilder()
                        ->select('*')
                        ->from($tableName)
                        ->where('id = :id')
                        ->setParameter('id', $identifier)
                        ->executeQuery()
                        ->fetchAssociative();
                }

                return $entry ?: null;
            } catch (\Exception $e) {
                return null;
            }
        });

        // theme_config() - Expose theme info to templates
        $view->addFunction('theme_config', function () use ($themeManager) {
            return $themeManager->getThemeConfig();
        });

        // theme_preview(slug) - Generate preview URL with query param
        $view->addFunction('theme_preview_url', function (string $slug) {
            return '/?theme_preview=' . urlencode($slug);
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

                \ZephyrPHP\Router\Route::get($slug, function () use ($template, $title) {
                    $view = \ZephyrPHP\View\View::getInstance();
                    echo $view->render($template, [
                        'page' => [
                            'title' => $title,
                        ],
                    ]);
                });
            }
        } catch (\Exception $e) {
            // pages.json may not exist yet
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
        } catch (\Exception $e) {
            // Silently fail - tables will be created on next request or via CLI
        }
    }
}
