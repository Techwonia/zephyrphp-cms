<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;

class AnalyticsDashboardController extends Controller
{
    use CmsAccessTrait;

    /**
     * Allowed range values (whitelist for input validation).
     */
    private const VALID_RANGES = ['today', '7d', '30d', '90d'];

    /**
     * Render the analytics dashboard page.
     */
    public function index(): string
    {
        $this->requirePermission('analytics.view');

        $range = $this->sanitizeRange($this->query('range', '7d'));

        try {
            $data = $this->aggregateData($range);
        } catch (\Throwable $e) {
            return $this->render('cms::analytics/dashboard', [
                'error' => 'Failed to load analytics data.',
                'user' => Auth::user(),
                'range' => $range,
            ]);
        }

        return $this->render('cms::analytics/dashboard', array_merge($data, [
            'user' => Auth::user(),
            'range' => $range,
        ]));
    }

    /**
     * AJAX endpoint for date range switching (returns JSON).
     */
    public function data(): string
    {
        $this->requirePermission('analytics.view');

        $range = $this->sanitizeRange($this->query('range', '7d'));

        try {
            $data = $this->aggregateData($range);
            return $this->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => 'Failed to load analytics data.'], 500);
        }
    }

    /**
     * Validate and sanitize the range parameter via whitelist.
     */
    private function sanitizeRange(string $range): string
    {
        return in_array($range, self::VALID_RANGES, true) ? $range : '7d';
    }

    /**
     * Get the number of days for a given range key.
     */
    private function rangeToDays(string $range): int
    {
        return match ($range) {
            'today' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };
    }

    /**
     * Aggregate analytics data from JSON log files.
     */
    private function aggregateData(string $range): array
    {
        $days = $this->rangeToDays($range);
        $storageDir = $this->getStorageDir();
        $entries = $this->loadEntries($storageDir, $days);

        // Also load today-specific data and wider ranges for the stats cards
        $entriesToday = $this->loadEntries($storageDir, 1);
        $entries7d = ($days >= 7) ? $entries : $this->loadEntries($storageDir, 7);
        $entries30d = ($days >= 30) ? $entries : $this->loadEntries($storageDir, 30);

        // Total page views
        $totalToday = count($entriesToday);
        $total7d = count($entries7d);
        $total30d = count($entries30d);
        $totalRange = count($entries);

        // Unique visitors (by IP hash)
        $uniqueToday = count(array_unique(array_column($entriesToday, 'ip_hash')));
        $unique7d = count(array_unique(array_column($entries7d, 'ip_hash')));
        $unique30d = count(array_unique(array_column($entries30d, 'ip_hash')));
        $uniqueRange = count(array_unique(array_column($entries, 'ip_hash')));

        // Average pages per session (proxy: total views / unique visitors)
        $avgPagesPerSession = $uniqueRange > 0 ? round($totalRange / $uniqueRange, 1) : 0;

        // Bounce rate proxy: visitors who viewed only 1 page
        $visitorPageCounts = [];
        foreach ($entries as $e) {
            $hash = $e['ip_hash'] ?? '';
            if ($hash === '') continue;
            $visitorPageCounts[$hash] = ($visitorPageCounts[$hash] ?? 0) + 1;
        }
        $singlePageVisitors = 0;
        foreach ($visitorPageCounts as $count) {
            if ($count === 1) {
                $singlePageVisitors++;
            }
        }
        $bounceRate = $uniqueRange > 0 ? round(($singlePageVisitors / $uniqueRange) * 100, 1) : 0;

        // Top pages by views
        $pageCounts = [];
        foreach ($entries as $e) {
            $path = $e['path'] ?? '/';
            $pageCounts[$path] = ($pageCounts[$path] ?? 0) + 1;
        }
        arsort($pageCounts);
        $topPages = [];
        $i = 0;
        foreach ($pageCounts as $path => $count) {
            if ($i >= 20) break;
            $topPages[] = ['path' => $path, 'views' => $count];
            $i++;
        }

        // Traffic per day (for chart)
        $dailyCounts = [];
        $dailyUniques = [];
        foreach ($entries as $e) {
            $day = date('Y-m-d', $e['ts'] ?? 0);
            $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
            if (!isset($dailyUniques[$day])) {
                $dailyUniques[$day] = [];
            }
            $hash = $e['ip_hash'] ?? '';
            $dailyUniques[$day][$hash] = true;
        }

        // Fill in missing days with zeroes
        $trafficChart = [];
        for ($d = $days - 1; $d >= 0; $d--) {
            $date = date('Y-m-d', strtotime("-{$d} days"));
            $trafficChart[] = [
                'date' => $date,
                'views' => $dailyCounts[$date] ?? 0,
                'visitors' => isset($dailyUniques[$date]) ? count($dailyUniques[$date]) : 0,
            ];
        }

        // Popular content sections (group by first path segment)
        $sectionCounts = [];
        foreach ($entries as $e) {
            $path = $e['path'] ?? '/';
            $segments = explode('/', trim($path, '/'));
            $section = ($segments[0] ?? '') ?: 'home';
            $sectionCounts[$section] = ($sectionCounts[$section] ?? 0) + 1;
        }
        arsort($sectionCounts);
        $topSections = [];
        $i = 0;
        foreach ($sectionCounts as $section => $count) {
            if ($i >= 10) break;
            $topSections[] = ['section' => $section, 'views' => $count];
            $i++;
        }

        // Browser breakdown
        $browsers = [];
        foreach ($entries as $e) {
            $browser = $this->detectBrowser($e['ua'] ?? '');
            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
        }
        arsort($browsers);
        $browserBreakdown = [];
        $i = 0;
        foreach ($browsers as $name => $count) {
            if ($i >= 8) break;
            $browserBreakdown[] = ['name' => $name, 'count' => $count];
            $i++;
        }

        // Device breakdown
        $devices = [];
        foreach ($entries as $e) {
            $device = $this->detectDevice($e['ua'] ?? '');
            $devices[$device] = ($devices[$device] ?? 0) + 1;
        }
        arsort($devices);
        $deviceBreakdown = [];
        foreach ($devices as $name => $count) {
            $deviceBreakdown[] = ['name' => $name, 'count' => $count];
        }

        // Referrer sources
        $referrers = [];
        foreach ($entries as $e) {
            $ref = $e['ref'] ?? '';
            if ($ref === '') {
                $source = 'Direct / None';
            } else {
                $parsed = parse_url($ref);
                $source = $parsed['host'] ?? 'Unknown';
            }
            $referrers[$source] = ($referrers[$source] ?? 0) + 1;
        }
        arsort($referrers);
        $topReferrers = [];
        $i = 0;
        foreach ($referrers as $source => $count) {
            if ($i >= 10) break;
            $topReferrers[] = ['source' => $source, 'count' => $count];
            $i++;
        }

        // Live visitors (last 5 minutes)
        $fiveMinAgo = time() - 300;
        $liveIps = [];
        // Only check today's entries for live count
        foreach ($entriesToday as $e) {
            if (($e['ts'] ?? 0) >= $fiveMinAgo) {
                $liveIps[$e['ip_hash'] ?? ''] = true;
            }
        }
        $liveVisitors = count($liveIps);

        return [
            'totalToday' => $totalToday,
            'total7d' => $total7d,
            'total30d' => $total30d,
            'totalRange' => $totalRange,
            'uniqueToday' => $uniqueToday,
            'unique7d' => $unique7d,
            'unique30d' => $unique30d,
            'uniqueRange' => $uniqueRange,
            'avgPagesPerSession' => $avgPagesPerSession,
            'bounceRate' => $bounceRate,
            'topPages' => $topPages,
            'trafficChart' => $trafficChart,
            'topSections' => $topSections,
            'browserBreakdown' => $browserBreakdown,
            'deviceBreakdown' => $deviceBreakdown,
            'topReferrers' => $topReferrers,
            'liveVisitors' => $liveVisitors,
        ];
    }

    /**
     * Load analytics entries from daily JSON log files.
     */
    private function loadEntries(string $storageDir, int $days): array
    {
        $entries = [];

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("-{$d} days"));
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $date . '.json';

            if (!file_exists($filePath) || !is_readable($filePath)) {
                continue;
            }

            $fp = @fopen($filePath, 'r');
            if ($fp === false) {
                continue;
            }

            if (flock($fp, LOCK_SH)) {
                while (($line = fgets($fp)) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;

                    $entry = json_decode($line, true);
                    if (!is_array($entry)) continue;

                    // Validate required fields exist
                    if (!isset($entry['ts'], $entry['path'], $entry['ip_hash'])) continue;

                    // Validate timestamp is numeric and within sane range
                    if (!is_int($entry['ts']) || $entry['ts'] < 0 || $entry['ts'] > 4102444800) continue;

                    $entries[] = $entry;
                }
                flock($fp, LOCK_UN);
            }

            fclose($fp);
        }

        return $entries;
    }

    /**
     * Get the analytics storage directory path.
     */
    private function getStorageDir(): string
    {
        $basePath = $_ENV['BASE_PATH_ABSOLUTE'] ?? $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
        return rtrim((string) $basePath, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'analytics';
    }

    /**
     * Detect browser name from User-Agent string.
     */
    private function detectBrowser(string $ua): string
    {
        $ua = strtolower($ua);

        // Order matters: check specific browsers before generic engines
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edge/')) return 'Edge';
        if (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) return 'Opera';
        if (str_contains($ua, 'brave')) return 'Brave';
        if (str_contains($ua, 'vivaldi')) return 'Vivaldi';
        if (str_contains($ua, 'chrome') || str_contains($ua, 'crios')) return 'Chrome';
        if (str_contains($ua, 'firefox') || str_contains($ua, 'fxios')) return 'Firefox';
        if (str_contains($ua, 'safari') && !str_contains($ua, 'chrome')) return 'Safari';
        if (str_contains($ua, 'msie') || str_contains($ua, 'trident')) return 'IE';
        if (str_contains($ua, 'samsung')) return 'Samsung Browser';

        return 'Other';
    }

    /**
     * Detect device type from User-Agent string.
     */
    private function detectDevice(string $ua): string
    {
        $ua = strtolower($ua);

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') && !str_contains($ua, 'tablet')) {
            return 'Mobile';
        }
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'Tablet';
        }

        return 'Desktop';
    }
}
