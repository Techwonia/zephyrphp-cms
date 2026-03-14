<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class LogViewerController extends Controller
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

        $logsPath = $this->getLogsPath();
        $files = $this->getLogFiles($logsPath);

        $selectedFile = $this->input('file', '');
        $search = $this->input('search', '');
        $lines = [];
        $totalLines = 0;

        if ($selectedFile && in_array($selectedFile, array_column($files, 'name'), true)) {
            $filePath = $logsPath . '/' . $selectedFile;
            $result = $this->readLogFile($filePath, $search);
            $lines = $result['lines'];
            $totalLines = $result['total'];
        }

        return $this->render('cms::system/logs', [
            'files' => $files,
            'selectedFile' => $selectedFile,
            'search' => $search,
            'lines' => $lines,
            'totalLines' => $totalLines,
            'user' => Auth::user(),
        ]);
    }

    public function clear(): void
    {
        $this->requirePermission('settings.edit');

        $file = $this->input('file', '');
        $logsPath = $this->getLogsPath();
        $files = $this->getLogFiles($logsPath);

        if ($file && in_array($file, array_column($files, 'name'), true)) {
            $filePath = $logsPath . '/' . $file;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $this->flash('success', "Log file '{$file}' deleted.");
        }

        $this->redirect('/cms/system/logs');
    }

    public function clearAll(): void
    {
        $this->requirePermission('settings.edit');

        $logsPath = $this->getLogsPath();
        $files = glob($logsPath . '/*.log');
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }

        $this->flash('success', "Cleared {$count} log file(s).");
        $this->redirect('/cms/system/logs');
    }

    public function download(): void
    {
        $this->requirePermission('settings.view');

        $file = $this->input('file', '');
        $logsPath = $this->getLogsPath();
        $files = $this->getLogFiles($logsPath);

        if (!$file || !in_array($file, array_column($files, 'name'), true)) {
            $this->flash('errors', ['File not found.']);
            $this->redirect('/cms/system/logs');
            return;
        }

        $filePath = $logsPath . '/' . $file;
        if (!file_exists($filePath)) {
            $this->flash('errors', ['File not found.']);
            $this->redirect('/cms/system/logs');
            return;
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    private function getLogsPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/storage/logs';
    }

    private function getLogFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $logFiles = glob($path . '/*.log');

        foreach ($logFiles as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => $this->formatBytes(filesize($file)),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'lines' => $this->countLines($file),
            ];
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));

        return $files;
    }

    private function readLogFile(string $path, string $search = '', int $maxLines = 500): array
    {
        if (!file_exists($path)) {
            return ['lines' => [], 'total' => 0];
        }

        $allLines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total = count($allLines);

        // Reverse to show newest first
        $allLines = array_reverse($allLines);

        if ($search !== '') {
            $allLines = array_filter($allLines, fn($line) => stripos($line, $search) !== false);
        }

        // Limit output
        $allLines = array_slice($allLines, 0, $maxLines);

        // Parse log entries
        $entries = [];
        foreach ($allLines as $line) {
            $entry = $this->parseLogLine($line);
            $entries[] = $entry;
        }

        return ['lines' => $entries, 'total' => $total];
    }

    private function parseLogLine(string $line): array
    {
        // Format: [2024-01-15 10:30:00] ExceptionClass: Message
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(.+)$/', $line, $matches)) {
            $content = $matches[2];
            $level = 'info';

            if (stripos($content, 'error') !== false || stripos($content, 'exception') !== false) {
                $level = 'error';
            } elseif (stripos($content, 'warning') !== false) {
                $level = 'warning';
            } elseif (stripos($content, 'debug') !== false) {
                $level = 'debug';
            }

            return [
                'timestamp' => $matches[1],
                'content' => $content,
                'level' => $level,
                'raw' => $line,
            ];
        }

        // Stack trace or continuation lines
        return [
            'timestamp' => '',
            'content' => $line,
            'level' => 'trace',
            'raw' => $line,
        ];
    }

    private function countLines(string $path): int
    {
        $count = 0;
        $handle = fopen($path, 'r');
        if ($handle) {
            while (!feof($handle)) {
                if (fgets($handle) !== false) {
                    $count++;
                }
            }
            fclose($handle);
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
