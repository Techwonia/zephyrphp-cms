<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\EnvFileManager;
use ZephyrPHP\Cms\Services\PermissionService;

class CacheSettingsController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $settings = [
            'CACHE_DRIVER' => env('CACHE_DRIVER', 'file'),
            'CACHE_PREFIX' => env('CACHE_PREFIX', 'zephyr_'),
            'REDIS_HOST' => env('REDIS_HOST', '127.0.0.1'),
            'REDIS_PORT' => env('REDIS_PORT', '6379'),
            'REDIS_PASSWORD' => env('REDIS_PASSWORD', '') ? '••••••••' : '',
            'REDIS_CACHE_DB' => env('REDIS_CACHE_DB', '1'),
        ];

        // Check driver availability
        $drivers = [
            'file' => ['available' => true, 'label' => 'File System'],
            'redis' => ['available' => extension_loaded('redis') || class_exists('Predis\\Client'), 'label' => 'Redis'],
            'apcu' => ['available' => extension_loaded('apcu') && apcu_enabled(), 'label' => 'APCu'],
            'array' => ['available' => true, 'label' => 'Array (Testing)'],
        ];

        // Cache stats
        $stats = $this->getCacheStats();

        // Redis connection test
        $redisStatus = null;
        if ($settings['CACHE_DRIVER'] === 'redis') {
            $redisStatus = $this->testRedisConnection();
        }

        return $this->render('cms::settings/cache', [
            'settings' => $settings,
            'drivers' => $drivers,
            'stats' => $stats,
            'redisStatus' => $redisStatus,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [
            'CACHE_DRIVER' => trim($this->input('CACHE_DRIVER', 'file')),
            'CACHE_PREFIX' => preg_replace('/[^a-z0-9_]/', '', trim($this->input('CACHE_PREFIX', 'zephyr_'))),
            'REDIS_HOST' => trim($this->input('REDIS_HOST', '127.0.0.1')),
            'REDIS_PORT' => (string) max(1, min(65535, (int) $this->input('REDIS_PORT', '6379'))),
            'REDIS_CACHE_DB' => (string) max(0, min(15, (int) $this->input('REDIS_CACHE_DB', '1'))),
        ];

        // Validate driver
        $allowedDrivers = ['file', 'redis', 'apcu', 'array'];
        if (!in_array($settings['CACHE_DRIVER'], $allowedDrivers, true)) {
            $settings['CACHE_DRIVER'] = 'file';
        }

        // Only update password if changed
        $redisPassword = $this->input('REDIS_PASSWORD', '');
        if ($redisPassword !== '' && $redisPassword !== '••••••••') {
            $settings['REDIS_PASSWORD'] = $redisPassword;
        }

        if (!EnvFileManager::updateAndApply($settings)) {
            $this->flash('errors', ['.env file not found or not writable.']);
            $this->redirect(admin_url('settings/cache'));
            return;
        }

        $this->flash('success', 'Cache settings updated successfully.');
        $this->redirect(admin_url('settings/cache'));
    }

    public function testRedis(): void
    {
        $this->requirePermission('settings.view');

        $result = $this->testRedisConnection();

        if ($result['connected']) {
            $this->flash('success', 'Redis connection successful. Server: ' . ($result['info'] ?? 'OK'));
        } else {
            $this->flash('errors', ['Redis connection failed: ' . ($result['error'] ?? 'Unknown error')]);
        }

        $this->redirect(admin_url('settings/cache'));
    }

    private function getCacheStats(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $cacheDir = $basePath . '/storage/cache';

        $stats = [
            'driver' => env('CACHE_DRIVER', 'file'),
            'file_count' => 0,
            'total_size' => '0 B',
        ];

        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            $stats['file_count'] = count($files);
            $totalSize = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                }
            }
            $stats['total_size'] = format_bytes($totalSize);
        }

        return $stats;
    }

    private function testRedisConnection(): array
    {
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD', '');

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, 3);
            if (!$socket) {
                return ['connected' => false, 'error' => "{$errstr} ({$errno})"];
            }

            // Send PING
            if ($password !== '') {
                fwrite($socket, "AUTH {$password}\r\n");
                $authReply = fgets($socket, 512);
                if (strpos($authReply, '+OK') === false && strpos($authReply, '-') === 0) {
                    fclose($socket);
                    return ['connected' => false, 'error' => 'Authentication failed'];
                }
            }

            fwrite($socket, "PING\r\n");
            $reply = trim(fgets($socket, 512));

            fwrite($socket, "INFO server\r\n");
            $infoHeader = fgets($socket, 512);
            $info = '';
            if (strpos($infoHeader, '$') === 0) {
                $len = (int) substr($infoHeader, 1);
                $info = fread($socket, min($len, 2048));
            }

            fclose($socket);

            $version = '';
            if (preg_match('/redis_version:([^\r\n]+)/', $info, $m)) {
                $version = $m[1];
            }

            return [
                'connected' => $reply === '+PONG',
                'info' => $version ? "Redis {$version}" : 'Connected',
            ];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

}
