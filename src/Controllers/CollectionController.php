<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

class CollectionController extends Controller
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

    public function index(): string
    {
        $this->requirePermission('collections.view');

        $collections = Collection::findAll();

        $stats = [];
        foreach ($collections as $collection) {
            $stats[$collection->getSlug()] = $this->schema->countEntries($collection->getTableName());
        }

        return $this->render('cms::collections/index', [
            'collections' => $collections,
            'stats' => $stats,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('collections.create');

        return $this->render('cms::collections/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('collections.create');

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = $this->input('description', '');
        $isApiEnabled = $this->boolean('is_api_enabled');
        $isPublishable = $this->boolean('is_publishable');
        $hasSlug = $this->boolean('has_slug');
        $primaryKeyType = $this->input('primary_key_type', 'integer');

        // Auto-generate slug from name if empty
        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->generateSlug($slug);
        }

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Collection name is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Collection slug is required.';
        }

        // Check slug uniqueness
        if (empty($errors['slug'])) {
            $existing = Collection::findOneBy(['slug' => $slug]);
            if ($existing) {
                $errors['slug'] = 'A collection with this slug already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', [
                'name' => $name, 'slug' => $slug,
                'description' => $description,
            ]);
            $this->back();
            return;
        }

        $isSubmittable = $this->boolean('is_submittable');
        $urlPrefix = trim($this->input('url_prefix', ''));
        $itemsPerPage = (int) $this->input('items_per_page', 10);

        $collection = new Collection();
        $collection->setName($name);
        $collection->setSlug($slug);
        $collection->setDescription($description ?: null);
        $collection->setIsApiEnabled($isApiEnabled);
        $collection->setIsPublishable($isPublishable);
        $collection->setHasSlug($hasSlug);
        $collection->setPrimaryKeyType($primaryKeyType);
        $collection->setIsSubmittable($isSubmittable);
        $collection->setUrlPrefix($urlPrefix ?: null);
        $collection->setItemsPerPage($itemsPerPage > 0 ? $itemsPerPage : 10);
        $collection->setSeoEnabled($this->boolean('seo_enabled'));
        $collection->setIsTranslatable($this->boolean('is_translatable'));
        $collection->setCreatedBy(Auth::user()?->getId());
        $collection->save();

        // Create the dynamic table
        $this->schema->createCollectionTable($collection);

        // Add SEO columns if enabled
        if ($collection->isSeoEnabled()) {
            $this->schema->addSeoColumns($collection->getTableName());
        }

        ActivityLogger::log('created', 'collection', $collection->getSlug(), $name);

        $this->flash('success', "Collection \"{$name}\" created successfully.");
        $this->redirect("/cms/collections/{$slug}");
    }

    public function edit(string $slug): string
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return '';
        }

        $entryCount = $this->schema->countEntries($collection->getTableName());

        $fieldTypes = [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'richtext' => 'Rich Text',
            'number' => 'Number',
            'decimal' => 'Decimal',
            'boolean' => 'Boolean',
            'date' => 'Date',
            'datetime' => 'Date & Time',
            'email' => 'Email',
            'url' => 'URL',
            'select' => 'Select / Dropdown',
            'image' => 'Image',
            'file' => 'File',
            'slug' => 'Slug',
            'json' => 'JSON',
            'relation' => 'Relation',
        ];

        $allCollections = Collection::findAll();

        // Load roles for per-collection permissions tab
        $roles = $this->loadRoles();

        return $this->render('cms::collections/edit', [
            'collection' => $collection,
            'collections' => $allCollections,
            'fields' => $collection->getFields()->toArray(),
            'fieldTypes' => $fieldTypes,
            'entryCount' => $entryCount,
            'roles' => $roles,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return;
        }

        // Handle permissions save (separate form on the Permissions tab)
        if ($this->input('_save_permissions') === '1') {
            $this->saveCollectionPermissions($collection);
            return;
        }

        $name = trim($this->input('name', ''));
        $description = $this->input('description', '');
        $isApiEnabled = $this->boolean('is_api_enabled');
        $isPublishable = $this->boolean('is_publishable');
        $hasSlug = $this->boolean('has_slug');
        $slugSourceField = $this->input('slug_source_field', '');
        $primaryKeyType = $this->input('primary_key_type', $collection->getPrimaryKeyType());

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Collection name is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $oldHasSlug = $collection->hasSlug();
        $tableName = $collection->getTableName();
        $conn = $this->schema->getConnection();

        // Handle slug toggle
        if ($hasSlug && !$oldHasSlug) {
            // Enabling slug: add slug column to data table
            try {
                $conn->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `slug` VARCHAR(255) NULL DEFAULT NULL");
                $conn->executeStatement("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_{$tableName}_slug` (`slug`)");
            } catch (\Exception $e) {
                // Column may already exist
            }
        } elseif (!$hasSlug && $oldHasSlug) {
            // Disabling slug: remove slug column from data table
            try {
                $conn->executeStatement("ALTER TABLE `{$tableName}` DROP INDEX `uniq_{$tableName}_slug`");
                $conn->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `slug`");
            } catch (\Exception $e) {
                // Column or index may not exist
            }
            $slugSourceField = '';
        }

        $isSubmittable = $this->boolean('is_submittable');
        $urlPrefix = trim($this->input('url_prefix', ''));
        $itemsPerPage = (int) $this->input('items_per_page', 10);
        $apiRateLimit = max(0, (int) $this->input('api_rate_limit', 0));

        // Build submit settings from form inputs
        $submitSettings = null;
        if ($isSubmittable) {
            $submitSettings = [
                'success_message' => trim($this->input('submit_success_message', 'Thank you for your submission.')),
                'redirect_url' => trim($this->input('submit_redirect_url', '')),
                'submit_button_text' => trim($this->input('submit_button_text', 'Submit')),
                'email_notify' => $this->boolean('submit_email_notify'),
                'email_to' => trim($this->input('submit_email_to', '')),
                'honeypot_enabled' => $this->boolean('submit_honeypot_enabled'),
                'rate_limit_per_ip' => max(0, (int) $this->input('submit_rate_limit', 0)),
            ];
        }

        $collection->setName($name);
        $collection->setDescription($description ?: null);
        $collection->setIsApiEnabled($isApiEnabled);
        $collection->setIsPublishable($isPublishable);
        $collection->setHasSlug($hasSlug);
        $collection->setSlugSourceField($slugSourceField ?: null);
        $collection->setPrimaryKeyType($primaryKeyType);
        $collection->setIsSubmittable($isSubmittable);
        $collection->setSubmitSettings($submitSettings);
        $collection->setUrlPrefix($urlPrefix ?: null);
        $collection->setItemsPerPage($itemsPerPage > 0 ? $itemsPerPage : 10);
        $collection->setApiRateLimit($apiRateLimit);

        // Handle SEO toggle
        $seoEnabled = $this->boolean('seo_enabled');
        $oldSeoEnabled = $collection->isSeoEnabled();
        $collection->setSeoEnabled($seoEnabled);

        if ($seoEnabled && !$oldSeoEnabled) {
            $this->schema->addSeoColumns($tableName);
        } elseif (!$seoEnabled && $oldSeoEnabled) {
            $this->schema->removeSeoColumns($tableName);
        }

        $collection->setIsTranslatable($this->boolean('is_translatable'));

        // Handle workflow config save (separate form on Workflow tab)
        if ($this->input('_save_workflow') === '1') {
            $this->saveWorkflowConfig($collection);
            return;
        }

        $collection->setWorkflowEnabled($this->boolean('workflow_enabled'));

        $collection->save();

        ActivityLogger::log('updated', 'collection', $collection->getSlug(), $collection->getName());

        $this->flash('success', 'Collection updated successfully.');
        $this->back();
    }

    /**
     * Save workflow configuration from the Workflow tab.
     */
    private function saveWorkflowConfig(Collection $collection): void
    {
        $workflowEnabled = $this->boolean('workflow_enabled');
        $collection->setWorkflowEnabled($workflowEnabled);

        if ($workflowEnabled) {
            $stagesRaw = trim($this->input('workflow_stages', ''));
            if (!empty($stagesRaw)) {
                $stages = array_filter(array_map('trim', explode(',', $stagesRaw)));
                $stages = array_values(array_map(function ($s) {
                    return preg_replace('/[^a-z0-9_-]/', '', strtolower($s));
                }, $stages));

                if (count($stages) >= 2) {
                    $collection->setWorkflowStages($stages);
                }
            }

            // Parse reviewers: stage => [user_id, ...]
            $reviewers = [];
            foreach ($collection->getWorkflowStages() as $stage) {
                $ids = $this->input("reviewers_{$stage}", '');
                if (!empty($ids)) {
                    $reviewers[$stage] = array_map('intval', array_filter(explode(',', $ids)));
                }
            }
            $collection->setWorkflowReviewers(!empty($reviewers) ? $reviewers : null);
        }

        $collection->save();

        $this->flash('success', 'Workflow configuration saved.');
        $this->back();
    }

    public function destroy(string $slug): void
    {
        $this->requirePermission('collections.delete');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return;
        }

        $collectionName = $collection->getName();

        // Drop the dynamic table + all related pivot tables and FK constraints
        $this->schema->dropCollectionTableWithRelations($collection);

        // Delete the collection record (cascade deletes field records)
        $collection->delete();

        ActivityLogger::log('deleted', 'collection', $slug, $collectionName);

        $this->flash('success', "Collection \"{$collectionName}\" deleted.");
        $this->redirect('/cms/collections');
    }

    // ========================================================================
    // FIELD MANAGEMENT
    // ========================================================================

    public function addField(string $slug): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return;
        }

        $name = trim($this->input('field_name', ''));
        $fieldSlug = trim($this->input('field_slug', ''));
        $type = $this->input('field_type', 'text');
        $isRequired = $this->boolean('field_required');
        $isListable = $this->boolean('field_listable');
        $isSearchable = $this->boolean('field_searchable');
        $isSortable = $this->boolean('field_sortable');
        $isUnique = $this->boolean('field_unique');
        $defaultValue = $this->input('field_default', '');
        $optionsRaw = $this->input('field_options', '');

        if (empty($fieldSlug)) {
            $fieldSlug = $this->generateSlug($name);
        } else {
            $fieldSlug = $this->generateSlug($fieldSlug);
        }

        $errors = [];
        if (empty($name)) {
            $errors['field_name'] = 'Field name is required.';
        }
        if (empty($fieldSlug)) {
            $errors['field_slug'] = 'Field slug is required.';
        }

        // Check slug uniqueness within collection
        if (empty($errors['field_slug'])) {
            foreach ($collection->getFields() as $existingField) {
                if ($existingField->getSlug() === $fieldSlug) {
                    $errors['field_slug'] = 'A field with this slug already exists in this collection.';
                    break;
                }
            }
        }

        // Check reserved column names
        $reserved = ['id', 'slug', 'status', 'published_at', 'created_by', 'created_at', 'updated_at'];
        if (in_array($fieldSlug, $reserved)) {
            $errors['field_slug'] = "'{$fieldSlug}' is a reserved column name.";
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        // Parse options for select type
        $options = null;
        if ($type === 'select' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }
        if ($type === 'relation' && !empty($optionsRaw)) {
            $relationType = $this->input('field_relation_type', 'one_to_one');
            if (!in_array($relationType, ['one_to_one', 'one_to_many', 'many_to_many'])) {
                $relationType = 'one_to_one';
            }
            $displayField = trim($this->input('field_display_field', ''));
            $options = [
                'relation_collection' => trim($optionsRaw),
                'relation_type' => $relationType,
                'display_field' => $displayField ?: null,
            ];
        }
        // Parse file accept options for image/file types
        if (in_array($type, ['image', 'file'])) {
            $acceptPreset = $this->input('field_accept_preset', 'all');
            $validPresets = ['images', 'documents', 'media', 'all', 'custom'];
            if (!in_array($acceptPreset, $validPresets)) {
                $acceptPreset = 'all';
            }
            $options = $options ?? [];
            $options['accept_preset'] = $acceptPreset;

            if ($acceptPreset === 'custom') {
                $customMimes = $this->request->all()['field_accept_custom'] ?? [];
                if (is_array($customMimes)) {
                    $options['accept_custom'] = array_values(array_filter($customMimes));
                }
            }

            $maxFileSize = $this->input('field_max_file_size', '');
            if (!empty($maxFileSize) && is_numeric($maxFileSize)) {
                $options['max_file_size'] = (int) ((float) $maxFileSize * 1024 * 1024); // MB to bytes
            }
        }

        $maxOrder = 0;
        foreach ($collection->getFields() as $f) {
            if ($f->getSortOrder() > $maxOrder) {
                $maxOrder = $f->getSortOrder();
            }
        }

        $field = new Field();
        $field->setCollection($collection);
        $field->setName($name);
        $field->setSlug($fieldSlug);
        $field->setType($type);
        $field->setOptions($options);
        $field->setIsRequired($isRequired);
        $field->setIsUnique($isUnique);
        $field->setIsListable($isListable);
        $field->setIsSearchable($isSearchable);
        $field->setIsSortable($isSortable);
        $field->setDefaultValue($defaultValue ?: null);
        $field->setSortOrder($maxOrder + 1);
        $field->save();

        // Add column or pivot table to the dynamic table
        if ($type === 'relation' && $options) {
            $relationType = $options['relation_type'] ?? 'one_to_one';
            $relSlug = $options['relation_collection'] ?? '';
            $relCollection = !empty($relSlug) ? Collection::findOneBy(['slug' => $relSlug]) : null;

            if ($relationType === 'one_to_one') {
                // INT column + foreign key
                $this->schema->addColumn($collection->getTableName(), $field);
                if ($relCollection && $this->schema->tableExists($relCollection->getTableName())) {
                    $this->schema->addForeignKey(
                        $collection->getTableName(),
                        $field->getSlug(),
                        $relCollection->getTableName()
                    );
                }
            } else {
                // Pivot table for one-to-many / many-to-many
                if ($relCollection && $this->schema->tableExists($relCollection->getTableName())) {
                    $this->schema->createPivotTable(
                        $collection->getTableName(),
                        $relCollection->getTableName(),
                        $field->getSlug()
                    );
                }
            }
        } else {
            $this->schema->addColumn($collection->getTableName(), $field);
        }

        // Handle "Use as Slug" checkbox
        $useAsSlug = $this->boolean('use_as_slug');
        if ($useAsSlug && in_array($type, ['text', 'email', 'url']) && !$collection->hasSlug()) {
            $collection->setHasSlug(true);
            $collection->setSlugSourceField($fieldSlug);
            $collection->save();

            // Add slug column to the data table
            try {
                $tableName = $collection->getTableName();
                $conn = $this->schema->getConnection();
                $conn->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `slug` VARCHAR(255) NULL DEFAULT NULL");
                $conn->executeStatement("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_{$tableName}_slug` (`slug`)");
            } catch (\Exception $e) {
                // Column may already exist
            }
        }

        $this->flash('success', "Field \"{$name}\" added successfully.");
        $this->redirect("/cms/collections/{$slug}");
    }

    public function updateField(string $slug, int $id): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->redirect('/cms/collections');
            return;
        }

        $field = Field::find($id);
        if (!$field) {
            $this->flash('errors', ['field' => 'Field not found.']);
            $this->back();
            return;
        }

        $name = trim($this->input('field_name', ''));
        $type = $this->input('field_type', $field->getType());
        $isRequired = $this->boolean('field_required');
        $isListable = $this->boolean('field_listable');
        $isSearchable = $this->boolean('field_searchable');
        $isSortable = $this->boolean('field_sortable');
        $defaultValue = $this->input('field_default', '');
        $optionsRaw = $this->input('field_options', '');

        if (empty($name)) {
            $this->flash('errors', ['field_name' => 'Field name is required.']);
            $this->back();
            return;
        }

        $oldSlug = $field->getSlug();
        $oldType = $field->getType();

        // Parse options
        $options = null;
        if ($type === 'select' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }
        if ($type === 'relation' && !empty($optionsRaw)) {
            $relationType = $this->input('field_relation_type', 'one_to_one');
            if (!in_array($relationType, ['one_to_one', 'one_to_many', 'many_to_many'])) {
                $relationType = 'one_to_one';
            }
            $displayField = trim($this->input('field_display_field', ''));
            $options = [
                'relation_collection' => trim($optionsRaw),
                'relation_type' => $relationType,
                'display_field' => $displayField ?: null,
            ];
        }
        // Parse file accept options for image/file types
        if (in_array($type, ['image', 'file'])) {
            $acceptPreset = $this->input('field_accept_preset', 'all');
            $validPresets = ['images', 'documents', 'media', 'all', 'custom'];
            if (!in_array($acceptPreset, $validPresets)) {
                $acceptPreset = 'all';
            }
            $options = $options ?? [];
            $options['accept_preset'] = $acceptPreset;

            if ($acceptPreset === 'custom') {
                $customMimes = $this->request->all()['field_accept_custom'] ?? [];
                if (is_array($customMimes)) {
                    $options['accept_custom'] = array_values(array_filter($customMimes));
                }
            }

            $maxFileSize = $this->input('field_max_file_size', '');
            if (!empty($maxFileSize) && is_numeric($maxFileSize)) {
                $options['max_file_size'] = (int) ((float) $maxFileSize * 1024 * 1024);
            }
        }

        $field->setName($name);
        $field->setType($type);
        $field->setOptions($options);
        $field->setIsRequired($isRequired);
        $field->setIsListable($isListable);
        $field->setIsSearchable($isSearchable);
        $field->setIsSortable($isSortable);
        $field->setDefaultValue($defaultValue ?: null);
        $field->save();

        // Modify the column if type changed
        if ($oldType !== $type) {
            $this->schema->modifyColumn($collection->getTableName(), $field, $oldSlug);
        }

        $this->flash('success', "Field \"{$name}\" updated.");
        $this->redirect("/cms/collections/{$slug}");
    }

    public function deleteField(string $slug, int $id): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->redirect('/cms/collections');
            return;
        }

        $field = Field::find($id);
        if (!$field) {
            $this->flash('errors', ['field' => 'Field not found.']);
            $this->back();
            return;
        }

        $fieldName = $field->getName();
        $fieldSlug = $field->getSlug();
        $isSlugSource = $collection->hasSlug() && $collection->getSlugSourceField() === $fieldSlug;

        // If this field is the slug source, remove slug column and reset collection slug settings
        if ($isSlugSource) {
            try {
                $tableName = $collection->getTableName();
                $conn = $this->schema->getConnection();
                $conn->executeStatement("ALTER TABLE `{$tableName}` DROP INDEX `uniq_{$tableName}_slug`");
                $conn->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `slug`");
            } catch (\Exception $e) {
                // Index or column may not exist
            }
            $collection->setHasSlug(false);
            $collection->setSlugSourceField(null);
            $collection->save();
        }

        // Drop the column or pivot table from the dynamic table
        if ($field->getType() === 'relation') {
            $relationType = $field->getOptions()['relation_type'] ?? 'one_to_one';
            if ($relationType === 'one_to_one') {
                $this->schema->dropForeignKey($collection->getTableName(), $fieldSlug);
                $this->schema->dropColumn($collection->getTableName(), $fieldSlug);
            } else {
                $this->schema->dropPivotTable($collection->getTableName(), $fieldSlug);
            }
        } else {
            $this->schema->dropColumn($collection->getTableName(), $fieldSlug);
        }

        // Delete the field record
        $field->delete();

        $this->flash('success', "Field \"{$fieldName}\" deleted.");
        $this->redirect("/cms/collections/{$slug}");
    }

    /**
     * Return a collection's fields as JSON (for AJAX use in relation setup)
     */
    public function fieldsJson(string $slug): void
    {
        $this->requirePermission('collections.view');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            header('Content-Type: application/json');
            echo json_encode(['fields' => []]);
            return;
        }

        $fields = [];
        foreach ($collection->getFields() as $field) {
            $fields[] = [
                'slug' => $field->getSlug(),
                'name' => $field->getName(),
                'type' => $field->getType(),
            ];
        }

        header('Content-Type: application/json');
        echo json_encode(['fields' => $fields]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Save per-collection permissions from the Permissions tab form.
     */
    private function saveCollectionPermissions(Collection $collection): void
    {
        $enabled = $this->boolean('enable_custom_permissions');

        if (!$enabled) {
            $collection->setPermissions(null);
            $collection->save();
            $this->flash('success', 'Per-collection permissions disabled. Using global permissions.');
            $this->back();
            return;
        }

        $permsInput = $_POST['perms'] ?? [];
        $validActions = ['view', 'create', 'edit', 'delete', 'publish'];
        $permissions = [];

        foreach ($permsInput as $roleSlug => $actions) {
            $roleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $roleSlug));
            if (empty($roleSlug)) continue;

            $filtered = array_values(array_intersect((array) $actions, $validActions));
            if (!empty($filtered)) {
                $permissions[$roleSlug] = $filtered;
            }
        }

        $collection->setPermissions(!empty($permissions) ? $permissions : null);
        $collection->save();

        $this->flash('success', 'Per-collection permissions saved.');
        $this->back();
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
        $slug = trim($slug, '_');
        return $slug;
    }

    /**
     * Load all roles from the database for the permissions UI.
     */
    private function loadRoles(): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $sm = $conn->createSchemaManager();
            if (!$sm->tablesExist(['roles'])) {
                return [];
            }
            return $conn->createQueryBuilder()
                ->select('name', 'slug')
                ->from('roles')
                ->orderBy('name', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Exception $e) {
            return [];
        }
    }
}
