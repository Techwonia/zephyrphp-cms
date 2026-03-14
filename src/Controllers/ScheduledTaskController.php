<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class ScheduledTaskController extends Controller
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

        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $tasks = $conn->fetchAllAssociative(
            'SELECT * FROM cms_scheduled_tasks ORDER BY sort_order ASC, name ASC'
        );

        // Check run history
        foreach ($tasks as &$task) {
            $task['is_due'] = $this->isDue($task['schedule'], $task['last_run_at']);
            $task['next_run'] = $this->getNextRun($task['schedule'], $task['last_run_at']);
        }
        unset($task);

        return $this->render('cms::system/scheduled-tasks', [
            'tasks' => $tasks,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('settings.edit');

        $name = trim($this->input('name', ''));
        $command = trim($this->input('command', ''));
        $schedule = trim($this->input('schedule', ''));
        $description = trim($this->input('description', ''));

        if ($name === '' || $command === '' || $schedule === '') {
            $this->flash('errors', ['Name, command, and schedule are required.']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        // Validate cron expression (basic 5-field)
        if (!$this->isValidCron($schedule)) {
            $this->flash('errors', ['Invalid cron expression. Use standard 5-field format (e.g. */5 * * * *).']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        // Sanitize command — only allow artisan/craftsman commands and safe shell commands
        if (!$this->isAllowedCommand($command)) {
            $this->flash('errors', ['Command not allowed. Only php craftsman commands and whitelisted commands are permitted.']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        $this->ensureTable();

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->insert('cms_scheduled_tasks', [
            'name' => $name,
            'command' => $command,
            'schedule' => $schedule,
            'description' => $description,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', "Task '{$name}' created.");
        $this->redirect('/cms/system/scheduled-tasks');
    }

    public function toggle(string $id): void
    {
        $this->requirePermission('settings.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $task = $conn->fetchAssociative('SELECT * FROM cms_scheduled_tasks WHERE id = ?', [(int) $id]);

        if (!$task) {
            $this->flash('errors', ['Task not found.']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        $conn->update('cms_scheduled_tasks', [
            'is_active' => $task['is_active'] ? 0 : 1,
        ], ['id' => (int) $id]);

        $status = $task['is_active'] ? 'disabled' : 'enabled';
        $this->flash('success', "Task '{$task['name']}' {$status}.");
        $this->redirect('/cms/system/scheduled-tasks');
    }

    public function run(string $id): void
    {
        $this->requirePermission('settings.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $task = $conn->fetchAssociative('SELECT * FROM cms_scheduled_tasks WHERE id = ?', [(int) $id]);

        if (!$task) {
            $this->flash('errors', ['Task not found.']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        if (!$this->isAllowedCommand($task['command'])) {
            $this->flash('errors', ['Command not allowed.']);
            $this->redirect('/cms/system/scheduled-tasks');
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $startTime = microtime(true);
        $output = '';
        $exitCode = -1;

        // Execute in project root with timeout
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $fullCommand = 'cd ' . escapeshellarg($basePath) . ' && ' . $task['command'] . ' 2>&1';
        $process = proc_open($fullCommand, $descriptors, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);

            // Read with timeout (30 seconds max)
            stream_set_timeout($pipes[1], 30);
            $output = stream_get_contents($pipes[1], 1024 * 64); // Max 64KB
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        // Update last run
        $conn->update('cms_scheduled_tasks', [
            'last_run_at' => date('Y-m-d H:i:s'),
            'last_run_output' => mb_substr($output, 0, 5000),
            'last_run_status' => $exitCode === 0 ? 'success' : 'failed',
            'last_run_duration_ms' => (int) $duration,
        ], ['id' => (int) $id]);

        if ($exitCode === 0) {
            $this->flash('success', "Task '{$task['name']}' executed successfully ({$duration}ms).");
        } else {
            $this->flash('errors', ["Task '{$task['name']}' failed (exit code: {$exitCode})."]);
        }

        $this->redirect('/cms/system/scheduled-tasks');
    }

    public function destroy(string $id): void
    {
        $this->requirePermission('settings.edit');

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $task = $conn->fetchAssociative('SELECT * FROM cms_scheduled_tasks WHERE id = ?', [(int) $id]);

        if ($task) {
            $conn->delete('cms_scheduled_tasks', ['id' => (int) $id]);
            $this->flash('success', "Task '{$task['name']}' deleted.");
        } else {
            $this->flash('errors', ['Task not found.']);
        }

        $this->redirect('/cms/system/scheduled-tasks');
    }

    private function ensureTable(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $sm = $conn->createSchemaManager();

        if (!$sm->tablesExist(['cms_scheduled_tasks'])) {
            $conn->executeStatement('
                CREATE TABLE cms_scheduled_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    command VARCHAR(500) NOT NULL,
                    schedule VARCHAR(100) NOT NULL,
                    description VARCHAR(500) NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    sort_order INT DEFAULT 0,
                    last_run_at DATETIME NULL,
                    last_run_output TEXT NULL,
                    last_run_status VARCHAR(20) NULL,
                    last_run_duration_ms INT NULL,
                    created_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }
    }

    private function isAllowedCommand(string $command): bool
    {
        $command = trim($command);

        // Allow php craftsman commands
        if (preg_match('/^php\s+craftsman\s+/', $command)) {
            return true;
        }

        // Allow specific safe commands
        $allowed = [
            'php -v',
            'php -m',
            'composer dump-autoload',
        ];

        foreach ($allowed as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        // Block everything else — prevent shell injection
        return false;
    }

    private function isValidCron(string $expression): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return false;
        }

        // Basic validation — each field matches cron-like pattern
        foreach ($parts as $part) {
            if (!preg_match('/^(\*|[0-9,\-\/\*]+)$/', $part)) {
                return false;
            }
        }

        return true;
    }

    private function isDue(string $schedule, ?string $lastRun): bool
    {
        if ($lastRun === null) {
            return true;
        }

        $lastRunTime = strtotime($lastRun);
        if ($lastRunTime === false) {
            return true;
        }

        $nextRun = $this->getNextRunTimestamp($schedule, $lastRunTime);
        return $nextRun <= time();
    }

    private function getNextRun(string $schedule, ?string $lastRun): string
    {
        $baseTime = $lastRun ? strtotime($lastRun) : time();
        if ($baseTime === false) {
            $baseTime = time();
        }

        $nextRun = $this->getNextRunTimestamp($schedule, $baseTime);
        return date('Y-m-d H:i:s', $nextRun);
    }

    private function getNextRunTimestamp(string $schedule, int $from): int
    {
        // Simple cron-to-interval approximation for common patterns
        $parts = preg_split('/\s+/', trim($schedule));
        if (count($parts) !== 5) {
            return $from + 3600;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // Every N minutes: */N * * * *
        if (preg_match('#^\*/(\d+)$#', $minute, $m) && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return $from + ((int) $m[1] * 60);
        }

        // Hourly: 0 * * * * or N * * * *
        if (is_numeric($minute) && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return $from + 3600;
        }

        // Daily: N N * * *
        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return $from + 86400;
        }

        // Weekly: N N * * N
        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*' && is_numeric($dayOfWeek)) {
            return $from + (7 * 86400);
        }

        // Default: hourly
        return $from + 3600;
    }
}
