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
        $collection = $this->resolveCollection($slug);

        $fields = $collection->getFields()->toArray();
        $data = [];
        $pivotData = [];

        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());

            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    // Pivot relation — expect array of IDs
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
}
