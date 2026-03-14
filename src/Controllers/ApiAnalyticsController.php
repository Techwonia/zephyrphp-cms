<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class ApiAnalyticsController extends Controller
{
    private function requirePermission(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('api-keys.manage')) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission();

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $this->ensureTable($conn);

            $now = new \DateTime();

            // Total requests: last 24h, 7d, 30d
            $total24h = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ?",
                [(clone $now)->modify('-24 hours')->format('Y-m-d H:i:s')]
            );
            $total7d = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ?",
                [(clone $now)->modify('-7 days')->format('Y-m-d H:i:s')]
            );
            $total30d = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ?",
                [(clone $now)->modify('-30 days')->format('Y-m-d H:i:s')]
            );

            // Requests per API key (top 10, last 7 days)
            $since7d = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s');
            $topKeys = $conn->fetchAllAssociative(
                "SELECT api_key_name, api_key_id,
                        COUNT(*) AS request_count,
                        SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS error_count,
                        ROUND(AVG(response_time_ms)) AS avg_response_time
                 FROM cms_api_logs
                 WHERE created_at >= ?
                 GROUP BY api_key_id, api_key_name
                 ORDER BY request_count DESC
                 LIMIT 10",
                [$since7d]
            );

            // Error rate (4xx and 5xx counts, last 7 days)
            $errors4xx = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ? AND status_code >= 400 AND status_code < 500",
                [$since7d]
            );
            $errors5xx = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ? AND status_code >= 500",
                [$since7d]
            );
            $errorRate = $total7d > 0 ? round(($errors4xx + $errors5xx) / $total7d * 100, 1) : 0;

            // Rate-limited count (last 7 days)
            $rateLimited = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_api_logs WHERE created_at >= ? AND rate_limited = 1",
                [$since7d]
            );

            // Top endpoints (last 7 days)
            $topEndpoints = $conn->fetchAllAssociative(
                "SELECT endpoint, method,
                        COUNT(*) AS request_count,
                        ROUND(AVG(response_time_ms)) AS avg_response_time
                 FROM cms_api_logs
                 WHERE created_at >= ?
                 GROUP BY endpoint, method
                 ORDER BY request_count DESC
                 LIMIT 15",
                [$since7d]
            );

            // Average response time (last 7 days)
            $avgResponseTime = (int) $conn->fetchOne(
                "SELECT ROUND(AVG(response_time_ms)) FROM cms_api_logs WHERE created_at >= ?",
                [$since7d]
            );

            // Requests per day (last 30 days) for chart
            $since30d = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s');
            $requestsPerDay = $conn->fetchAllAssociative(
                "SELECT DATE(created_at) AS day, COUNT(*) AS count
                 FROM cms_api_logs
                 WHERE created_at >= ?
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC",
                [$since30d]
            );

            // Recent requests (last 50)
            $recentRequests = $conn->fetchAllAssociative(
                "SELECT created_at, method, endpoint, status_code, api_key_name,
                        response_time_ms, ip_address
                 FROM cms_api_logs
                 ORDER BY created_at DESC
                 LIMIT 50"
            );

        } catch (\Throwable $e) {
            return $this->render('cms::system/api-analytics', [
                'error' => $e->getMessage(),
                'user' => Auth::user(),
            ]);
        }

        return $this->render('cms::system/api-analytics', [
            'total24h' => $total24h,
            'total7d' => $total7d,
            'total30d' => $total30d,
            'topKeys' => $topKeys,
            'errors4xx' => $errors4xx,
            'errors5xx' => $errors5xx,
            'errorRate' => $errorRate,
            'rateLimited' => $rateLimited,
            'topEndpoints' => $topEndpoints,
            'avgResponseTime' => $avgResponseTime,
            'requestsPerDay' => $requestsPerDay,
            'recentRequests' => $recentRequests,
            'user' => Auth::user(),
        ]);
    }

    private function ensureTable(\Doctrine\DBAL\Connection $conn): void
    {
        $sm = $conn->createSchemaManager();
        if ($sm->tablesExist(['cms_api_logs'])) {
            return;
        }

        $conn->executeStatement("
            CREATE TABLE cms_api_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_key_id INT NULL,
                api_key_name VARCHAR(100) NULL,
                method VARCHAR(10) NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                status_code INT NOT NULL,
                response_time_ms INT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                rate_limited TINYINT(1) DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_api_key (api_key_id),
                INDEX idx_created_at (created_at),
                INDEX idx_status (status_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
