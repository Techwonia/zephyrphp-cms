<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\SchemaManager;

class EntryController extends Controller
{
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
    }

    private function requireAdmin(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!Auth::user()->hasRole('admin')) {
            $this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            $this->redirect('/v1/dashboard');
        }
    }

    private function resolveCollection(string $slug): ?Collection
    {
        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return null;
        }
        return $collection;
    }

    public function index(string $slug): string
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';

        $fields = $collection->getFields()->toArray();
        $listableFields = $collection->getListableFields();
        $searchableFields = $collection->getSearchableFields();

        $options = [
            'page' => max(1, (int) ($this->input('page') ?? 1)),
            'per_page' => 20,
            'sort_by' => $this->input('sort_by', 'id'),
            'sort_dir' => $this->input('sort_dir', 'DESC'),
            'search' => $this->input('search'),
            'searchFields' => array_map(fn(Field $f) => $f->getSlug(), $searchableFields),
        ];

        $entries = $this->schema->listEntries($collection->getTableName(), $options);

        return $this->render('cms::entries/index', [
            'collection' => $collection,
            'listableFields' => $listableFields,
            'entries' => $entries,
            'search' => $options['search'] ?? '',
            'sortBy' => $options['sort_by'],
            'sortDir' => $options['sort_dir'],
            'user' => Auth::user(),
        ]);
    }

    public function create(string $slug): string
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';

        // Load related collection data for relation fields
        $relationData = $this->loadRelationData($collection->getFields()->toArray());

        return $this->render('cms::entries/create', [
            'collection' => $collection,
            'fields' => $collection->getFields()->toArray(),
            'relationData' => $relationData,
            'user' => Auth::user(),
        ]);
    }

    public function store(string $slug): void
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $fields = $collection->getFields()->toArray();
        $data = $this->buildEntryData($fields);
        $pivotData = $this->collectPivotData($fields);
        $errors = $this->validateEntryData($fields, $data, $pivotData);

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', $data);
            $this->back();
            return;
        }

        // Handle publishable
        if ($collection->isPublishable()) {
            $data['status'] = $this->input('status', 'draft');
            if ($data['status'] === 'published') {
                $data['published_at'] = (new \DateTime())->format('Y-m-d H:i:s');
            }
        }

        $data['created_by'] = Auth::user()?->getId();

        $entryId = $this->schema->insertEntry($collection->getTableName(), $data, $collection->isUuid());

        // Sync pivot relations
        $this->syncAllPivotRelations($collection->getTableName(), $fields, $entryId, $pivotData);

        $this->flash('success', 'Entry created successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function edit(string $slug, string $id): string
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['entry' => 'Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $fields = $collection->getFields()->toArray();
        $relationData = $this->loadRelationData($fields);

        // Load pivot relation IDs for multi-relation fields
        foreach ($fields as $field) {
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

        return $this->render('cms::entries/edit', [
            'collection' => $collection,
            'fields' => $fields,
            'entry' => $entry,
            'relationData' => $relationData,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug, string $id): void
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['entry' => 'Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        $fields = $collection->getFields()->toArray();
        $data = $this->buildEntryData($fields, $entry);
        $pivotData = $this->collectPivotData($fields);
        $errors = $this->validateEntryData($fields, $data, $pivotData);

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        // Handle publishable
        if ($collection->isPublishable()) {
            $newStatus = $this->input('status', $entry['status'] ?? 'draft');
            $data['status'] = $newStatus;
            if ($newStatus === 'published' && ($entry['published_at'] ?? null) === null) {
                $data['published_at'] = (new \DateTime())->format('Y-m-d H:i:s');
            }
        }

        $this->schema->updateEntry($collection->getTableName(), $id, $data);

        // Sync pivot relations
        $this->syncAllPivotRelations($collection->getTableName(), $fields, $id, $pivotData);

        $this->flash('success', 'Entry updated successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function destroy(string $slug, string $id): void
    {
        $this->requireAdmin();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $this->schema->deleteEntry($collection->getTableName(), $id);

        $this->flash('success', 'Entry deleted.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function buildEntryData(array $fields, ?array $existing = null): array
    {
        $data = [];
        foreach ($fields as $field) {
            // Skip pivot relation fields — they have no column in the main table
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    continue;
                }
                $value = $this->input($field->getSlug());
                $data[$field->getSlug()] = $value !== null && $value !== '' ? (int) $value : null;
                continue;
            }

            $value = $this->input($field->getSlug());
            $data[$field->getSlug()] = match ($field->getType()) {
                'boolean' => $this->boolean($field->getSlug()) ? 1 : 0,
                'number' => $value !== null && $value !== '' ? (int) $value : null,
                'decimal' => $value !== null && $value !== '' ? (float) $value : null,
                'image', 'file' => $this->handleFileUpload($field, $existing),
                default => $value !== '' ? $value : null,
            };
        }
        return $data;
    }

    /**
     * Collect selected IDs for pivot relation fields from form input
     */
    private function collectPivotData(array $fields): array
    {
        $pivotData = [];
        foreach ($fields as $field) {
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    $value = $this->input($field->getSlug());
                    if (is_array($value)) {
                        $pivotData[$field->getSlug()] = array_map('intval', array_filter($value, fn($v) => $v !== '' && $v !== null));
                    } else {
                        $pivotData[$field->getSlug()] = [];
                    }
                }
            }
        }
        return $pivotData;
    }

    /**
     * Sync all pivot relations for an entry
     */
    private function syncAllPivotRelations(string $tableName, array $fields, int|string $entryId, array $pivotData): void
    {
        foreach ($fields as $field) {
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one' && isset($pivotData[$field->getSlug()])) {
                    $targetTable = 'cms_' . ($field->getOptions()['relation_collection'] ?? '');
                    $this->schema->syncPivotRelations(
                        $tableName,
                        $field->getSlug(),
                        $targetTable,
                        $entryId,
                        $pivotData[$field->getSlug()]
                    );
                }
            }
        }
    }

    private function validateEntryData(array $fields, array $data, array $pivotData = []): array
    {
        $errors = [];
        foreach ($fields as $field) {
            // Check pivot relations for required
            if ($field->getType() === 'relation') {
                $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
                if ($relationType !== 'one_to_one') {
                    if ($field->isRequired() && empty($pivotData[$field->getSlug()] ?? [])) {
                        $errors[$field->getSlug()] = "{$field->getName()} is required.";
                    }
                    continue;
                }
            }

            $value = $data[$field->getSlug()] ?? null;

            if ($field->isRequired() && ($value === null || $value === '')) {
                $errors[$field->getSlug()] = "{$field->getName()} is required.";
                continue;
            }

            // Type-specific validation
            if ($value !== null && $value !== '') {
                switch ($field->getType()) {
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field->getSlug()] = "{$field->getName()} must be a valid email.";
                        }
                        break;
                    case 'url':
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field->getSlug()] = "{$field->getName()} must be a valid URL.";
                        }
                        break;
                }
            }
        }
        return $errors;
    }

    private function handleFileUpload(Field $field, ?array $existing = null): ?string
    {
        $file = $_FILES[$field->getSlug()] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            // Keep existing value if no new file uploaded
            return $existing[$field->getSlug()] ?? null;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $uploadDir = $publicPath . '/storage/cms/uploads/' . date('Y') . '/' . date('m');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $relativePath = 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $filename;

            // Create media record
            $media = new Media();
            $media->setFilename($filename);
            $media->setOriginalName($file['name']);
            $media->setPath($relativePath);
            $media->setMimeType($file['type']);
            $media->setSize($file['size']);
            $media->setUploadedBy(Auth::user()?->getId());
            $media->save();

            return $relativePath;
        }

        return $existing[$field->getSlug()] ?? null;
    }

    private function loadRelationData(array $fields): array
    {
        $relationData = [];

        foreach ($fields as $field) {
            if ($field->getType() === 'relation') {
                $options = $field->getOptions();
                $relSlug = $options['relation_collection'] ?? '';
                if (!empty($relSlug)) {
                    $relCollection = Collection::findOneBy(['slug' => $relSlug]);
                    if ($relCollection && $this->schema->tableExists($relCollection->getTableName())) {
                        $result = $this->schema->listEntries($relCollection->getTableName(), ['per_page' => 1000]);
                        $relationData[$field->getSlug()] = [
                            'collection' => $relCollection,
                            'entries' => $result['data'],
                        ];
                    }
                }
            }
        }

        return $relationData;
    }
}
