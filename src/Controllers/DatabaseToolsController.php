<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class DatabaseToolsController extends Controller
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

        $tables = [];
        $dbInfo = [];

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $sm = $conn->createSchemaManager();
            $tableNames = $sm->listTableNames();
            sort($tableNames);

            foreach ($tableNames as $tableName) {
                $columns = $sm->listTableColumns($tableName);
                $indexes = $sm->listTableIndexes($tableName);

                $columnInfo = [];
                foreach ($columns as $col) {
                    $columnInfo[] = [
                        'name' => $col->getName(),
                        'type' => $col->getType()->getName(),
                        'nullable' => !$col->getNotnull(),
                        'default' => $col->getDefault(),
                        'length' => $col->getLength(),
                        'autoincrement' => $col->getAutoincrement(),
                    ];
                }

                $indexInfo = [];
                foreach ($indexes as $idx) {
                    $indexInfo[] = [
                        'name' => $idx->getName(),
                        'columns' => $idx->getColumns(),
                        'unique' => $idx->isUnique(),
                        'primary' => $idx->isPrimary(),
                    ];
                }

                // Get row count
                $rowCount = 0;
                try {
                    $result = $conn->fetchAssociative("SELECT COUNT(*) as cnt FROM `{$tableName}`");
                    $rowCount = (int) ($result['cnt'] ?? 0);
                } catch (\Throwable $e) {
                    // Permission or lock issue
                }

                $tables[] = [
                    'name' => $tableName,
                    'columns' => $columnInfo,
                    'indexes' => $indexInfo,
                    'row_count' => $rowCount,
                    'column_count' => count($columnInfo),
                ];
            }

            $params = $conn->getParams();
            $dbInfo = [
                'driver' => $params['driver'] ?? 'unknown',
                'host' => $params['host'] ?? 'localhost',
                'database' => $params['dbname'] ?? '',
                'table_count' => count($tableNames),
            ];
        } catch (\Throwable $e) {
            $this->flash('errors', ['Database connection error: ' . $e->getMessage()]);
        }

        $selectedTable = $this->input('table', '');

        return $this->render('cms::system/database-tools', [
            'tables' => $tables,
            'dbInfo' => $dbInfo,
            'selectedTable' => $selectedTable,
            'user' => Auth::user(),
        ]);
    }

    public function backup(): void
    {
        $this->requirePermission('settings.edit');

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $params = $conn->getParams();
            $sm = $conn->createSchemaManager();
            $tableNames = $sm->listTableNames();

            $sql = "-- ZephyrPHP Database Backup\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Database: " . ($params['dbname'] ?? '') . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tableNames as $tableName) {
                // CREATE TABLE
                try {
                    $result = $conn->fetchAssociative("SHOW CREATE TABLE `{$tableName}`");
                    $sql .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                    $sql .= ($result['Create Table'] ?? '') . ";\n\n";
                } catch (\Throwable $e) {
                    continue;
                }

                // INSERT rows
                $rows = $conn->fetchAllAssociative("SELECT * FROM `{$tableName}`");
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $columnList = '`' . implode('`, `', $columns) . '`';

                    foreach ($rows as $row) {
                        $values = array_map(function ($v) use ($conn) {
                            if ($v === null) {
                                return 'NULL';
                            }
                            return $conn->quote((string) $v);
                        }, array_values($row));

                        $sql .= "INSERT INTO `{$tableName}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            // Send as download
            $filename = ($params['dbname'] ?? 'backup') . '_' . date('Y-m-d_His') . '.sql';

            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sql));
            echo $sql;
            exit;
        } catch (\Throwable $e) {
            $this->flash('errors', ['Backup failed: ' . $e->getMessage()]);
            $this->redirect('/cms/system/database');
        }
    }

    public function browse(string $table): string
    {
        $this->requirePermission('settings.view');

        $page = max(1, (int) $this->input('page', 1));
        $perPage = 50;
        $rows = [];
        $columns = [];
        $total = 0;

        try {
            $conn = \ZephyrPHP\Database\DB::connection();

            // Validate table name exists
            $sm = $conn->createSchemaManager();
            if (!in_array($table, $sm->listTableNames(), true)) {
                $this->flash('errors', ['Table not found.']);
                $this->redirect('/cms/system/database');
                return '';
            }

            // Get columns
            $tableColumns = $sm->listTableColumns($table);
            $columns = array_map(fn($c) => $c->getName(), $tableColumns);

            // Get total
            $result = $conn->fetchAssociative("SELECT COUNT(*) as cnt FROM `{$table}`");
            $total = (int) ($result['cnt'] ?? 0);

            // Get rows
            $offset = ($page - 1) * $perPage;
            $rows = $conn->fetchAllAssociative("SELECT * FROM `{$table}` LIMIT {$perPage} OFFSET {$offset}");
        } catch (\Throwable $e) {
            $this->flash('errors', ['Error: ' . $e->getMessage()]);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        return $this->render('cms::system/database-browse', [
            'table' => $table,
            'columns' => $columns,
            'rows' => $rows,
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
            'perPage' => $perPage,
            'user' => Auth::user(),
        ]);
    }
}
