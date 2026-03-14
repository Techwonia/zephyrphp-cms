<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Config\Config;

class SystemHealthController extends Controller
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

        // PHP Info
        $php = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
            'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'opcache_enabled' => function_exists('opcache_get_status') && @opcache_get_status() !== false ? 'Yes' : 'No',
        ];

        // Memory
        $memory = [
            'current' => $this->formatBytes(memory_get_usage(true)),
            'peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'limit' => ini_get('memory_limit'),
        ];

        // Disk space
        $disk = [
            'total' => $this->formatBytes((int) @disk_total_space($basePath)),
            'free' => $this->formatBytes((int) @disk_free_space($basePath)),
            'used_percent' => $this->getDiskUsedPercent($basePath),
        ];

        // Storage sizes
        $storage = [
            'logs' => $this->getDirectorySize($basePath . '/storage/logs'),
            'cache' => $this->getDirectorySize($basePath . '/storage/cache'),
            'media' => $this->getDirectorySize($basePath . '/public/uploads'),
            'views_cache' => $this->getDirectorySize($basePath . '/storage/views'),
        ];

        // Database status
        $database = $this->getDatabaseInfo();

        // Cache status
        $cache = [
            'config_cached' => Config::isCached() ? 'Yes' : 'No',
            'driver' => env('CACHE_DRIVER', 'file'),
        ];

        // Environment
        $environment = [
            'env' => env('ENV', 'dev'),
            'debug' => env('APP_DEBUG', 'true'),
            'timezone' => date_default_timezone_get(),
            'locale' => env('APP_LOCALE', 'en'),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        ];

        // Extensions check
        $requiredExtensions = ['pdo', 'mbstring', 'json', 'openssl', 'fileinfo', 'tokenizer', 'ctype'];
        $extensionStatus = [];
        foreach ($requiredExtensions as $ext) {
            $extensionStatus[$ext] = extension_loaded($ext);
        }

        // Maintenance mode
        $maintenanceFile = $basePath . '/storage/framework/down';
        $maintenance = [
            'active' => file_exists($maintenanceFile),
            'data' => file_exists($maintenanceFile) ? json_decode(file_get_contents($maintenanceFile), true) : null,
        ];

        // Writable directories check
        $writableDirs = [
            'storage' => is_writable($basePath . '/storage'),
            'storage/logs' => is_writable($basePath . '/storage/logs'),
            'storage/cache' => is_dir($basePath . '/storage/cache') && is_writable($basePath . '/storage/cache'),
            'public/uploads' => is_dir($basePath . '/public/uploads') && is_writable($basePath . '/public/uploads'),
        ];

        return $this->render('cms::system/health', [
            'php' => $php,
            'memory' => $memory,
            'disk' => $disk,
            'storage' => $storage,
            'database' => $database,
            'cache' => $cache,
            'environment' => $environment,
            'extensionStatus' => $extensionStatus,
            'maintenance' => $maintenance,
            'writableDirs' => $writableDirs,
            'user' => Auth::user(),
        ]);
    }

    private function getDatabaseInfo(): array
    {
        try {
            if (!class_exists(\ZephyrPHP\Database\DB::class)) {
                return ['status' => 'Module not installed', 'connected' => false];
            }

            $conn = \ZephyrPHP\Database\DB::connection();
            $params = $conn->getParams();

            // Get table count
            $sm = $conn->createSchemaManager();
            $tables = $sm->listTableNames();

            // Get database size (MySQL)
            $dbSize = 'Unknown';
            try {
                $dbName = $params['dbname'] ?? '';
                $result = $conn->fetchAssociative(
                    "SELECT SUM(data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = ?",
                    [$dbName]
                );
                if ($result && $result['size']) {
                    $dbSize = $this->formatBytes((int) $result['size']);
                }
            } catch (\Throwable $e) {
                // Not MySQL or permission issue
            }

            return [
                'connected' => true,
                'driver' => $params['driver'] ?? 'unknown',
                'host' => $params['host'] ?? 'localhost',
                'database' => $params['dbname'] ?? '',
                'table_count' => count($tables),
                'size' => $dbSize,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
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

    private function getDiskUsedPercent(string $path): int
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $total <= 0) {
            return 0;
        }

        return (int) round((($total - $free) / $total) * 100);
    }
}
