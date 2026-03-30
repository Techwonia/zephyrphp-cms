<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Manages reading and writing the .env file.
 * Consolidates duplicate code from settings controllers.
 */
class EnvFileManager
{
    /**
     * Find the .env file path.
     */
    public static function getEnvPath(): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $envPath = $basePath . '/.env';

        if (file_exists($envPath)) {
            return $envPath;
        }

        $parentEnv = dirname($basePath) . '/.env';
        return file_exists($parentEnv) ? $parentEnv : null;
    }

    /**
     * Update .env file with new settings.
     */
    public static function update(array $settings): bool
    {
        $envPath = self::getEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            return false;
        }

        $content = file_get_contents($envPath);

        foreach ($settings as $key => $value) {
            $escaped = self::escapeValue($value);

            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $content);
            } else {
                $content = rtrim($content) . "\n{$key}={$escaped}\n";
            }
        }

        file_put_contents($envPath, $content);
        return true;
    }

    /**
     * Update .env and also set values in current process.
     */
    public static function updateAndApply(array $settings): bool
    {
        $result = self::update($settings);

        if ($result) {
            foreach ($settings as $key => $value) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        return $result;
    }

    /**
     * Escape a value for safe .env file storage.
     */
    public static function escapeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
