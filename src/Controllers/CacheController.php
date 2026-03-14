<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Config\Config;

class CacheController extends Controller
{
    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();

        $caches = [
            [
                'name' => 'Application Cache',
                'key' => 'app',
                'path' => $basePath . '/storage/cache',
                'size' => $this->getDirectorySize($basePath . '/storage/cache'),
                'files' => $this->countFiles($basePath . '/storage/cache'),
                'description' => 'General application cache files',
            ],
            [
                'name' => 'View Cache',
                'key' => 'views',
                'path' => $basePath . '/storage/views',
                'size' => $this->getDirectorySize($basePath . '/storage/views'),
                'files' => $this->countFiles($basePath . '/storage/views'),
                'description' => 'Compiled Twig template cache',
            ],
            [
                'name' => 'Config Cache',
                'key' => 'config',
                'path' => $basePath . '/storage/cache/config.php',
                'size' => file_exists($basePath . '/storage/cache/config.php') ? $this->formatBytes(filesize($basePath . '/storage/cache/config.php')) : '0 B',
                'files' => file_exists($basePath . '/storage/cache/config.php') ? 1 : 0,
                'description' => 'Cached configuration (single file)',
                'cached' => Config::isCached(),
            ],
        ];

        return $this->render('cms::system/cache', [
            'caches' => $caches,
            'user' => Auth::user(),
        ]);
    }

    public function clear(): void
    {
        $this->requirePermission('settings.edit');

        $type = $this->input('type', 'all');
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $cleared = 0;

        if ($type === 'all' || $type === 'app') {
            $cleared += $this->clearDirectory($basePath . '/storage/cache');
        }

        if ($type === 'all' || $type === 'views') {
            $cleared += $this->clearDirectory($basePath . '/storage/views');
        }

        if ($type === 'all' || $type === 'config') {
            Config::clearCache();
            $cleared++;
        }

        $this->flash('success', "Cache cleared. Removed {$cleared} file(s).");
        $this->redirect('/cms/system/cache');
    }

    public function cacheConfig(): void
    {
        $this->requirePermission('settings.edit');

        Config::clearCache();
        Config::reset();
        Config::load((defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/config');

        if (Config::cache()) {
            $this->flash('success', 'Configuration cached successfully.');
        } else {
            $this->flash('errors', ['Failed to cache configuration.']);
        }

        $this->redirect('/cms/system/cache');
    }

    private function clearDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $files = glob($dir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            } elseif (is_dir($file)) {
                $count += $this->clearDirectory($file);
                @rmdir($file);
            }
        }

        return $count;
    }

    private function getDirectorySize(string $path): string
    {
        if (!is_dir($path)) {
            return '0 B';
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $this->formatBytes($size);
    }

    private function countFiles(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
