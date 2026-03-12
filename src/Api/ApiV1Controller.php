<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\PageType;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\SchemaManager;
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
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
    }

    private function requireScope(string $scope): void
    {
        if (!OAuthMiddleware::hasScope($scope)) {
            $this->json(['error' => 'insufficient_scope', 'error_description' => "Required scope: {$scope}"], 403);
            exit;
        }
    }

    // ========================================================================
    // PAGES
    // ========================================================================

    /**
     * GET /api/v1/pages — List all page types and their pages.
     */
    public function listPages(): void
    {
        $this->requireScope('read_pages');

        try {
            $pageTypes = PageType::findAll();
            $result = [];

            foreach ($pageTypes as $pt) {
                $result[] = [
                    'id' => $pt->getId(),
                    'name' => $pt->getName(),
                    'slug' => $pt->getSlug(),
                    'template' => $pt->getTemplate(),
                    'page_mode' => $pt->getPageMode(),
                ];
            }

            $this->json(['data' => $result, 'meta' => ['total' => count($result)]]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error', 'error_description' => 'Failed to fetch pages.'], 500);
        }
    }

    /**
     * GET /api/v1/pages/{id} — Get a single page type.
     */
    public function getPage(string $id): void
    {
        $this->requireScope('read_pages');

        try {
            $pt = PageType::find((int) $id);
            if (!$pt) {
                $this->json(['error' => 'not_found', 'error_description' => 'Page type not found.'], 404);
                return;
            }

            $this->json(['data' => [
                'id' => $pt->getId(),
                'name' => $pt->getName(),
                'slug' => $pt->getSlug(),
                'template' => $pt->getTemplate(),
                'description' => $pt->getDescription(),
                'page_mode' => $pt->getPageMode(),
                'has_seo' => $pt->hasSeo(),
                'is_publishable' => $pt->isPublishable(),
            ]]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error'], 500);
        }
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

            $table = $this->schema->getTableName($slug);
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$table}`");
            $offset = ($page - 1) * $perPage;

            $rows = $conn->fetchAllAssociative(
                "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT ? OFFSET ?",
                [$perPage, $offset],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]
            );

            $this->json([
                'data' => $rows,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) ceil($total / $perPage),
                ],
            ]);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error', 'error_description' => $e->getMessage()], 500);
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

            $table = $this->schema->getTableName($slug);
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            // Only insert fields that exist as columns
            $columns = array_keys($conn->createSchemaManager()->listTableColumns($table));
            $data = [];
            foreach ($input as $key => $value) {
                if (in_array(strtolower($key), $columns, true) && $key !== 'id') {
                    $data[$key] = is_array($value) ? json_encode($value) : $value;
                }
            }

            $data['createdAt'] = date('Y-m-d H:i:s');
            $data['updatedAt'] = date('Y-m-d H:i:s');

            $conn->insert($table, $data);
            $id = $conn->lastInsertId();

            // Dispatch webhook
            WebhookDispatcher::getInstance()->dispatch('entry.created', [
                'collection' => $slug,
                'id' => $id,
                'data' => $data,
            ]);

            $this->json(['data' => array_merge($data, ['id' => $id])], 201);
        } catch (\Exception $e) {
            $this->json(['error' => 'server_error', 'error_description' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/v1/collections/{slug}/entries/{id} — Update an entry.
     */
    public function updateEntry(string $slug, string $id): void
    {
        $this->requireScope('write_collections');

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $id = (int) $id;
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        try {
            $table = $this->schema->getTableName($slug);
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $existing = $conn->fetchAssociative("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
            if (!$existing) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

            $columns = array_keys($conn->createSchemaManager()->listTableColumns($table));
            $data = [];
            foreach ($input as $key => $value) {
                if (in_array(strtolower($key), $columns, true) && $key !== 'id') {
                    $data[$key] = is_array($value) ? json_encode($value) : $value;
                }
            }

            $data['updatedAt'] = date('Y-m-d H:i:s');
            $conn->update($table, $data, ['id' => $id]);

            WebhookDispatcher::getInstance()->dispatch('entry.updated', [
                'collection' => $slug,
                'id' => $id,
                'data' => $data,
            ]);

            $this->json(['data' => array_merge($existing, $data)]);
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
        $id = (int) $id;

        try {
            $table = $this->schema->getTableName($slug);
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $affected = $conn->delete($table, ['id' => $id]);
            if ($affected === 0) {
                $this->json(['error' => 'not_found'], 404);
                return;
            }

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

        $result = WebhookDispatcher::getInstance()->subscribe(
            $input['topic'],
            $input['url'],
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
