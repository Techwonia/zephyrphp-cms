<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Models\Revision;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\FileValidator;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Api\ContentApiController;

class EntryController extends Controller
{
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
    }

    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect('/login');
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
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
        $this->requirePermission('entries.view');

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

        // Resolve relation labels for list view
        $relationLabels = $this->resolveRelationLabels($listableFields, $entries['data']);

        return $this->render('cms::entries/index', [
            'collection' => $collection,
            'listableFields' => $listableFields,
            'entries' => $entries,
            'relationLabels' => $relationLabels,
            'search' => $options['search'] ?? '',
            'sortBy' => $options['sort_by'],
            'sortDir' => $options['sort_dir'],
            'user' => Auth::user(),
        ]);
    }

    public function create(string $slug): string
    {
        $this->requirePermission('entries.create');

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
        $this->requirePermission('entries.create');

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
            } elseif ($data['status'] === 'scheduled') {
                $scheduledAt = $this->input('scheduled_at');
                if (!empty($scheduledAt)) {
                    $data['scheduled_at'] = (new \DateTime($scheduledAt))->format('Y-m-d H:i:s');
                } else {
                    $data['status'] = 'draft';
                }
            }
        }

        // Auto-generate slug if collection has slug enabled
        if ($collection->hasSlug()) {
            $manualSlug = trim($this->input('slug', ''));
            if (!empty($manualSlug)) {
                $data['slug'] = $this->generateUniqueSlug($collection->getTableName(), $manualSlug);
            } else {
                $sourceField = $collection->getSlugSourceField();
                $sourceValue = $data[$sourceField] ?? $data['name'] ?? $data['title'] ?? '';
                $data['slug'] = $this->generateUniqueSlug($collection->getTableName(), $sourceValue);
            }
        }

        $data['created_by'] = Auth::user()?->getId();

        // Sanitize richtext fields to prevent XSS
        foreach ($fields as $field) {
            if ($field->getType() === 'richtext' && isset($data[$field->getSlug()])) {
                $data[$field->getSlug()] = ContentApiController::sanitizeHtml($data[$field->getSlug()]);
            }
        }

        $entryId = $this->schema->insertEntry($collection->getTableName(), $data, $collection->isUuid());

        // Sync pivot relations
        $this->syncAllPivotRelations($collection->getTableName(), $fields, $entryId, $pivotData);

        // Record revision
        Revision::record($collection->getTableName(), $entryId, $data, 'create');

        $this->flash('success', 'Entry created successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function edit(string $slug, string $id): string
    {
        $this->requirePermission('entries.edit');

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
        $this->requirePermission('entries.edit');

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
            } elseif ($newStatus === 'scheduled') {
                $scheduledAt = $this->input('scheduled_at');
                if (!empty($scheduledAt)) {
                    $data['scheduled_at'] = (new \DateTime($scheduledAt))->format('Y-m-d H:i:s');
                } else {
                    $data['status'] = $entry['status'] ?? 'draft';
                }
            }
        }

        // Handle slug on update
        if ($collection->hasSlug()) {
            $manualSlug = trim($this->input('slug', ''));
            if (!empty($manualSlug) && $manualSlug !== ($entry['slug'] ?? '')) {
                $data['slug'] = $this->generateUniqueSlug($collection->getTableName(), $manualSlug, $id);
            } elseif (empty($entry['slug'] ?? '')) {
                // Entry has no slug yet, generate one
                $sourceField = $collection->getSlugSourceField();
                $sourceValue = $data[$sourceField] ?? $data['name'] ?? $data['title'] ?? '';
                $data['slug'] = $this->generateUniqueSlug($collection->getTableName(), $sourceValue, $id);
            }
        }

        // Sanitize richtext fields to prevent XSS
        foreach ($fields as $field) {
            if ($field->getType() === 'richtext' && isset($data[$field->getSlug()])) {
                $data[$field->getSlug()] = ContentApiController::sanitizeHtml($data[$field->getSlug()]);
            }
        }

        // Determine changed fields for revision
        $changedFields = [];
        foreach ($data as $key => $val) {
            if (($entry[$key] ?? null) !== $val) {
                $changedFields[] = $key;
            }
        }

        $this->schema->updateEntry($collection->getTableName(), $id, $data);

        // Sync pivot relations
        $this->syncAllPivotRelations($collection->getTableName(), $fields, $id, $pivotData);

        // Record revision
        Revision::record($collection->getTableName(), $id, $data, 'update', $changedFields);

        $this->flash('success', 'Entry updated successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function destroy(string $slug, string $id): void
    {
        $this->requirePermission('entries.delete');

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        // Record revision before deleting
        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if ($entry) {
            Revision::record($collection->getTableName(), $id, $entry, 'delete');
        }

        $this->schema->deleteEntry($collection->getTableName(), $id);

        $this->flash('success', 'Entry deleted.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * Show revision history for an entry.
     */
    public function history(string $slug, string $id): string
    {
        $this->requirePermission('entries.view');

        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['entry' => 'Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $revisions = Revision::getHistory($collection->getTableName(), $id);

        return $this->render('cms::entries/history', [
            'collection' => $collection,
            'entry' => $entry,
            'revisions' => $revisions,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Restore an entry to a previous revision.
     */
    public function restore(string $slug, string $id, int $revisionId): void
    {
        $this->requirePermission('entries.edit');

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $revision = Revision::find($revisionId);
        if (!$revision || $revision->getEntryId() !== (string) $id) {
            $this->flash('errors', ['revision' => 'Revision not found.']);
            $this->back();
            return;
        }

        $data = $revision->getData();
        // Remove system fields that shouldn't be overwritten
        unset($data['id'], $data['created_at'], $data['created_by']);

        $this->schema->updateEntry($collection->getTableName(), $id, $data);

        // Record the restore as a new revision
        Revision::record($collection->getTableName(), $id, $data, 'update', ['_restored_from_revision' => $revisionId]);

        $this->flash('success', 'Entry restored to revision #' . $revisionId . '.');
        $this->redirect("/cms/collections/{$slug}/entries/{$id}");
    }

    /**
     * Bulk operations: delete, publish, unpublish selected entries.
     */
    public function bulk(string $slug): void
    {
        $this->requireCmsAccess();

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $action = $this->input('bulk_action', '');

        // Check permission based on the actual action
        $permissionMap = [
            'delete' => 'entries.delete',
            'publish' => 'entries.publish',
            'unpublish' => 'entries.publish',
        ];
        $requiredPermission = $permissionMap[$action] ?? 'entries.delete';
        if (!PermissionService::can($requiredPermission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
            return;
        }
        $ids = $this->input('selected_ids');

        if (empty($action) || empty($ids)) {
            $this->flash('errors', ['bulk' => 'No action or entries selected.']);
            $this->back();
            return;
        }

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        $ids = array_filter(array_map('trim', $ids));

        $count = 0;
        foreach ($ids as $id) {
            if ($action === 'delete') {
                $entry = $this->schema->findEntry($collection->getTableName(), $id);
                if ($entry) {
                    Revision::record($collection->getTableName(), $id, $entry, 'delete');
                    $this->schema->deleteEntry($collection->getTableName(), $id);
                    $count++;
                }
            } elseif ($action === 'publish' && $collection->isPublishable()) {
                $this->schema->updateEntry($collection->getTableName(), $id, [
                    'status' => 'published',
                    'published_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
                $count++;
            } elseif ($action === 'unpublish' && $collection->isPublishable()) {
                $this->schema->updateEntry($collection->getTableName(), $id, [
                    'status' => 'draft',
                ]);
                $count++;
            }
        }

        $actionLabel = match ($action) {
            'delete' => 'deleted',
            'publish' => 'published',
            'unpublish' => 'unpublished',
            default => 'processed',
        };

        $this->flash('success', "{$count} entries {$actionLabel}.");
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * Export entries as CSV or JSON.
     */
    public function export(string $slug): void
    {
        $this->requirePermission('entries.view');

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $format = $this->input('format', 'csv');
        $entries = $this->schema->listEntries($collection->getTableName(), ['per_page' => 10000])['data'];

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $slug . '_export.json"');
            echo json_encode(['collection' => $slug, 'data' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $slug . '_export.csv"');
            $output = fopen('php://output', 'w');

            if (!empty($entries)) {
                // Header row
                fputcsv($output, array_keys($entries[0]));
                // Data rows
                foreach ($entries as $entry) {
                    $row = array_map(function ($v) {
                        return is_array($v) ? json_encode($v) : $v;
                    }, $entry);
                    fputcsv($output, $row);
                }
            }

            fclose($output);
        }
        exit;
    }

    /**
     * Import entries from CSV or JSON.
     */
    public function import(string $slug): void
    {
        $this->requirePermission('entries.create');

        $collection = $this->resolveCollection($slug);
        if (!$collection) return;

        $file = $_FILES['import_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('errors', ['import' => 'File upload failed.']);
            $this->back();
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $content = file_get_contents($file['tmp_name']);
        $entries = [];

        if ($ext === 'json') {
            $decoded = json_decode($content, true);
            if (isset($decoded['data'])) {
                $entries = $decoded['data'];
            } elseif (is_array($decoded) && isset($decoded[0])) {
                $entries = $decoded;
            }
        } elseif ($ext === 'csv') {
            $lines = str_getcsv_lines($content);
            if (count($lines) > 1) {
                $headers = $lines[0];
                for ($i = 1; $i < count($lines); $i++) {
                    if (count($lines[$i]) === count($headers)) {
                        $entries[] = array_combine($headers, $lines[$i]);
                    }
                }
            }
        }

        if (empty($entries)) {
            $this->flash('errors', ['import' => 'No valid entries found in the file.']);
            $this->back();
            return;
        }

        // Get valid field slugs
        $validFields = [];
        foreach ($collection->getFields()->toArray() as $field) {
            $validFields[] = $field->getSlug();
        }
        // Include system fields
        if ($collection->isPublishable()) {
            $validFields = array_merge($validFields, ['status', 'published_at']);
        }
        if ($collection->hasSlug()) {
            $validFields[] = 'slug';
        }

        // Build field type map for sanitization
        $fieldTypeMap = [];
        foreach ($collection->getFields()->toArray() as $field) {
            $fieldTypeMap[$field->getSlug()] = $field->getType();
        }

        $imported = 0;
        foreach ($entries as $entry) {
            $data = [];
            foreach ($entry as $key => $value) {
                if (in_array($key, $validFields)) {
                    $data[$key] = $value;
                }
            }
            if (!empty($data)) {
                // Sanitize imported data: type coercion and XSS prevention
                foreach ($data as $key => &$value) {
                    $fieldType = $fieldTypeMap[$key] ?? null;
                    if ($fieldType === 'richtext' && is_string($value)) {
                        $value = ContentApiController::sanitizeHtml($value);
                    } elseif ($fieldType === 'boolean') {
                        $value = in_array(strtolower((string) $value), ['1', 'true', 'yes'], true) ? 1 : 0;
                    } elseif ($fieldType === 'number' && $value !== null && $value !== '') {
                        $value = (int) $value;
                    } elseif ($fieldType === 'decimal' && $value !== null && $value !== '') {
                        $value = (float) $value;
                    } elseif ($fieldType === 'email' && !empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $value = null;
                        }
                    } elseif ($fieldType === 'url' && !empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $value = null;
                        }
                    }
                }
                unset($value);

                $this->schema->insertEntry($collection->getTableName(), $data, $collection->isUuid());
                $imported++;
            }
        }

        $this->flash('success', "{$imported} entries imported successfully.");
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
                'image', 'file' => $this->handleFileOrMediaUpload($field, $existing),
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

    /**
     * Handle file upload or media library selection.
     * Checks for media library path first, then falls back to file upload.
     */
    private function handleFileOrMediaUpload(Field $field, ?array $existing = null): ?string
    {
        // Check for media library selection
        $mediaPath = $this->input($field->getSlug() . '_from_media');
        if (!empty($mediaPath)) {
            return $mediaPath;
        }

        return $this->handleFileUpload($field, $existing);
    }

    private function handleFileUpload(Field $field, ?array $existing = null): ?string
    {
        $file = $_FILES[$field->getSlug()] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return $existing[$field->getSlug()] ?? null;
        }

        // Validate using FileValidator with per-field options (accept_preset, accept_custom, max_file_size)
        $fieldOptions = $field->getOptions();
        $validation = FileValidator::validate($file, $fieldOptions);
        if (!$validation['valid']) {
            $this->flash('errors', [$field->getSlug() => $validation['error']]);
            return $existing[$field->getSlug()] ?? null;
        }

        $realMime = $validation['mime'];

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $uploadDir = $publicPath . '/storage/cms/uploads/' . date('Y') . '/' . date('m');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $relativePath = 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $filename;

            $media = new Media();
            $media->setFilename($filename);
            $media->setOriginalName($file['name']);
            $media->setPath($relativePath);
            $media->setMimeType($realMime);
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

    /**
     * Resolve relation field IDs to display labels for list view
     * Returns: ['field_slug' => [id => label, ...], ...]
     */
    private function resolveRelationLabels(array $listableFields, array $entries): array
    {
        $relationLabels = [];

        foreach ($listableFields as $field) {
            if ($field->getType() !== 'relation') {
                continue;
            }

            $options = $field->getOptions();
            $relSlug = $options['relation_collection'] ?? '';
            $displayField = $options['display_field'] ?? null;

            if (empty($relSlug)) {
                continue;
            }

            // Collect all IDs used in entries for this relation field
            $ids = [];
            foreach ($entries as $entry) {
                $val = $entry[$field->getSlug()] ?? null;
                if ($val !== null && $val !== '') {
                    $ids[] = $val;
                }
            }

            if (empty($ids)) {
                continue;
            }

            $ids = array_unique($ids);
            $relCollection = Collection::findOneBy(['slug' => $relSlug]);
            if (!$relCollection || !$this->schema->tableExists($relCollection->getTableName())) {
                continue;
            }

            // Fetch related entries by IDs
            $conn = $this->schema->getConnection();
            $qb = $conn->createQueryBuilder()
                ->select('*')
                ->from($relCollection->getTableName())
                ->where('id IN (:ids)')
                ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::STRING);

            $relEntries = $qb->executeQuery()->fetchAllAssociative();

            $labels = [];
            $systemCols = ['id', 'slug', 'status', 'published_at', 'created_by', 'created_at', 'updated_at'];
            foreach ($relEntries as $relEntry) {
                if ($displayField && isset($relEntry[$displayField])) {
                    $labels[$relEntry['id']] = $relEntry[$displayField];
                } else {
                    // Auto-detect: use first non-system field
                    $label = '';
                    foreach ($relEntry as $k => $v) {
                        if (!in_array($k, $systemCols) && $v !== null && $v !== '') {
                            $label = $v;
                            break;
                        }
                    }
                    $labels[$relEntry['id']] = $label ?: '#' . $relEntry['id'];
                }
            }

            $relationLabels[$field->getSlug()] = $labels;
        }

        return $relationLabels;
    }

    private function generateUniqueSlug(string $tableName, string $source, string|int|null $excludeId = null): string
    {
        $base = strtolower(trim($source));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');
        if (empty($base)) {
            $base = 'entry';
        }

        $slug = $base;
        $counter = 2;
        $conn = $this->schema->getConnection();

        while (true) {
            $qb = $conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($tableName)
                ->where('slug = :slug')
                ->setParameter('slug', $slug);

            if ($excludeId !== null) {
                $qb->andWhere('id != :id')->setParameter('id', $excludeId);
            }

            if ((int) $qb->executeQuery()->fetchOne() === 0) {
                break;
            }

            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
