<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Middleware;

class AnalyticsMiddleware
{
    /**
     * Known bot user-agent patterns (case-insensitive substrings).
     */
    private const BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'slurp', 'wget', 'curl',
        'fetch', 'python', 'scrapy', 'httpclient', 'java/',
        'nutch', 'linkchecker', 'headlesschrome', 'phantomjs',
        'lighthouse', 'pingdom', 'uptimerobot', 'statuspage',
        'monitoring', 'check_http', 'nagios', 'newrelic',
        'datadog', 'semrush', 'ahrefs', 'mj12bot', 'dotbot',
        'bingpreview', 'facebookexternalhit', 'twitterbot',
        'applebot', 'yandexbot', 'baiduspider', 'duckduckbot',
    ];

    /**
     * Path prefixes to exclude from tracking.
     */
    private const EXCLUDED_PREFIXES = [
        '/cms',
        '/api/',
        '/oauth/',
        '/cms-assets/',
        '/marketplace/api/',
    ];

    /**
     * File extensions to exclude (static assets).
     */
    private const EXCLUDED_EXTENSIONS = [
        '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg',
        '.ico', '.woff', '.woff2', '.ttf', '.eot', '.map',
        '.webp', '.avif', '.mp4', '.webm', '.pdf', '.zip',
    ];

    public function handle(): bool
    {
        $path = $this->getRequestPath();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Skip if no user agent (likely not a real browser)
        if ($userAgent === '') {
            return true;
        }

        // Skip bot traffic
        if ($this->isBot($userAgent)) {
            return true;
        }

        // Skip admin/API/asset paths
        if ($this->isExcludedPath($path)) {
            return true;
        }

        // Skip static asset extensions
        if ($this->isStaticAsset($path)) {
            return true;
        }

        // Log the page view asynchronously (don't block the request)
        $this->logPageView($path, $userAgent);

        return true;
    }

    private function getRequestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    private function isBot(string $userAgent): bool
    {
        $ua = strtolower($userAgent);
        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isExcludedPath(string $path): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isStaticAsset(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::EXCLUDED_EXTENSIONS as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }
        return false;
    }

    private function logPageView(string $path, string $userAgent): void
    {
        $storageDir = $this->getStorageDir();
        if ($storageDir === null) {
            return;
        }

        $date = date('Y-m-d');
        $filePath = $storageDir . DIRECTORY_SEPARATOR . $date . '.json';

        // Hash IP for privacy (SHA-256, with a daily salt so hashes can't be correlated across days)
        $ip = $this->getClientIp();
        $dailySalt = $date . ($this->getAppSecret());
        $ipHash = hash('sha256', $ip . $dailySalt);

        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        // Sanitize referrer: only keep valid URLs, strip query params for privacy
        $referrer = $this->sanitizeReferrer($referrer);

        $entry = [
            'ts' => time(),
            'path' => $this->sanitizePath($path),
            'ip_hash' => $ipHash,
            'ua' => mb_substr($userAgent, 0, 500),
            'ref' => $referrer,
        ];

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        // Append with exclusive file locking for concurrency safety
        $fp = @fopen($filePath, 'a');
        if ($fp === false) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $encoded . "\n");
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private function getStorageDir(): ?string
    {
        $basePath = $_ENV['BASE_PATH_ABSOLUTE'] ?? $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
        $storageDir = rtrim((string) $basePath, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'analytics';

        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0750, true)) {
                return null;
            }
            // Add .htaccess to prevent direct access
            $htaccess = $storageDir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            // Add index.html as extra protection
            $index = $storageDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!file_exists($index)) {
                @file_put_contents($index, '');
            }
        }

        return $storageDir;
    }

    private function getClientIp(): string
    {
        // Only trust REMOTE_ADDR (proxy headers can be spoofed)
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getAppSecret(): string
    {
        // Use APP_KEY or a fallback for the daily salt
        return $_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? 'zephyr-analytics-salt';
    }

    private function sanitizePath(string $path): string
    {
        // Keep only the path component, limit length
        $path = preg_replace('/[^\x20-\x7E]/', '', $path) ?? $path;
        return mb_substr($path, 0, 500);
    }

    private function sanitizeReferrer(string $referrer): string
    {
        if ($referrer === '') {
            return '';
        }

        // Validate it's a proper URL
        if (!filter_var($referrer, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parsed = parse_url($referrer);
        if ($parsed === false || !isset($parsed['host'])) {
            return '';
        }

        // Return only scheme + host + path (strip query/fragment for privacy)
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $path = $parsed['path'] ?? '/';

        return $scheme . '://' . $host . $path;
    }
}
