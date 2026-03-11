<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\ApiKey;
use ZephyrPHP\Cms\Services\SchemaManager;

class ContentApiController extends Controller
{
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
    }

    /**
     * Authenticate API request. Returns true if authenticated, exits with error if not.
     * Read-only endpoints can optionally be public (if collection has api enabled).
     */
    private function authenticate(string $requiredPermission = 'read'): ?ApiKey
    {
        $apiKey = $this->extractApiKey();

        if (!$apiKey) {
            // Allow public read access if no key provided (backward compatible)
            if ($requiredPermission === 'read') {
                return null;
            }
            $this->json(['error' => 'API key required. Use Authorization: Bearer <key> or X-API-Key header.'], 401);
            exit;
        }

        $keyModel = ApiKey::findOneBy(['key' => hash('sha256', $apiKey)]);

        if (!$keyModel) {
            $this->json(['error' => 'Invalid API key.'], 401);
            exit;
        }

        if (!$keyModel->isActive()) {
            $this->json(['error' => 'API key is inactive.'], 403);
            exit;
        }

        if ($keyModel->getExpiresAt() && $keyModel->getExpiresAt() < new \DateTime()) {
            $this->json(['error' => 'API key has expired.'], 403);
            exit;
        }

        // Check permission level
        if (!$keyModel->hasPermission($requiredPermission)) {
            $this->json(['error' => "Insufficient permissions. Required: {$requiredPermission}"], 403);
            exit;
        }

        // Update last used
        $keyModel->setLastUsedAt(new \DateTime());
        $keyModel->save();

        return $keyModel;
    }

    private function extractApiKey(): ?string
    {
        // Check Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check X-API-Key header
        $xApiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($xApiKey) {
            return $xApiKey;
        }

        // Query param intentionally removed — API keys in URLs leak via logs, referer headers, and browser history
        return null;
    }

    private function resolveCollection(string $slug): ?Collection
    {
        $collection = Collection::findOneBy(['slug' => $slug]);

        if (!$collection || !$collection->isApiEnabled()) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Collection not found or API not enabled.']);
            exit;
        }

        return $collection;
    }

    public function index(string $slug): string
    {
        $this->authenticate('read');
        $collection = $this->resolveCollection($slug);

        $searchableFields = array_map(
            fn($f) => $f->getSlug(),
            $collection->getSearchableFields()
        );

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->input('per_page') ?? 15)));

        $result = $this->schema->listEntries($collection->getTableName(), [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $this->input('sort_by', 'id'),
            'sort_dir' => $this->input('sort_dir', 'DESC'),
            'search' => $this->input('search'),
            'searchFields' => $searchableFields,
        ]);

        $this->json([
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
            ],
        ]);
        return '';
    }

    public function show(string $slug, string $id): string
    {
        $this->authenticate('read');
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        // Include pivot relation data
        foreach ($collection->getFields() as $field) {
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    $targetTable = 'cms_' . ($field->getOptions()['relation_collection'] ?? '');
                    $entry[$field->getSlug()] = $this->schema->getPivotRelations(
                        $collection->getTableName(),
                        $field->getSlug(),
                        $targetTable,
                        $id
                    );
                }
            }
        }

        $this->json(['data' => $entry]);
        return '';
    }

    public function store(string $slug): string
    {
        $this->authenticate('write');
        $collection = $this->resolveCollection($slug);

        $fields = $collection->getFields()->toArray();
        $data = [];
        $pivotData = [];

        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());

            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    if ($value !== null) {
                        $pivotData[$field->getSlug()] = is_array($value) ? array_map('intval', $value) : [(int) $value];
                    }
                    continue;
                }
            }

            if ($value !== null) {
                $data[$field->getSlug()] = match ($field->getType()) {
                    'boolean' => (bool) $value ? 1 : 0,
                    'number', 'relation' => (int) $value,
                    'decimal' => (float) $value,
                    default => $value,
                };
            }
        }

        // Validate required fields
        $errors = [];
        foreach ($fields as $field) {
            if ($field->isRequired() && !isset($data[$field->getSlug()])) {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($field->getType() === 'relation' && $relationType !== 'one_to_one') {
                    if (empty($pivotData[$field->getSlug()] ?? [])) {
                        $errors[$field->getSlug()] = "{$field->getName()} is required.";
                    }
                } else {
                    $errors[$field->getSlug()] = "{$field->getName()} is required.";
                }
            }
        }

        if (!empty($errors)) {
            $this->json(['error' => 'Validation failed.', 'errors' => $errors], 422);
            return '';
        }

        // Sanitize richtext fields
        foreach ($fields as $field) {
            if ($field->getType() === 'richtext' && isset($data[$field->getSlug()])) {
                $data[$field->getSlug()] = self::sanitizeHtml($data[$field->getSlug()]);
            }
        }

        $entryId = $this->schema->insertEntry($collection->getTableName(), $data, $collection->isUuid());

        // Sync pivot relations
        foreach ($fields as $field) {
            if (isset($pivotData[$field->getSlug()])) {
                $targetTable = 'cms_' . ($field->getOptions()['relation_collection'] ?? '');
                $this->schema->syncPivotRelations(
                    $collection->getTableName(), $field->getSlug(), $targetTable, $entryId, $pivotData[$field->getSlug()]
                );
            }
        }

        $entry = $this->schema->findEntry($collection->getTableName(), $entryId);
        $this->json(['data' => $entry], 201);
        return '';
    }

    public function update(string $slug, string $id): string
    {
        $this->authenticate('write');
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        $fields = $collection->getFields()->toArray();
        $data = [];
        $pivotData = [];

        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());

            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    if ($value !== null) {
                        $pivotData[$field->getSlug()] = is_array($value) ? array_map('intval', $value) : [(int) $value];
                    }
                    continue;
                }
            }

            if ($value !== null) {
                $data[$field->getSlug()] = match ($field->getType()) {
                    'boolean' => (bool) $value ? 1 : 0,
                    'number', 'relation' => (int) $value,
                    'decimal' => (float) $value,
                    default => $value,
                };
            }
        }

        // Sanitize richtext fields
        foreach ($fields as $field) {
            if ($field->getType() === 'richtext' && isset($data[$field->getSlug()])) {
                $data[$field->getSlug()] = self::sanitizeHtml($data[$field->getSlug()]);
            }
        }

        $this->schema->updateEntry($collection->getTableName(), $id, $data);

        // Sync pivot relations
        foreach ($fields as $field) {
            if (isset($pivotData[$field->getSlug()])) {
                $targetTable = 'cms_' . ($field->getOptions()['relation_collection'] ?? '');
                $this->schema->syncPivotRelations(
                    $collection->getTableName(), $field->getSlug(), $targetTable, $id, $pivotData[$field->getSlug()]
                );
            }
        }

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        $this->json(['data' => $entry]);
        return '';
    }

    public function destroy(string $slug, string $id): string
    {
        $this->authenticate('delete');
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        $this->schema->deleteEntry($collection->getTableName(), $id);

        $this->json(['message' => 'Entry deleted.']);
        return '';
    }

    /**
     * Sanitize HTML to prevent XSS — allow safe tags only.
     * Uses a multi-pass approach for robust protection.
     */
    public static function sanitizeHtml(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><s><h1><h2><h3><h4><h5><h6>'
            . '<ul><ol><li><a><img><blockquote><pre><code><hr><table><thead><tbody><tr><th><td>'
            . '<span><div><figure><figcaption><sub><sup>';
        $clean = strip_tags($html, $allowed);

        // Decode HTML entities to catch encoded attacks (&#106;avascript:, &#x6A;avascript:)
        // Run multiple passes since attackers can double-encode
        $maxPasses = 3;
        for ($i = 0; $i < $maxPasses; $i++) {
            $decoded = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $clean) {
                break;
            }
            $clean = $decoded;
        }

        // Re-strip tags after decoding (entities might have revealed new tags)
        $clean = strip_tags($clean, $allowed);

        // Remove ALL event handler attributes — handles whitespace variations and encoded forms
        // Match: onXXX = "..." or onXXX = '...' or onXXX = value (with any whitespace)
        $clean = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $clean);

        // Remove javascript: protocol in href/src/action — handles whitespace and encoding
        $clean = preg_replace('/(?:href|src|action|formaction|xlink:href|poster|background)\s*=\s*["\']?\s*(?:javascript|vbscript|livescript)\s*:/i', 'href="#blocked"', $clean);

        // Remove data: URIs in src/href (can embed executable content)
        $clean = preg_replace('/(?:src|href|poster|background)\s*=\s*["\']?\s*data\s*:/i', 'src="#blocked"', $clean);

        // Remove style attributes with expression() or url(javascript:)
        $clean = preg_replace('/style\s*=\s*["\'][^"\']*(?:expression|javascript|vbscript)[^"\']*["\']/i', '', $clean);

        // Remove <base> tag injection attempts (already stripped by strip_tags, but be safe)
        $clean = preg_replace('/<base\b[^>]*>/i', '', $clean);

        // Remove null bytes (used to bypass filters)
        $clean = str_replace("\0", '', $clean);

        return $clean;
    }
}
