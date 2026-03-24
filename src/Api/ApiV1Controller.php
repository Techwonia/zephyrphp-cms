<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\OAuth\OAuthMiddleware;
use ZephyrPHP\Webhook\WebhookDispatcher;

/**
 * REST API v1 Controller — provides versioned API endpoints for external apps.
 *
 * All endpoints require OAuth 2.0 Bearer token authentication.
 * Scopes control access to resources.
 */
class ApiV1Controller extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    private function requireScope(string $scope): void
    {
        if (!OAuthMiddleware::hasScope($scope)) {
            $this->json(['error' => 'insufficient_scope', 'error_description' => "Required scope: {$scope}"], 403);
            exit;
        }
    }

    /**
     * Sanitize and whitelist input data against actual table columns.
     * Only allows safe identifier keys that exist as columns (excludes 'id').
     */
    private function whitelistInput(array $input, string $slug): array
    {
        $schema = SchemaManager::getInstance();
        $collection = Collection::findBySlug($slug);
        if (!$collection) {
            return [];
        }

        $columns = array_keys($schema->getConnection()->createSchemaManager()->listTableColumns($collection->getTableName()));
        $data = [];
        foreach ($input as $key => $value) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)
                && in_array(strtolower($key), $columns, true)
                && $key !== 'id') {
                $data[$key] = is_array($value) ? json_encode($value) : $value;
            }
        }
        return $data;
    }

    // ========================================================================
    // COLLECTIONS
    // ========================================================================

    /**
     * GET /api/v1/collections — List all collections.
     */
    public function listCollections(): void
    {
        $this->requireScope('read_collections');

        try {
            $collections = Collection::findAll();
            $result = [];

            foreach ($collections as $col) {
                $result[] = [
                    'slug' => $col->getSlug(),
                    'name' => $col->getName(),
                    'fields' => count($col->getFields()),
                    'api_enabled' => $col->isApiEnabled(),
                ];
            }

            $this->json(['data' => $result, 'meta' => ['total' => count($result)]]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
    }

    /**
     * GET /api/v1/collections/{slug}/entries — List entries in a collection.
     */
    public function listEntries(string $slug): void
    {
        $this->requireScope('read_collections');

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

        try {
            $collection = Collection::findBySlug($slug);
            if (!$collection) {
                $this->json(['error' => 'not_found', 'error_description' => 'Collection not found.'], 404);
                return;
            }

            $result = EntryQuery::collection($slug)
                ->noCache()
                ->orderBy('id', 'DESC')
                ->paginate($page, $perPage);

            $this->json([
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $result['per_page'],
                    'current_page' => $result['current_page'],
                    'last_page' => $result['last_page'],
                ],
            ]);
        } catch (\Exception $e) {
            error_log('CMS API error: ' . $e->getMessage());
            $this->json(['error' => 'server_error', 'error_description' => 'An internal error occurred.'], 500);
        }
    }

    /**
     * POST /api/v1/collections/{slug}/entries — Create an entry.
     */
    public function createEntry(string $slug): void
    {
        $this->requireScope('write_collections');

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->json(['error' => 'invalid_request', 'error_description' => 'Request body must be JSON.'], 400);
            return;
        }

        try {
            $collection = Collection::findBySlug($slug);
            if (!$collection) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            $data = $this->whitelistInput($input, $slug);
            $entryId = EntryQuery::collection($slug)->create($data);

            // Dispatch webhook
            WebhookDispatcher::getInstance()->dispatch('entry.created', [
                'collection' => $slug,
                'id' => $entryId,
                'data' => $data,
            ]);

            $entry = EntryQuery::collection($slug)->noCache()->find($entryId);
            $this->json(['data' => $entry], 201);
        } catch (\Exception $e) {
            error_log('CMS API error: ' . $e->getMessage());
            $this->json(['error' => 'server_error', 'error_description' => 'An internal error occurred.'], 500);
        }
    }

    /**
     * PUT /api/v1/collections/{slug}/entries/{id} — Update an entry.
     */
    public function updateEntry(string $slug, string $id): void
    {
        $this->requireScope('write_collections');

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        try {
            $collection = Collection::findBySlug($slug);
            if (!$collection) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            $existing = EntryQuery::collection($slug)->noCache()->find($id);
            if (!$existing) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            $data = $this->whitelistInput($input, $slug);
            EntryQuery::collection($slug)->update($id, $data);

            WebhookDispatcher::getInstance()->dispatch('entry.updated', [
                'collection' => $slug,
                'id' => $id,
                'data' => $data,
            ]);

            $entry = EntryQuery::collection($slug)->noCache()->find($id);
            $this->json(['data' => $entry]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
    }

    /**
     * DELETE /api/v1/collections/{slug}/entries/{id} — Delete an entry.
     */
    public function deleteEntry(string $slug, string $id): void
    {
        $this->requireScope('write_collections');

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        try {
            $collection = Collection::findBySlug($slug);
            if (!$collection) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            $existing = EntryQuery::collection($slug)->noCache()->find($id);
            if (!$existing) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            EntryQuery::collection($slug)->delete($id);

            WebhookDispatcher::getInstance()->dispatch('entry.deleted', [
                'collection' => $slug,
                'id' => $id,
            ]);

            $this->json(['data' => ['deleted' => true]], 200);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
    }

    // ========================================================================
    // THEMES
    // ========================================================================

    /**
     * GET /api/v1/themes — List all themes.
     */
    public function listThemes(): void
    {
        $this->requireScope('read_themes');

        try {
            $themeManager = new ThemeManager();
            $themes = $themeManager->listThemes();
            $result = [];

            foreach ($themes as $theme) {
                $result[] = [
                    'slug' => $theme->getSlug(),
                    'name' => $theme->getName(),
                    'status' => $theme->getStatus(),
                    'description' => $theme->getDescription(),
                ];
            }

            $this->json(['data' => $result]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
    }

    // ========================================================================
    // USERS
    // ========================================================================

    /**
     * GET /api/v1/users — List users (limited fields).
     */
    public function listUsers(): void
    {
        $this->requireScope('read_users');

        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $rows = $conn->fetchAllAssociative(
                'SELECT id, name, email, role, createdAt FROM users ORDER BY id ASC LIMIT 100'
            );

            $this->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
    }

    // ========================================================================
    // WEBHOOKS (self-service for OAuth clients)
    // ========================================================================

    /**
     * GET /api/v1/webhooks — List my webhook subscriptions.
     */
    public function listWebhooks(): void
    {
        $clientId = $_REQUEST['_oauth_client_id'] ?? '';
        $subscriptions = WebhookDispatcher::getInstance()->listSubscriptions($clientId);
        $this->json(['data' => $subscriptions]);
    }

    /**
     * POST /api/v1/webhooks — Create a webhook subscription.
     */
    public function createWebhook(): void
    {
        $clientId = $_REQUEST['_oauth_client_id'] ?? '';
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input) || empty($input['topic']) || empty($input['url'])) {
            $this->json(['error' => 'invalid_request', 'error_description' => 'topic and url are required.'], 400);
            return;
        }

        // Validate webhook URL to prevent SSRF
        $webhookUrl = $input['url'];
        $parsed = parse_url($webhookUrl);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            $this->json(['error' => 'invalid_request', 'error_description' => 'Webhook URL must use http or https.'], 400);
            return;
        }
        $host = $parsed['host'] ?? '';
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            $this->json(['error' => 'invalid_request', 'error_description' => 'Could not resolve webhook URL host.'], 400);
            return;
        }
        // Block private/reserved IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $this->json(['error' => 'invalid_request', 'error_description' => 'Webhook URL must point to a public IP address.'], 400);
            return;
        }

        $result = WebhookDispatcher::getInstance()->subscribe(
            $input['topic'],
            $webhookUrl,
            $clientId,
            $input['format'] ?? 'json'
        );

        if ($result['success']) {
            $this->json([
                'data' => [
                    'id' => $result['id'],
                    'secret' => $result['secret'],
                    'topic' => $input['topic'],
                    'url' => $input['url'],
                ],
            ], 201);
        } else {
            $this->json(['error' => 'invalid_request', 'error_description' => $result['error']], 400);
        }
    }

    /**
     * DELETE /api/v1/webhooks/{id} — Delete a webhook subscription.
     */
    public function deleteWebhook(string $id): void
    {
        $clientId = $_REQUEST['_oauth_client_id'] ?? '';

        if (WebhookDispatcher::getInstance()->unsubscribe((int) $id, $clientId)) {
            $this->json(['data' => ['deleted' => true]]);
        } else {
            $this->json(['error' => 'not_found'], 404);
        }
    }
}
