<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class WebhookController extends Controller
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
        $this->requirePermission('api-keys.manage');

        $webhooks = $this->getWebhooks();

        return $this->render('cms::webhooks/index', [
            'webhooks' => $webhooks,
            'events' => $this->getAvailableEvents(),
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('api-keys.manage');

        $url = trim($this->input('url', ''));
        $events = $this->input('events', []);
        $secret = trim($this->input('secret', ''));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->flash('errors', ['Please provide a valid webhook URL.']);
            $this->redirect('/cms/webhooks');
            return;
        }

        if (!str_starts_with($url, 'https://')) {
            $this->flash('errors', ['Webhook URL must use HTTPS.']);
            $this->redirect('/cms/webhooks');
            return;
        }

        if (empty($events) || !is_array($events)) {
            $this->flash('errors', ['Please select at least one event.']);
            $this->redirect('/cms/webhooks');
            return;
        }

        try {
            $conn = \ZephyrPHP\Database\DB::connection();

            // Create webhooks table if not exists
            $this->ensureWebhooksTable($conn);

            $conn->insert('cms_webhooks', [
                'url' => $url,
                'events' => json_encode(array_values($events)),
                'secret' => $secret !== '' ? $secret : null,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->flash('success', 'Webhook created successfully.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to create webhook: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/webhooks');
    }

    public function toggle(string $id): void
    {
        $this->requirePermission('api-keys.manage');

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $webhook = $conn->fetchAssociative('SELECT * FROM cms_webhooks WHERE id = ?', [(int) $id]);

            if (!$webhook) {
                $this->flash('errors', ['Webhook not found.']);
                $this->redirect('/cms/webhooks');
                return;
            }

            $newStatus = $webhook['is_active'] ? 0 : 1;
            $conn->update('cms_webhooks', ['is_active' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $id]);

            $statusLabel = $newStatus ? 'activated' : 'deactivated';
            $this->flash('success', "Webhook {$statusLabel}.");
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to toggle webhook.']);
        }

        $this->redirect('/cms/webhooks');
    }

    public function destroy(string $id): void
    {
        $this->requirePermission('api-keys.manage');

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $conn->delete('cms_webhooks', ['id' => (int) $id]);
            $this->flash('success', 'Webhook deleted.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to delete webhook.']);
        }

        $this->redirect('/cms/webhooks');
    }

    public function test(string $id): void
    {
        $this->requirePermission('api-keys.manage');

        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $this->ensureDeliveryLogsTable($conn);
            $webhook = $conn->fetchAssociative('SELECT * FROM cms_webhooks WHERE id = ?', [(int) $id]);

            if (!$webhook) {
                $this->flash('errors', ['Webhook not found.']);
                $this->redirect('/cms/webhooks');
                return;
            }

            $payload = json_encode([
                'event' => 'webhook.test',
                'timestamp' => date('c'),
                'data' => ['message' => 'This is a test webhook delivery.'],
            ]);

            $result = $this->sendWebhook($webhook, 'webhook.test', $payload);

            // Log the delivery
            $conn->insert('cms_webhook_deliveries', [
                'webhook_id' => (int) $id,
                'event' => 'webhook.test',
                'payload' => $payload,
                'response_code' => $result['status_code'],
                'response_body' => mb_substr($result['response'], 0, 5000),
                'error' => $result['error'],
                'duration_ms' => $result['duration_ms'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Update webhook last triggered
            $conn->update('cms_webhooks', [
                'last_triggered_at' => date('Y-m-d H:i:s'),
                'last_status_code' => $result['status_code'],
            ], ['id' => (int) $id]);

            if ($result['success']) {
                $this->flash('success', 'Webhook test sent. HTTP ' . $result['status_code']);
            } elseif ($result['error']) {
                $this->flash('errors', ['Webhook test failed: ' . $result['error']]);
            } else {
                $this->flash('errors', ['Webhook responded with HTTP ' . $result['status_code']]);
            }
        } catch (\Throwable $e) {
            $this->flash('errors', ['Webhook test failed: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/webhooks');
    }

    public function logs(string $id): string
    {
        $this->requirePermission('api-keys.manage');

        $conn = \ZephyrPHP\Database\DB::connection();
        $this->ensureDeliveryLogsTable($conn);

        $webhook = $conn->fetchAssociative('SELECT * FROM cms_webhooks WHERE id = ?', [(int) $id]);
        if (!$webhook) {
            $this->flash('errors', ['Webhook not found.']);
            $this->redirect('/cms/webhooks');
            return '';
        }

        $webhook['events'] = json_decode($webhook['events'] ?? '[]', true) ?: [];

        $logs = $conn->fetchAllAssociative(
            'SELECT * FROM cms_webhook_deliveries WHERE webhook_id = ? ORDER BY created_at DESC LIMIT 100',
            [(int) $id]
        );

        return $this->render('cms::webhooks/logs', [
            'webhook' => $webhook,
            'logs' => $logs,
            'user' => Auth::user(),
        ]);
    }

    public function retry(string $id, string $deliveryId): void
    {
        $this->requirePermission('api-keys.manage');

        $conn = \ZephyrPHP\Database\DB::connection();
        $delivery = $conn->fetchAssociative(
            'SELECT * FROM cms_webhook_deliveries WHERE id = ? AND webhook_id = ?',
            [(int) $deliveryId, (int) $id]
        );

        if (!$delivery) {
            $this->flash('errors', ['Delivery not found.']);
            $this->redirect('/cms/webhooks/' . $id . '/logs');
            return;
        }

        $webhook = $conn->fetchAssociative('SELECT * FROM cms_webhooks WHERE id = ?', [(int) $id]);
        if (!$webhook) {
            $this->flash('errors', ['Webhook not found.']);
            $this->redirect('/cms/webhooks');
            return;
        }

        $result = $this->sendWebhook($webhook, $delivery['event'], $delivery['payload']);

        $conn->insert('cms_webhook_deliveries', [
            'webhook_id' => (int) $id,
            'event' => $delivery['event'],
            'payload' => $delivery['payload'],
            'response_code' => $result['status_code'],
            'response_body' => mb_substr($result['response'] ?? '', 0, 5000),
            'error' => $result['error'] ?? null,
            'duration_ms' => $result['duration_ms'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($result['success']) {
            $this->flash('success', 'Webhook retry sent. HTTP ' . $result['status_code']);
        } else {
            $this->flash('errors', ['Retry failed: ' . ($result['error'] ?: 'HTTP ' . $result['status_code'])]);
        }

        $this->redirect('/cms/webhooks/' . $id . '/logs');
    }

    private function sendWebhook(array $webhook, string $event, string $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: ZephyrPHP-Webhook/1.0',
            'X-Webhook-Event: ' . $event,
        ];

        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', $payload, $webhook['secret']);
            $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
        }

        $start = microtime(true);

        $ch = curl_init($webhook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $duration = (int) round((microtime(true) - $start) * 1000);

        return [
            'success' => !$error && $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'response' => $response ?: '',
            'error' => $error ?: null,
            'duration_ms' => $duration,
        ];
    }

    private function getWebhooks(): array
    {
        try {
            $conn = \ZephyrPHP\Database\DB::connection();
            $this->ensureWebhooksTable($conn);
            $webhooks = $conn->fetchAllAssociative('SELECT * FROM cms_webhooks ORDER BY created_at DESC');
            foreach ($webhooks as &$wh) {
                $wh['events'] = json_decode($wh['events'] ?? '[]', true) ?: [];
            }
            return $webhooks;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getAvailableEvents(): array
    {
        return [
            'entry.created' => 'Entry Created',
            'entry.updated' => 'Entry Updated',
            'entry.deleted' => 'Entry Deleted',
            'entry.published' => 'Entry Published',
            'form.submitted' => 'Form Submitted',
            'user.registered' => 'User Registered',
            'user.updated' => 'User Updated',
            'media.uploaded' => 'Media Uploaded',
            'media.deleted' => 'Media Deleted',
            'theme.published' => 'Theme Published',
            'collection.created' => 'Collection Created',
            'collection.deleted' => 'Collection Deleted',
            'webhook.test' => 'Webhook Test',
        ];
    }

    private function ensureDeliveryLogsTable($conn): void
    {
        $this->ensureWebhooksTable($conn);
        $sm = $conn->createSchemaManager();
        if (!$sm->tablesExist(['cms_webhook_deliveries'])) {
            $conn->executeStatement("
                CREATE TABLE cms_webhook_deliveries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    webhook_id INT NOT NULL,
                    event VARCHAR(100) NOT NULL,
                    payload TEXT NULL,
                    response_code INT NULL,
                    response_body TEXT NULL,
                    error TEXT NULL,
                    duration_ms INT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_webhook_id (webhook_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    private function ensureWebhooksTable($conn): void
    {
        $sm = $conn->createSchemaManager();
        if (!$sm->tablesExist(['cms_webhooks'])) {
            $conn->executeStatement("
                CREATE TABLE cms_webhooks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    url VARCHAR(500) NOT NULL,
                    events JSON NULL,
                    secret VARCHAR(255) NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    last_triggered_at DATETIME NULL,
                    last_status_code INT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
}
