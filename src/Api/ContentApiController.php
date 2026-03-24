<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\ApiKey;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\TranslationService;

class ContentApiController extends Controller
{
    public function __construct()
    {
        parent::__construct();
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

        // Per-collection API rate limiting
        $rateLimit = $collection->getApiRateLimit();
        if ($rateLimit > 0) {
            $this->checkRateLimit($slug, $rateLimit);
        }

        return $collection;
    }

    /**
     * Check per-collection API rate limit (requests per minute).
     */
    private function checkRateLimit(string $collectionSlug, int $maxPerMinute): void
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        // Use first IP if X-Forwarded-For contains multiple
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $key = "api_rate:{$collectionSlug}:{$ip}";

        // Try cache-based rate limiting first
        try {
            $cache = \ZephyrPHP\Cache\CacheManager::getInstance();
            $current = (int) $cache->get($key, 0);

            if ($current >= $maxPerMinute) {
                header('Content-Type: application/json');
                header('X-RateLimit-Limit: ' . $maxPerMinute);
                header('X-RateLimit-Remaining: 0');
                header('Retry-After: 60');
                http_response_code(429);
                echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
                exit;
            }

            $cache->set($key, $current + 1, 60);

            header('X-RateLimit-Limit: ' . $maxPerMinute);
            header('X-RateLimit-Remaining: ' . max(0, $maxPerMinute - $current - 1));
            return;
        } catch (\Exception $e) {
            // Cache unavailable — fall through (no rate limiting)
        }

        // Fallback: session-based rate limiting
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $data = $_SESSION[$key] ?? null;
        $now = time();

