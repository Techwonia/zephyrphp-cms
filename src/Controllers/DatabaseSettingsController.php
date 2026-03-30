<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use Doctrine\DBAL\DriverManager;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\EnvFileManager;
use ZephyrPHP\Cms\Services\PermissionService;

class DatabaseSettingsController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $settings = [
            'ENV' => env('ENV', 'dev'),
            'DB_CONNECTION' => env('DB_CONNECTION', 'pdo_mysql'),
            'DB_HOST' => env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => env('DB_PORT', '3306'),
            'DB_DATABASE' => env('DB_DATABASE', ''),
            'DB_USERNAME' => env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => env('DB_PASSWORD', ''),
            'DB_CHARSET' => env('DB_CHARSET', 'utf8mb4'),
        ];

        // Check current connection status
        $status = $this->checkConnectionStatus();

        return $this->render('cms::settings/database', [
            'settings' => $settings,
            'status' => $status,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [
            'ENV' => $this->input('ENV', 'dev'),
            'DB_CONNECTION' => $this->input('DB_CONNECTION', 'pdo_mysql'),
            'DB_HOST' => $this->input('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->input('DB_PORT', '3306'),
            'DB_DATABASE' => $this->input('DB_DATABASE', ''),
            'DB_USERNAME' => $this->input('DB_USERNAME', 'root'),
            'DB_PASSWORD' => $this->input('DB_PASSWORD', ''),
            'DB_CHARSET' => $this->input('DB_CHARSET', 'utf8mb4'),
        ];

        $errors = $this->validateSettings($settings);
        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $envPath = EnvFileManager::getEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            $this->flash('errors', ['env' => '.env file not found or not writable.']);
            $this->back();
            return;
        }

        // Create backup
        copy($envPath, $envPath . '.backup');

        EnvFileManager::updateAndApply($settings);

        $this->flash('success', 'Database settings updated successfully. Backup saved as .env.backup');
        $this->redirect(admin_url('settings/database'));
    }

    public function test(): string
    {
        $this->requirePermission('settings.edit');

        $config = [
            'driver' => $this->input('DB_CONNECTION', 'pdo_mysql'),
            'host' => $this->input('DB_HOST', '127.0.0.1'),
            'port' => (int) ($this->input('DB_PORT', '3306')),
            'dbname' => $this->input('DB_DATABASE', ''),
            'user' => $this->input('DB_USERNAME', 'root'),
            'password' => $this->input('DB_PASSWORD', ''),
            'charset' => $this->input('DB_CHARSET', 'utf8mb4'),
        ];

        try {
            $conn = DriverManager::getConnection($config);
            $conn->executeQuery('SELECT 1');
            $conn->close();

            $this->json(['success' => true, 'message' => 'Connection successful!']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
        }

        return '';
    }

    public function listDatabases(): string
    {
        $this->requirePermission('settings.edit');

        $driver = $this->input('DB_CONNECTION', 'pdo_mysql');

        if (!in_array($driver, ['pdo_mysql', 'mysql', 'mysqli'])) {
            $this->json(['success' => false, 'message' => 'Database listing is only available for MySQL.', 'databases' => []]);
            return '';
        }

        $config = [
            'driver' => $driver,
            'host' => $this->input('DB_HOST', '127.0.0.1'),
            'port' => (int) ($this->input('DB_PORT', '3306')),
            'user' => $this->input('DB_USERNAME', 'root'),
            'password' => $this->input('DB_PASSWORD', ''),
            'charset' => $this->input('DB_CHARSET', 'utf8mb4'),
        ];

        try {
            $conn = DriverManager::getConnection($config);
            $result = $conn->executeQuery('SHOW DATABASES');
            $rows = $result->fetchAllAssociative();
            $databases = array_column($rows, 'Database');

            // Filter out system databases
            $system = ['information_schema', 'performance_schema', 'mysql', 'sys'];
            $databases = array_values(array_filter($databases, fn($db) => !in_array($db, $system)));

            $conn->close();

            $this->json(['success' => true, 'databases' => $databases]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => 'Failed to list databases: ' . $e->getMessage(), 'databases' => []]);
        }

        return '';
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function checkConnectionStatus(): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $conn->executeQuery('SELECT 1');
            return ['connected' => true, 'message' => 'Connected to ' . env('DB_DATABASE', 'unknown')];
        } catch (\Exception $e) {
            return ['connected' => false, 'message' => $e->getMessage()];
        }
    }

    private function validateSettings(array $settings): array
    {
        $errors = [];

        if (empty($settings['DB_HOST'])) {
            $errors['DB_HOST'] = 'Database host is required.';
        }
        if (empty($settings['DB_PORT']) || !is_numeric($settings['DB_PORT'])) {
            $errors['DB_PORT'] = 'A valid port number is required.';
        }
        if (empty($settings['DB_DATABASE'])) {
            $errors['DB_DATABASE'] = 'Database name is required.';
        }
        if (empty($settings['DB_USERNAME'])) {
            $errors['DB_USERNAME'] = 'Database username is required.';
        }

        return $errors;
    }

}
