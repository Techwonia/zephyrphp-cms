<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;

class BackupController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $backups = $this->getBackups();

        return $this->render('cms::system/backups', [
            'backups' => $backups,
            'user' => Auth::user(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission('settings.edit');

        $type = $this->input('type', 'full');
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $backupDir = $basePath . '/storage/backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_His');

        try {
            if ($type === 'database' || $type === 'full') {
                $this->backupDatabase($backupDir, $timestamp);
            }

            if ($type === 'files' || $type === 'full') {
                $this->backupFiles($backupDir, $timestamp, $basePath);
            }

            $this->flash('success', ucfirst($type) . ' backup created successfully.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Backup failed: ' . $e->getMessage()]);
        }

        $this->redirect(admin_url('system/backups'));
    }

    public function downloadBackup(string $filename): void
    {
        $this->requirePermission('settings.view');

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $filePath = $basePath . '/storage/backups/' . basename($filename);

        if (!file_exists($filePath)) {
            $this->flash('errors', ['Backup file not found.']);
            $this->redirect(admin_url('system/backups'));
            return;
        }

        $mimeType = str_ends_with($filePath, '.sql') ? 'application/sql' : 'application/zip';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function destroy(string $filename): void
    {
        $this->requirePermission('settings.edit');

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $filePath = $basePath . '/storage/backups/' . basename($filename);

        if (file_exists($filePath)) {
            unlink($filePath);
            $this->flash('success', 'Backup deleted.');
        } else {
            $this->flash('errors', ['Backup file not found.']);
        }

        $this->redirect(admin_url('system/backups'));
    }

    private function getBackups(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $backupDir = $basePath . '/storage/backups';

        if (!is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir . '/*');
        $backups = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = basename($file);
            $type = 'unknown';
            if (str_contains($name, 'database')) {
                $type = 'database';
            } elseif (str_contains($name, 'files')) {
                $type = 'files';
            }

            $backups[] = [
                'name' => $name,
                'type' => $type,
                'size' => $this->formatBytes(filesize($file)),
                'created' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        // Newest first
        usort($backups, fn($a, $b) => strcmp($b['created'], $a['created']));

        return $backups;
    }

    private function backupDatabase(string $backupDir, string $timestamp): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $params = $conn->getParams();
        $sm = $conn->createSchemaManager();
        $tableNames = $sm->listTableNames();

        $filename = "database_{$timestamp}.sql";
        $filePath = $backupDir . '/' . $filename;
        $fh = fopen($filePath, 'w');
        if ($fh === false) {
            throw new \RuntimeException('Could not create backup file.');
        }

        try {
            fwrite($fh, "-- ZephyrPHP Database Backup\n");
            fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "-- Database: " . ($params['dbname'] ?? '') . "\n\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $chunkSize = 500;

            foreach ($tableNames as $tableName) {
                try {
                    $result = $conn->fetchAssociative("SHOW CREATE TABLE `{$tableName}`");
                    fwrite($fh, "DROP TABLE IF EXISTS `{$tableName}`;\n");
                    fwrite($fh, ($result['Create Table'] ?? '') . ";\n\n");
                } catch (\Throwable $e) {
                    continue;
                }

                // Stream rows in chunks to avoid loading entire table into memory
                $offset = 0;
                $columnList = null;
                while (true) {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT * FROM `{$tableName}` LIMIT {$chunkSize} OFFSET {$offset}"
                    );
                    if (empty($rows)) {
                        break;
                    }

                    if ($columnList === null) {
                        $columns = array_keys($rows[0]);
                        $columnList = '`' . implode('`, `', $columns) . '`';
                    }

                    foreach ($rows as $row) {
                        $values = array_map(function ($v) use ($conn) {
                            return $v === null ? 'NULL' : $conn->quote((string) $v);
                        }, array_values($row));
                        fwrite($fh, "INSERT INTO `{$tableName}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n");
                    }

                    if (count($rows) < $chunkSize) {
                        break;
                    }
                    $offset += $chunkSize;
                }

                fwrite($fh, "\n");
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }

    private function backupFiles(string $backupDir, string $timestamp, string $basePath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required for file backups.');
        }

        $filename = "files_{$timestamp}.zip";
        $zipPath = $backupDir . '/' . $filename;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create backup archive.');
        }

        // Backup config, routes, and app directories
        $dirs = ['config', 'routes', 'app', 'lang'];
        foreach ($dirs as $dir) {
            $fullPath = $basePath . '/' . $dir;
            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $dir);
            }
        }

        // Backup .env with sensitive values redacted
        if (file_exists($basePath . '/.env')) {
            $envContent = file_get_contents($basePath . '/.env');
            $sensitiveKeys = ['DB_PASSWORD', 'APP_KEY', 'APP_SECRET', 'API_KEY', 'API_SECRET',
                'MAIL_PASSWORD', 'AWS_SECRET', 'STRIPE_SECRET', 'GEMINI_API_KEY',
                'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_API_KEY', 'MISTRAL_API_KEY',
                'OPENROUTER_API_KEY', 'OAUTH_CLIENT_SECRET'];
            $redacted = preg_replace_callback(
                '/^(' . implode('|', array_map('preg_quote', $sensitiveKeys)) . ')=(.+)$/m',
                fn($m) => $m[1] . '=REDACTED',
                $envContent
            );
            $zip->addFromString('.env', $redacted);
        }

        $zip->close();
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $prefix . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
            }
        }
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
