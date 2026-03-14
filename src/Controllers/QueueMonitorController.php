<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class QueueMonitorController extends Controller
{
    private function requirePermission(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('settings.edit')) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission();
        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

        // Counts by status
        $statusCounts = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM cms_jobs GROUP BY status'
        );
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($statusCounts as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        // Completed in last 24 hours
        $completed24h = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM cms_jobs WHERE status = 'completed' AND completed_at >= ?",
            [date('Y-m-d H:i:s', strtotime('-24 hours'))]
        );

        // Average processing time (seconds) for completed jobs
        $avgProcessingTime = $conn->fetchOne(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) FROM cms_jobs WHERE status = 'completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL"
        );
        $avgProcessingTime = $avgProcessingTime !== null ? round((float) $avgProcessingTime, 1) : null;

        // Failure rate
        $totalFinished = $counts['completed'] + $counts['failed'];
        $failureRate = $totalFinished > 0 ? round(($counts['failed'] / $totalFinished) * 100, 1) : 0.0;

        // Jobs per queue
        $jobsPerQueue = $conn->fetchAllAssociative(
            'SELECT queue, status, COUNT(*) AS cnt FROM cms_jobs GROUP BY queue, status ORDER BY queue'
        );
        $queues = [];
        foreach ($jobsPerQueue as $row) {
            $queues[$row['queue']][$row['status']] = (int) $row['cnt'];
        }

        // Failed jobs
        $failedJobs = $conn->fetchAllAssociative(
            "SELECT * FROM cms_jobs WHERE status = 'failed' ORDER BY created_at DESC"
        );

        // Pending jobs
        $pendingJobs = $conn->fetchAllAssociative(
            "SELECT * FROM cms_jobs WHERE status = 'pending' ORDER BY created_at ASC"
        );

        // Recent completed (last 50)
        $completedJobs = $conn->fetchAllAssociative(
            "SELECT *, TIMESTAMPDIFF(SECOND, started_at, completed_at) AS processing_seconds FROM cms_jobs WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 50"
        );

        return $this->render('cms::system/queue-monitor', [
            'counts' => $counts,
            'completed24h' => $completed24h,
            'avgProcessingTime' => $avgProcessingTime,
            'failureRate' => $failureRate,
            'queues' => $queues,
            'failedJobs' => $failedJobs,
            'pendingJobs' => $pendingJobs,
            'completedJobs' => $completedJobs,
            'user' => Auth::user(),
        ]);
    }

    public function retry(string $id): void
    {
        $this->requirePermission();
        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $job = $conn->fetchAssociative(
            "SELECT * FROM cms_jobs WHERE id = ? AND status = 'failed'",
            [(int) $id]
        );

        if (!$job) {
            $this->flash('errors', ['Failed job not found.']);
            $this->redirect('/cms/system/queue');
            return;
        }

        $conn->update('cms_jobs', [
            'status' => 'pending',
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
            'attempts' => $job['attempts'] + 1,
        ], ['id' => (int) $id]);

        $this->flash('success', "Job #{$id} queued for retry.");
        $this->redirect('/cms/system/queue');
    }

    public function retryAll(): void
    {
        $this->requirePermission();
        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $affected = $conn->executeStatement(
            "UPDATE cms_jobs SET status = 'pending', error = NULL, started_at = NULL, completed_at = NULL, attempts = attempts + 1 WHERE status = 'failed'"
        );

        $this->flash('success', "{$affected} failed job(s) queued for retry.");
        $this->redirect('/cms/system/queue');
    }

    public function delete(string $id): void
    {
        $this->requirePermission();
        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $job = $conn->fetchAssociative('SELECT * FROM cms_jobs WHERE id = ?', [(int) $id]);

        if ($job) {
            $conn->delete('cms_jobs', ['id' => (int) $id]);
            $this->flash('success', "Job #{$id} deleted.");
        } else {
            $this->flash('errors', ['Job not found.']);
        }

        $this->redirect('/cms/system/queue');
    }

    public function purge(): void
    {
        $this->requirePermission();
        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $affected = $conn->executeStatement(
            "DELETE FROM cms_jobs WHERE status = 'completed' AND completed_at < ?",
            [$cutoff]
        );

        $this->flash('success', "{$affected} completed job(s) older than 7 days purged.");
        $this->redirect('/cms/system/queue');
    }

    private function ensureTable(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $sm = $conn->createSchemaManager();

        if (!$sm->tablesExist(['cms_jobs'])) {
            $conn->executeStatement('
                CREATE TABLE cms_jobs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    queue VARCHAR(100) DEFAULT \'default\',
                    job_class VARCHAR(255) NOT NULL,
                    payload TEXT NULL,
                    status ENUM(\'pending\',\'running\',\'completed\',\'failed\') DEFAULT \'pending\',
                    attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    error TEXT NULL,
                    started_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_status (status),
                    INDEX idx_queue (queue)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }
    }
}
