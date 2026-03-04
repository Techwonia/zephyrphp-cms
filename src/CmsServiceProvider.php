<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms;

use ZephyrPHP\Container\Container;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Database\EntityManager;

class CmsServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(SchemaManager::class, function () {
            return new SchemaManager();
        });

        $container->alias('cms.schema', SchemaManager::class);

        // Register CMS models path with Doctrine
        $em = EntityManager::getInstance();
        $em->addPath(__DIR__ . '/Models');
    }

    public function boot(): void
    {
        // Register Twig namespace for CMS templates
        $view = \ZephyrPHP\View\View::getInstance();
        $view->addNamespace('cms', __DIR__ . '/../views');

        // Load CMS routes
        $routesFile = __DIR__ . '/../routes/cms.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }
    }
}
