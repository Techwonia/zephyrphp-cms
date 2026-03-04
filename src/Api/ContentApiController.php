<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\SchemaManager;

class ContentApiController extends Controller
{
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
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

        return $this->json([
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
            ],
        ]);
    }

    public function show(string $slug, int $id): string
    {
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        return $this->json(['data' => $entry]);
    }

    public function store(string $slug): string
    {
        $collection = $this->resolveCollection($slug);

        $fields = $collection->getFields()->toArray();
        $data = [];

        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());
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
                $errors[$field->getSlug()] = "{$field->getName()} is required.";
            }
        }

        if (!empty($errors)) {
            return $this->json(['error' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $id = $this->schema->insertEntry($collection->getTableName(), $data);
        $entry = $this->schema->findEntry($collection->getTableName(), $id);

        return $this->json(['data' => $entry], 201);
    }

    public function update(string $slug, int $id): string
    {
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        $fields = $collection->getFields()->toArray();
        $data = [];

        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());
            if ($value !== null) {
                $data[$field->getSlug()] = match ($field->getType()) {
                    'boolean' => (bool) $value ? 1 : 0,
                    'number', 'relation' => (int) $value,
                    'decimal' => (float) $value,
                    default => $value,
                };
            }
        }

        $this->schema->updateEntry($collection->getTableName(), $id, $data);
        $entry = $this->schema->findEntry($collection->getTableName(), $id);

        return $this->json(['data' => $entry]);
    }

    public function destroy(string $slug, int $id): string
    {
        $collection = $this->resolveCollection($slug);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        $this->schema->deleteEntry($collection->getTableName(), $id);

        return $this->json(['message' => 'Entry deleted.']);
    }
}
