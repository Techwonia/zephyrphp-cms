<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class ErrorPageController extends Controller
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
        $this->requirePermission('settings.edit');

        $config = $this->loadExceptionConfig();
        $errorTemplates = $this->getErrorTemplates();

        $httpCodes = [400, 401, 403, 404, 405, 419, 422, 429, 500, 503];
        $httpTitles = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
        ];

        $dbErrorTypes = [
            'unique' => 'Duplicate Value',
            'not_null' => 'Required Field Missing',
            'foreign_key' => 'Invalid Reference',
            'connection' => 'Database Connection Failed',
            'table_not_found' => 'Table Not Found',
            'syntax_error' => 'SQL Syntax Error',
            'deadlock' => 'Database Deadlock',
            'lock_timeout' => 'Lock Timeout',
        ];

        return $this->render('cms::settings/error-pages', [
            'config' => $config,
            'httpCodes' => $httpCodes,
            'httpTitles' => $httpTitles,
            'dbErrorTypes' => $dbErrorTypes,
            'errorTemplates' => $errorTemplates,
            'user' => Auth::user(),
        ]);
    }

    public function updateHttp(): void
    {
        $this->requirePermission('settings.edit');

        $config = $this->loadExceptionConfig();
        $messages = $this->input('http', []);

        if (is_array($messages)) {
            $config['http'] = [];
            foreach ($messages as $code => $msg) {
                $code = (int) $code;
                if ($code >= 100 && $code < 600) {
                    $config['http'][$code] = htmlspecialchars(trim($msg), ENT_QUOTES, 'UTF-8');
                }
            }
        }

        $this->saveExceptionConfig($config);
        $this->flash('success', 'HTTP error messages updated.');
        $this->redirect('/cms/settings/error-pages');
    }

    public function updateDatabase(): void
    {
        $this->requirePermission('settings.edit');

        $config = $this->loadExceptionConfig();
        $dbMessages = $this->input('database', []);

        if (is_array($dbMessages)) {
            $config['database'] = [];
            foreach ($dbMessages as $type => $msg) {
                if (preg_match('/^[a-z_]+$/', $type)) {
                    $config['database'][$type] = trim($msg);
                }
            }
        }

        $this->saveExceptionConfig($config);
        $this->flash('success', 'Database error messages updated.');
        $this->redirect('/cms/settings/error-pages');
    }

    public function updateFields(): void
    {
        $this->requirePermission('settings.edit');

        $config = $this->loadExceptionConfig();
        $fields = $this->input('fields', []);

        $config['fields'] = [];
        if (is_array($fields)) {
            foreach ($fields as $fieldName => $types) {
                $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
                if ($safeName === '' || !is_array($types)) continue;

                $config['fields'][$safeName] = [];
                foreach ($types as $type => $msg) {
                    if (preg_match('/^[a-z_]+$/', $type) && trim($msg) !== '') {
                        $config['fields'][$safeName][$type] = trim($msg);
                    }
                }
                if (empty($config['fields'][$safeName])) {
                    unset($config['fields'][$safeName]);
                }
            }
        }

        $this->saveExceptionConfig($config);
        $this->flash('success', 'Field-specific messages updated.');
        $this->redirect('/cms/settings/error-pages');
    }

    public function addField(): void
    {
        $this->requirePermission('settings.edit');

        $fieldName = trim($this->input('field_name', ''));
        $errorType = trim($this->input('error_type', ''));
        $message = trim($this->input('message', ''));

        if ($fieldName === '' || $errorType === '' || $message === '') {
            $this->flash('errors', ['All fields are required.']);
            $this->redirect('/cms/settings/error-pages');
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
        if ($safeName === '' || !preg_match('/^[a-z_]+$/', $errorType)) {
            $this->flash('errors', ['Invalid field name or error type.']);
            $this->redirect('/cms/settings/error-pages');
            return;
        }

        $config = $this->loadExceptionConfig();
        if (!isset($config['fields'])) {
            $config['fields'] = [];
        }
        if (!isset($config['fields'][$safeName])) {
            $config['fields'][$safeName] = [];
        }
        $config['fields'][$safeName][$errorType] = $message;

        $this->saveExceptionConfig($config);
        $this->flash('success', "Field message added for '{$safeName}.{$errorType}'.");
        $this->redirect('/cms/settings/error-pages');
    }

    public function preview(string $code): string
    {
        $this->requirePermission('settings.edit');

        $statusCode = (int) $code;
        $config = $this->loadExceptionConfig();

        $titles = [
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Page Not Found', 405 => 'Method Not Allowed', 419 => 'Page Expired',
            422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
            500 => 'Server Error', 503 => 'Service Unavailable',
        ];

        $title = $titles[$statusCode] ?? 'Error';
        $message = $config['http'][$statusCode] ?? 'An error occurred.';

        // Try to render user template
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $viewsPath = $_ENV['VIEWS_PATH'] ?? '/pages';
        $templateFile = $basePath . $viewsPath . '/errors/' . $statusCode . '.twig';

        if (file_exists($templateFile)) {
            try {
                echo view('errors/' . $statusCode, [
                    'code' => $statusCode,
                    'title' => $title,
                    'message' => $message,
                ]);
                return '';
            } catch (\Throwable $e) {
                // Fall through to default
            }
        }

        // Default preview
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f5f5; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .error-page { text-align: center; padding: 48px 24px; }
        .error-code { font-size: 6rem; font-weight: 700; color: #e0e0e0; line-height: 1; }
        .error-title { font-size: 1.5rem; font-weight: 600; margin: 16px 0 8px; }
        .error-message { color: #666; margin-bottom: 32px; }
        .error-link { display: inline-block; padding: 12px 24px; background: #0078d4; color: white; text-decoration: none; border-radius: 4px; }
        .back-link { display: block; margin-top: 16px; color: #666; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-code">{$statusCode}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-message">{$message}</p>
        <a href="/" class="error-link">Go Home</a>
        <a href="/cms/settings/error-pages" class="back-link">Back to Error Page Settings</a>
    </div>
</body>
</html>
HTML;
        return '';
    }

    // ─── Helpers ────────────────────────────────────────

    private function loadExceptionConfig(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $configFile = $basePath . '/config/exceptions.php';

        if (file_exists($configFile)) {
            return require $configFile;
        }

        return [
            'database' => [],
            'fields' => [],
            'http' => [],
            'dont_report' => [],
        ];
    }

    private function saveExceptionConfig(array $config): void
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $configFile = $basePath . '/config/exceptions.php';
        $configDir = dirname($configFile);

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $content = "<?php\n\nreturn " . $this->exportArray($config) . ";\n";
        file_put_contents($configFile, $content, LOCK_EX);

        // Clear config cache
        $cacheFile = $basePath . '/storage/cache/config.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    private function exportArray(array $array, int $indent = 1): string
    {
        $pad = str_repeat('    ', $indent);
        $padEnd = str_repeat('    ', $indent - 1);
        $lines = ["[\n"];

        foreach ($array as $key => $value) {
            $keyStr = is_int($key) ? $key . ' => ' : "'" . addslashes((string) $key) . "' => ";

            if (is_array($value)) {
                $lines[] = $pad . $keyStr . $this->exportArray($value, $indent + 1) . ",\n";
            } elseif (is_bool($value)) {
                $lines[] = $pad . $keyStr . ($value ? 'true' : 'false') . ",\n";
            } elseif (is_int($value)) {
                $lines[] = $pad . $keyStr . $value . ",\n";
            } elseif (is_null($value)) {
                $lines[] = $pad . $keyStr . "null,\n";
            } else {
                $lines[] = $pad . $keyStr . "'" . addslashes((string) $value) . "',\n";
            }
        }

        $lines[] = $padEnd . ']';
        return implode('', $lines);
    }

    private function getErrorTemplates(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $viewsPath = $_ENV['VIEWS_PATH'] ?? '/pages';
        $errorsDir = $basePath . $viewsPath . '/errors';

        $templates = [];
        if (is_dir($errorsDir)) {
            $files = @scandir($errorsDir);
            if ($files) {
                foreach ($files as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'twig') {
                        $code = pathinfo($file, PATHINFO_FILENAME);
                        $templates[$code] = $errorsDir . '/' . $file;
                    }
                }
            }
        }

        return $templates;
    }
}