        if ($data && ($now - ($data['start'] ?? 0)) <= 60) {
            if (($data['count'] ?? 0) >= $maxPerMinute) {
                header('Content-Type: application/json');
                header('Retry-After: 60');
                http_response_code(429);
                echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
                exit;
            }
            $_SESSION[$key]['count'] = ($data['count'] ?? 0) + 1;
        } else {
            $_SESSION[$key] = ['start' => $now, 'count' => 1];
        }
    }

    public function index(string $slug): string
    {
        $this->authenticate('read');
        $collection = $this->resolveCollection($slug);

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->input('per_page') ?? 15)));
        $locale = $this->input('locale');

        $query = EntryQuery::collection($slug)
            ->noCache()
            ->orderBy($this->input('sort_by', 'id'), $this->input('sort_dir', 'DESC'))
            ->withRelations(1);

        $search = $this->input('search');
        if ($search) {
            $searchFields = array_map(fn($f) => $f->getSlug(), $collection->getSearchableFields());
            $query->search($search, $searchFields);
        }

        if ($locale) {
            $query->locale($locale);
        }

        // Tree response: return nested structure if ?tree=true
        if ($this->input('tree') === 'true' && $collection->hasHierarchy()) {
            $treeData = EntryQuery::collection($slug)
                ->noCache()
                ->withRelations(1)
                ->tree();
            $this->json([
                'data' => $treeData,
                'meta' => [
                    'tree' => true,
                    'locale' => $locale ?: TranslationService::getDefaultLocale(),
                ],
            ]);
            return '';
        }

        $result = $query->paginate($page, $perPage);

        $this->json([
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
                'locale' => $locale ?: TranslationService::getDefaultLocale(),
            ],
        ]);
        return '';
    }

    public function show(string $slug, string $id): string
    {
        $this->authenticate('read');
        $this->resolveCollection($slug);

        $query = EntryQuery::collection($slug)
            ->noCache()
            ->withRelations(1);

        $locale = $this->input('locale');
        if ($locale) {
            $query->locale($locale);
        }

        $entry = $query->find($id);
        if (!$entry) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        $this->json(['data' => $entry]);
        return '';
    }

    public function store(string $slug): string
    {
        $this->authenticate('write');
        $collection = $this->resolveCollection($slug);

        $fields = $collection->getFields()->toArray();
        $data = $this->buildInputData($fields);

        // Validate required fields
        $errors = $this->validateRequired($fields, $data);
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

        $entryId = EntryQuery::collection($slug)->create($data);

        $entry = EntryQuery::collection($slug)->noCache()->find($entryId);
        $this->json(['data' => $entry], 201);
        return '';
    }

    public function update(string $slug, string $id): string
    {
        $this->authenticate('write');
        $collection = $this->resolveCollection($slug);

        // Check entry exists
        $existing = EntryQuery::collection($slug)->noCache()->find($id);
        if (!$existing) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        $fields = $collection->getFields()->toArray();
        $data = $this->buildInputData($fields);

        // Sanitize richtext fields
        foreach ($fields as $field) {
            if ($field->getType() === 'richtext' && isset($data[$field->getSlug()])) {
                $data[$field->getSlug()] = self::sanitizeHtml($data[$field->getSlug()]);
            }
        }

        EntryQuery::collection($slug)->update($id, $data);

        $entry = EntryQuery::collection($slug)->noCache()->find($id);
        $this->json(['data' => $entry]);
        return '';
    }

    public function destroy(string $slug, string $id): string
    {
        $this->authenticate('delete');
        $this->resolveCollection($slug);

        $entry = EntryQuery::collection($slug)->noCache()->find($id);
        if (!$entry) {
            $this->json(['error' => 'Entry not found.'], 404);
            return '';
        }

        EntryQuery::collection($slug)->delete($id);

        $this->json(['message' => 'Entry deleted.']);
        return '';
    }

    /**
     * Build typed input data from request, handling relation arrays for pivot sync.
     */
    private function buildInputData(array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());
            if ($value === null) {
                continue;
            }

            // Many-to-many relations: pass as array (EntryQuery handles pivot sync)
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    $data[$field->getSlug()] = is_array($value) ? array_map('intval', $value) : [(int) $value];
                    continue;
                }
            }

            $data[$field->getSlug()] = match ($field->getType()) {
                'boolean' => (bool) $value ? 1 : 0,
                'number', 'relation' => (int) $value,
                'decimal' => (float) $value,
                default => $value,
            };
        }
        return $data;
    }

    /**
     * Validate required fields, returns array of errors (empty = valid).
     */
    private function validateRequired(array $fields, array $data): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!$field->isRequired()) {
                continue;
            }

            $slug = $field->getSlug();
            $value = $data[$slug] ?? null;

            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    if (empty($value)) {
                        $errors[$slug] = "{$field->getName()} is required.";
                    }
                    continue;
                }
            }

            if ($value === null || $value === '') {
                $errors[$slug] = "{$field->getName()} is required.";
            }
        }
        return $errors;
    }

    /**
     * Sanitize HTML to prevent XSS — DOM-based allowlist approach.
     *
     * Uses PHP's DOMDocument to parse and rebuild HTML, only keeping
     * whitelisted tags and safe attributes. This is fundamentally more
     * secure than regex-based approaches which can be bypassed.
     */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Remove null bytes first (used to bypass filters)
        $html = str_replace("\0", '', $html);

        // Allowed tags and their allowed attributes
        $allowedTags = [
            'p' => ['class', 'id'],
            'br' => [],
            'strong' => [], 'b' => [],
            'em' => [], 'i' => [],
            'u' => [], 's' => [],
            'h1' => ['id'], 'h2' => ['id'], 'h3' => ['id'],
            'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
            'ul' => ['class'], 'ol' => ['class', 'start', 'type'], 'li' => ['class'],
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
            'blockquote' => ['class'],
            'pre' => ['class'], 'code' => ['class'],
            'hr' => [],
            'table' => ['class'], 'thead' => [], 'tbody' => [],
            'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
            'span' => ['class'], 'div' => ['class'],
            'figure' => ['class'], 'figcaption' => [],
            'sub' => [], 'sup' => [],
        ];

        // Dangerous URI schemes
        $dangerousSchemes = ['javascript', 'vbscript', 'livescript', 'data', 'mhtml'];

        // Parse with DOMDocument
        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__sanitize_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();

        // Recursively sanitize nodes
        $root = $dom->getElementById('__sanitize_root__');
        if (!$root) {
            // Fallback: strip all tags
            return strip_tags($html);
        }

        self::sanitizeNode($root, $allowedTags, $dangerousSchemes, $dom);

        // Extract inner HTML of root
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Recursively sanitize a DOM node and its children.
     */
    private static function sanitizeNode(
        \DOMNode $node,
        array $allowedTags,
        array $dangerousSchemes,
        \DOMDocument $dom
    ): void {
        $nodesToRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue; // Text nodes are safe
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $nodesToRemove[] = $child; // Strip comments (can contain IE conditionals)
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $nodesToRemove[] = $child;
                continue;
            }

            /** @var \DOMElement $child */
            $tagName = strtolower($child->nodeName);

            if (!isset($allowedTags[$tagName])) {
                // Unwrap: replace element with its children (preserves text content)
                $fragment = $dom->createDocumentFragment();
                while ($child->firstChild) {
                    $fragment->appendChild($child->firstChild);
                }
                $node->replaceChild($fragment, $child);
                // Re-sanitize since we changed the tree
                self::sanitizeNode($node, $allowedTags, $dangerousSchemes, $dom);
                return;
            }

            // Remove disallowed attributes
            $allowedAttrs = $allowedTags[$tagName];
            $attrsToRemove = [];
            foreach ($child->attributes as $attr) {
                $attrName = strtolower($attr->name);

                // Block all event handlers (on*)
                if (str_starts_with($attrName, 'on')) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Block style attribute entirely (expression(), url() attacks)
                if ($attrName === 'style') {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Check if attribute is in allowlist
                if (!in_array($attrName, $allowedAttrs, true)) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Validate URI attributes against dangerous schemes
                if (in_array($attrName, ['href', 'src', 'action', 'formaction', 'poster', 'background'], true)) {
                    $value = trim($attr->value);
                    // Decode entities to catch encoded schemes
                    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    // Remove whitespace/control chars that can hide schemes
                    $normalized = preg_replace('/[\s\x00-\x1f]+/', '', strtolower($decoded));

                    foreach ($dangerousSchemes as $scheme) {
                        if (str_starts_with($normalized, $scheme . ':')) {
                            $attrsToRemove[] = $attr->name;
                            break;
                        }
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                $child->removeAttribute($attrName);
            }

            // Force rel="noopener noreferrer" on links with target="_blank"
            if ($tagName === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            // Recurse into children
            if ($child->hasChildNodes()) {
                self::sanitizeNode($child, $allowedTags, $dangerousSchemes, $dom);
            }
        }

        // Remove collected nodes
        foreach ($nodesToRemove as $nodeToRemove) {
            $node->removeChild($nodeToRemove);
        }
    }
}
