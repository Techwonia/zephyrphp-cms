<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class SystemMonitorController extends Controller
{
    private function requirePermission(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('settings.view')) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission();

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();

        $phpInfo = $this->getPhpInfo();
        $memory = $this->getMemoryUsage();
        $disk = $this->getDiskUsage($basePath);
        $uptime = $this->getUptime($basePath);
        $database = $this->getDatabaseStats();
        $requestCount = $this->trackRequestCount($basePath);
        $opcache = $this->getOpcacheStats();
        $errorCount = $this->getErrorCount($basePath);
        $extensions = get_loaded_extensions();
        sort($extensions);

        return $this->render('cms::system/monitor', [
            'phpInfo' => $phpInfo,
            'memory' => $memory,
            'disk' => $disk,
            'uptime' => $uptime,
            'database' => $database,
            'requestCount' => $requestCount,
            'opcache' => $opcache,
            'errorCount' => $errorCount,
            'extensions' => $extensions,
            'user' => Auth::user(),
        ]);
    }

    public function stats(): string
    {
        $this->requirePermission();

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();

        $memory = $this->getMemoryUsage();
        $disk = $this->getDiskUsage($basePath);
        $requestCount = $this->trackRequestCount($basePath);

        return $this->json([
            'memory' => $memory,
            'disk' => $disk,
            'requestCount' => $requestCount,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    private function getPhpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'extensions_count' => count(get_loaded_extensions()),
        ];
    }

    private function getMemoryUsage(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limitRaw = ini_get('memory_limit');
        $limitBytes = $this->parseBytes($limitRaw);

        $percentage = $limitBytes > 0 ? round(($current / $limitBytes) * 100, 1) : 0;

        return [
            'current' => $this->formatBytes($current),
            'current_bytes' => $current,
            'peak' => $this->formatBytes($peak),
            'peak_bytes' => $peak,
            'limit' => $limitRaw,
            'limit_bytes' => $limitBytes,
            'percentage' => $percentage,
        ];
    }

    private function getDiskUsage(string $basePath): array
    {
        $total = (int) @disk_total_space($basePath);
        $free = (int) @disk_free_space($basePath);
        $used = $total - $free;
        $percentage = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        return [
            'total' => $this->formatBytes($total),
            'total_bytes' => $total,
            'free' => $this->formatBytes($free),
            'free_bytes' => $free,
            'used' => $this->formatBytes($used),
            'used_bytes' => $used,
            'percentage' => $percentage,
        ];
    }

    private function getUptime(string $basePath): array
    {
        $uptimeFile = $basePath . '/storage/cache/uptime.txt';
        $cacheDir = dirname($uptimeFile);

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        if (!file_exists($uptimeFile)) {
            file_put_contents($uptimeFile, (string) time());
        }

        $startTime = (int) file_get_contents($uptimeFile);
        $elapsed = time() - $startTime;

        $days = (int) floor($elapsed / 86400);
        $hours = (int) floor(($elapsed % 86400) / 3600);
        $minutes = (int) floor(($elapsed % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        $parts[] = $minutes . 'm';

        return [
            'start_time' => date('Y-m-d H:i:s', $startTime),
            'elapsed_seconds' => $elapsed,
            'formatted' => implode(' ', $parts),
        ];
    }

    private function getDatabaseStats(): array
    {
        try {
            if (!class_exists(\ZephyrPHP\Database\DB::class)) {
                return ['connected' => false, 'status' => 'Module not installed'];
            }

            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $params = $conn->getParams();

            // Table count
            $sm = $conn->createSchemaManager();
            $tables = $sm->listTableNames();
            $tableCount = count($tables);

            // Database size (MySQL)
            $dbSize = 'Unknown';
            $dbSizeBytes = 0;
            try {
                $dbName = $params['dbname'] ?? '';
                $result = $conn->fetchAssociative(
                    "SELECT SUM(data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = ?",
                    [$dbName]
                );
                if ($result && $result['size']) {
                    $dbSizeBytes = (int) $result['size'];
                    $dbSize = $this->formatBytes($dbSizeBytes);
                }
            } catch (\Throwable $e) {
                // Not MySQL or permission issue
            }

            // Slow queries (MySQL)
            $slowQueries = null;
            try {
                $result = $conn->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Slow_queries'");
                if ($result) {
                    $slowQueries = (int) ($result['Value'] ?? 0);
                }
            } catch (\Throwable $e) {
                // Not available
            }

            return [
                'connected' => true,
                'table_count' => $tableCount,
                'size' => $dbSize,
                'size_bytes' => $dbSizeBytes,
                'slow_queries' => $slowQueries,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function trackRequestCount(string $basePath): int
    {
        $counterFile = $basePath . '/storage/cache/request_count.txt';
        $cacheDir = dirname($counterFile);

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $count = 0;
        if (file_exists($counterFile)) {
            $count = (int) file_get_contents($counterFile);
        }
        $count++;

        file_put_contents($counterFile, (string) $count, LOCK_EX);

        return $count;
    }

    private function getOpcacheStats(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);
        if ($status === false) {
            return null;
        }

        $memoryUsage = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];

        $usedMemory = $memoryUsage['used_memory'] ?? 0;
        $freeMemory = $memoryUsage['free_memory'] ?? 0;
        $totalMemory = $usedMemory + $freeMemory;
        $memoryPercentage = $totalMemory > 0 ? round(($usedMemory / $totalMemory) * 100, 1) : 0;

        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $totalRequests = $hits + $misses;
        $hitRate = $totalRequests > 0 ? round(($hits / $totalRequests) * 100, 1) : 0;

        return [
            'enabled' => true,
            'hit_rate' => $hitRate,
            'memory_usage' => $this->formatBytes((int) $usedMemory),
            'memory_total' => $this->formatBytes((int) $totalMemory),
            'memory_percentage' => $memoryPercentage,
            'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
            'hits' => $hits,
            'misses' => $misses,
        ];
    }

    private function getErrorCount(string $basePath): int
    {
        $logFile = $basePath . '/storage/logs/error-' . date('Y-m-d') . '.log';

        if (!file_exists($logFile)) {
            return 0;
        }

        $count = 0;
        $handle = @fopen($logFile, 'r');
        if ($handle) {
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }

        return $count;
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

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
