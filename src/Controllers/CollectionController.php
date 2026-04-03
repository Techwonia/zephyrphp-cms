<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

class CollectionController extends Controller
{
    use CmsAccessTrait;

    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = SchemaManager::getInstance();
    }

    public function index(): string
    {
        $this->requirePermission('collections.view');

        $collections = Collection::findAll();

        $stats = [];
        foreach ($collections as $collection) {
            $stats[$collection->getSlug()] = EntryQuery::collection($collection->getSlug())->count();
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

        $tablePrefix = \ZephyrPHP\Config\Config::get('cms.content_prefix', 'app_');

        return $this->render('cms::collections/create', [
            'user' => Auth::user(),
            'tablePrefix' => $tablePrefix,
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('collections.create');

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = $this->input('description', '');
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

        // Check reserved slugs (system paths that would conflict with CMS routes)
        $reservedSlugs = [
            admin_path(), 'api', 'login', 'logout', 'register', 'setup',
            'storage', 'uploads', 'themes', 'plugins', 'assets',
            'sitemap', 'robots', 'feed', 'rss', 'search',
            'user', 'account', 'profile', 'settings', 'dashboard',
            'admin', 'panel', 'manage', 'console',
        ];
        if (in_array($slug, $reservedSlugs, true)) {
            $errors['slug'] = "'{$slug}' is a reserved system path and cannot be used as a collection slug.";
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
        $collection->setIsPublishable($isPublishable);
        $collection->setHasSlug($hasSlug);
        $collection->setPrimaryKeyType($primaryKeyType);
        $collection->setIsSubmittable($isSubmittable);
        $collection->setUrlPrefix($urlPrefix ?: null);
        $collection->setItemsPerPage($itemsPerPage > 0 ? $itemsPerPage : 10);
        $collection->setSeoEnabled($this->boolean('seo_enabled'));
        $collection->setIsTranslatable($this->boolean('is_translatable'));
        $collection->setHasHierarchy($this->boolean('has_hierarchy'));
        $collection->setHierarchyMaxDepth(max(0, (int) $this->input('hierarchy_max_depth', 0)));
        $collection->setDisplayField(trim($this->input('display_field', '')) ?: null);
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
        $this->redirect(admin_url("collections/{$slug}"));
    }

    public function edit(string $slug): string
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect(admin_url('collections'));
            return '';
        }

        $entryCount = EntryQuery::collection($collection->getSlug())->noCache()->count();

        $fieldTypes = [
            // Text
            'text' => 'Text',
            'textarea' => 'Textarea',
            'richtext' => 'Rich Text',
            'markdown' => 'Markdown',
            'slug' => 'Slug',
            'password' => 'Password',
            // Number
            'number' => 'Number',
            'decimal' => 'Decimal',
            'boolean' => 'Toggle',
            // Date & Time
            'date' => 'Date',
            'datetime' => 'Date & Time',
            // Contact
            'email' => 'Email',
            'url' => 'URL',
            'color' => 'Color',
            // Choice
            'select' => 'Dropdown',
            'checkbox_group' => 'Checkbox Group',
            'tags' => 'Tags',
            // Media
            'media' => 'Media',
            // Data
            'json' => 'JSON',
            'repeater' => 'Repeater',
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
            $this->redirect(admin_url('collections'));
            return;
        }

        // Handle permissions save (separate form on the Permissions tab)
        if ($this->input('_save_permissions') === '1') {
            $this->saveCollectionPermissions($collection);
            return;
        }

        $name = trim($this->input('name', ''));
        $description = $this->input('description', '');
        $isPublishable = $this->boolean('is_publishable');
        $hasSlug = $this->boolean('has_slug');

        // Auto-detect slug source from fields with type "slug"
        $slugSourceField = '';
        if ($hasSlug) {
            $fields = $collection->getFields();
            foreach ($fields as $field) {
                if ($field->getType() === 'slug') {
                    $slugSourceField = $field->getSlug();
                    break;
                }
            }
            // Keep existing source if no slug-type field found
            if (empty($slugSourceField)) {
                $slugSourceField = $collection->getSlugSourceField() ?? '';
            }
        }
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
                $safeTable = SchemaManager::validateIdentifier($tableName, 'table name');
                $safeIndex = SchemaManager::validateIdentifier("uniq_{$tableName}_slug", 'index name');
                $conn->executeStatement("ALTER TABLE `{$safeTable}` ADD COLUMN `slug` VARCHAR(255) NULL DEFAULT NULL");
                $conn->executeStatement("ALTER TABLE `{$safeTable}` ADD UNIQUE INDEX `{$safeIndex}` (`slug`)");
            } catch (\Exception $e) {
                // Column may already exist
            }
        } elseif (!$hasSlug && $oldHasSlug) {
            // Disabling slug: remove slug column from data table
            try {
                $safeTable = SchemaManager::validateIdentifier($tableName, 'table name');
                $safeIndex = SchemaManager::validateIdentifier("uniq_{$tableName}_slug", 'index name');
                $conn->executeStatement("ALTER TABLE `{$safeTable}` DROP INDEX `{$safeIndex}`");
                $conn->executeStatement("ALTER TABLE `{$safeTable}` DROP COLUMN `slug`");
            } catch (\Exception $e) {
                // Column or index may not exist
            }
            $slugSourceField = '';
        }

        $isSubmittable = $this->boolean('is_submittable');
        $urlPrefix = trim($this->input('url_prefix', ''));
        $itemsPerPage = (int) $this->input('items_per_page', 10);

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
        $collection->setIsPublishable($isPublishable);
        $collection->setHasSlug($hasSlug);
        $collection->setSlugSourceField($slugSourceField ?: null);
        $collection->setPrimaryKeyType($primaryKeyType);
        $collection->setIsSubmittable($isSubmittable);
        $collection->setSubmitSettings($submitSettings);
        $collection->setUrlPrefix($urlPrefix ?: null);
        $collection->setItemsPerPage($itemsPerPage > 0 ? $itemsPerPage : 10);

        // Handle hierarchy toggle
        $hasHierarchy = $this->boolean('has_hierarchy');
        $oldHasHierarchy = $collection->hasHierarchy();
        $collection->setHasHierarchy($hasHierarchy);
        $collection->setHierarchyMaxDepth(max(0, (int) $this->input('hierarchy_max_depth', 0)));
        $collection->setDisplayField(trim($this->input('display_field', '')) ?: null);

        if ($hasHierarchy && !$oldHasHierarchy) {
            $this->schema->addHierarchyColumn($tableName, $collection->isUuid());
        } elseif (!$hasHierarchy && $oldHasHierarchy) {
            $this->schema->removeHierarchyColumn($tableName);
        }

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
            $this->redirect(admin_url('collections'));
            return;
        }

        $collectionName = $collection->getName();

        // Drop the dynamic table + all related pivot tables and FK constraints
        $this->schema->dropCollectionTableWithRelations($collection);

        // Delete the collection record (cascade deletes field records)
        $collection->delete();

        ActivityLogger::log('deleted', 'collection', $slug, $collectionName);

        $this->flash('success', "Collection \"{$collectionName}\" deleted.");
        $this->redirect(admin_url('collections'));
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
            $this->redirect(admin_url('collections'));
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
        $reserved = ['id', 'slug', 'status', 'published_at', 'created_by', 'created_at', 'updated_at', 'parent_id', 'deleted_at'];
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
        if ($type === 'checkbox_group' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }
        if ($type === 'repeater') {
            $subFieldsRaw = $this->input('field_sub_fields', '');
            $subFields = json_decode($subFieldsRaw, true);
            if (is_array($subFields) && !empty($subFields)) {
                $options = $options ?? [];
                $options['sub_fields'] = $subFields;
            }
        }
        if ($type === 'relation' && !empty($optionsRaw)) {
            $relationType = $this->input('field_relation_type', 'one_to_one');
            if (!in_array($relationType, ['one_to_one', 'one_to_many', 'many_to_many'])) {
                $relationType = 'one_to_one';
            }
            // Validate relation collection exists
            $relationSlug = trim($optionsRaw);
            $relCollection = Collection::findOneBy(['slug' => $relationSlug]);
            if (!$relCollection) {
                $this->flash('errors', ['field_options' => 'The specified relation collection does not exist.']);
                $this->back();
                return;
            }
            $displayField = trim($this->input('field_display_field', ''));
            $options = [
                'relation_collection' => $relationSlug,
                'relation_type' => $relationType,
                'display_field' => $displayField ?: null,
            ];
        }
        // Parse file accept options for image/file/media types
        if (in_array($type, ['image', 'file', 'media'])) {
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

            // Multiple files support
            if ($this->boolean('field_multiple')) {
                $options['multiple'] = true;
                $maxFilesInput = $this->input('field_max_files', '10');
                $options['max_files'] = max(1, min(50, (int) $maxFilesInput));
            }
        }

        // Parse validation rules into options
        $options = $options ?? [];
        $validationKeys = ['min_length', 'max_length', 'min_value', 'max_value', 'pattern', 'pattern_message', 'custom_message'];
        foreach ($validationKeys as $key) {
            $val = trim($this->input('field_' . $key, ''));
            if ($val !== '') {
                $options[$key] = in_array($key, ['min_length', 'max_length']) ? (int) $val : $val;
                if (in_array($key, ['min_value', 'max_value']) && is_numeric($val)) {
                    $options[$key] = $val + 0; // cast to int or float as appropriate
                }
            }
        }

        // Parse DB-level column configuration
        $dbType = trim($this->input('field_db_type', ''));
        if ($dbType !== '') {
            $options['db_type'] = $dbType;
        }
        $dbLength = trim($this->input('field_db_length', ''));
        if ($dbLength !== '' && is_numeric($dbLength) && (int) $dbLength > 0) {
            $options['db_length'] = (int) $dbLength;
        }
        $dbIntType = trim($this->input('field_db_int_type', ''));
        if (in_array($dbIntType, ['INT', 'SMALLINT', 'BIGINT', 'TINYINT'], true)) {
            $options['db_int_type'] = $dbIntType;
        }
        $dbPrecision = trim($this->input('field_db_precision', ''));
        if ($dbPrecision !== '' && is_numeric($dbPrecision)) {
            $options['db_precision'] = max(1, min(65, (int) $dbPrecision));
        }
        $dbScale = trim($this->input('field_db_scale', ''));
        if ($dbScale !== '' && is_numeric($dbScale)) {
            $options['db_scale'] = max(0, min(30, (int) $dbScale));
        }

        // Parse field-level permissions
        $fieldPermissions = $this->parseFieldPermissions();
        if (!empty($fieldPermissions)) {
            $options['field_permissions'] = $fieldPermissions;
        }

        if (empty($options)) {
            $options = null;
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
        } elseif (in_array($type, ['image', 'file', 'media'])) {
            $isMultiple = !empty($options['multiple']);
            if ($isMultiple) {
                // Multiple: pivot table to cms_media
                $this->schema->createPivotTable(
                    $collection->getTableName(),
                    'cms_media',
                    $field->getSlug()
                );
            } else {
                // Single: INT column (media ID) + foreign key
                $this->schema->addColumn($collection->getTableName(), $field);
                if ($this->schema->tableExists('cms_media')) {
                    $this->schema->addForeignKey(
                        $collection->getTableName(),
                        $field->getSlug(),
                        'cms_media'
                    );
                }
            }
        } else {
            $this->schema->addColumn($collection->getTableName(), $field);
        }

        // Auto-set slug source when a "slug" type field is added
        if ($collection->hasSlug() && $type === 'slug') {
            $collection->setSlugSourceField($fieldSlug);
            $collection->save();
        }

        $this->flash('success', "Field \"{$name}\" added successfully.");
        $this->redirect(admin_url("collections/{$slug}"));
    }

    public function updateField(string $slug, int $id): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->redirect(admin_url('collections'));
            return;
        }

        $field = Field::find($id);
        if (!$field || $field->getCollection()->getId() !== $collection->getId()) {
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
        if ($type === 'checkbox_group' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }
        if ($type === 'repeater') {
            $subFieldsRaw = $this->input('field_sub_fields', '');
            $subFields = json_decode($subFieldsRaw, true);
            if (is_array($subFields) && !empty($subFields)) {
                $options = $options ?? [];
                $options['sub_fields'] = $subFields;
            }
        }
        if ($type === 'relation' && !empty($optionsRaw)) {
            $relationType = $this->input('field_relation_type', 'one_to_one');
            if (!in_array($relationType, ['one_to_one', 'one_to_many', 'many_to_many'])) {
                $relationType = 'one_to_one';
            }
            // Validate relation collection exists
            $relationSlug = trim($optionsRaw);
            $relCollection = Collection::findOneBy(['slug' => $relationSlug]);
            if (!$relCollection) {
                $this->flash('errors', ['field_options' => 'The specified relation collection does not exist.']);
                $this->back();
                return;
            }
            $displayField = trim($this->input('field_display_field', ''));
            $options = [
                'relation_collection' => $relationSlug,
                'relation_type' => $relationType,
                'display_field' => $displayField ?: null,
            ];
        }
        // Parse file accept options for image/file/media types
        if (in_array($type, ['image', 'file', 'media'])) {
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

            // Multiple files support
            if ($this->boolean('field_multiple')) {
                $options['multiple'] = true;
                $maxFilesInput = $this->input('field_max_files', '10');
                $options['max_files'] = max(1, min(50, (int) $maxFilesInput));
            }
        }

        // Parse validation rules into options
        $options = $options ?? [];
        $validationKeys = ['min_length', 'max_length', 'min_value', 'max_value', 'pattern', 'pattern_message', 'custom_message'];
        foreach ($validationKeys as $key) {
            $val = trim($this->input('field_' . $key, ''));
            if ($val !== '') {
                $options[$key] = in_array($key, ['min_length', 'max_length']) ? (int) $val : $val;
                if (in_array($key, ['min_value', 'max_value']) && is_numeric($val)) {
                    $options[$key] = $val + 0;
                }
            }
        }

        // Parse DB-level column configuration
        $dbType = trim($this->input('field_db_type', ''));
        if ($dbType !== '') {
            $options['db_type'] = $dbType;
        }
        $dbLength = trim($this->input('field_db_length', ''));
        if ($dbLength !== '' && is_numeric($dbLength) && (int) $dbLength > 0) {
            $options['db_length'] = (int) $dbLength;
        }
        $dbIntType = trim($this->input('field_db_int_type', ''));
        if (in_array($dbIntType, ['INT', 'SMALLINT', 'BIGINT', 'TINYINT'], true)) {
            $options['db_int_type'] = $dbIntType;
        }
        $dbPrecision = trim($this->input('field_db_precision', ''));
        if ($dbPrecision !== '' && is_numeric($dbPrecision)) {
            $options['db_precision'] = max(1, min(65, (int) $dbPrecision));
        }
        $dbScale = trim($this->input('field_db_scale', ''));
        if ($dbScale !== '' && is_numeric($dbScale)) {
            $options['db_scale'] = max(0, min(30, (int) $dbScale));
        }

        // Parse field-level permissions
        $fieldPermissions = $this->parseFieldPermissions();
        if (!empty($fieldPermissions)) {
            $options['field_permissions'] = $fieldPermissions;
        }

        if (empty($options)) {
            $options = null;
        }

        $field->setName($name);
        $field->setType($type);
        $field->setOptions($options);
        $isUnique = $this->boolean('field_unique');
        $oldIsUnique = $field->isUnique();
        $field->setIsUnique($isUnique);
        $field->setIsRequired($isRequired);
        $field->setIsListable($isListable);
        $field->setIsSearchable($isSearchable);
        $field->setIsSortable($isSortable);
        $field->setDefaultValue($defaultValue ?: null);
        $field->save();

        // Handle unique index changes
        if ($isUnique && !$oldIsUnique) {
            try {
                $this->schema->addUniqueIndex($collection->getTableName(), $field->getSlug());
            } catch (\Throwable $e) {
                $this->flash('warning', 'Unique constraint could not be added — duplicate values may exist.');
            }
        } elseif (!$isUnique && $oldIsUnique) {
            $this->schema->dropUniqueIndex($collection->getTableName(), $field->getSlug());
        }

        // Modify the column if type changed
        if ($oldType !== $type) {
            $this->schema->modifyColumn($collection->getTableName(), $field, $oldSlug);
        }

        $this->flash('success', "Field \"{$name}\" updated.");
        $this->redirect(admin_url("collections/{$slug}"));
    }

    public function deleteField(string $slug, int $id): void
    {
        $this->requirePermission('collections.edit');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->redirect(admin_url('collections'));
            return;
        }

        $field = Field::find($id);
        if (!$field || $field->getCollection()->getId() !== $collection->getId()) {
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
                $safeTable = SchemaManager::validateIdentifier($tableName, 'table name');
                $safeIndex = SchemaManager::validateIdentifier("uniq_{$tableName}_slug", 'index name');
                $conn = $this->schema->getConnection();
                $conn->executeStatement("ALTER TABLE `{$safeTable}` DROP INDEX `{$safeIndex}`");
                $conn->executeStatement("ALTER TABLE `{$safeTable}` DROP COLUMN `slug`");
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
        } elseif (in_array($field->getType(), ['image', 'file', 'media'])) {
            $isMultiple = !empty(($field->getOptions() ?? [])['multiple']);
            if ($isMultiple) {
                $this->schema->dropPivotTable($collection->getTableName(), $fieldSlug);
            } else {
                $this->schema->dropForeignKey($collection->getTableName(), $fieldSlug);
                $this->schema->dropColumn($collection->getTableName(), $fieldSlug);
            }
        } else {
            $this->schema->dropColumn($collection->getTableName(), $fieldSlug);
        }

        // Delete the field record
        $field->delete();

        $this->flash('success', "Field \"{$fieldName}\" deleted.");
        $this->redirect(admin_url("collections/{$slug}"));
    }

    /**
     * Return a single field's full data as JSON (for modal field editor).
     */
    public function fieldJson(string $slug, int $id): void
    {
        $this->requirePermission('collections.view');

        header('Content-Type: application/json');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            http_response_code(404);
            echo json_encode(['error' => 'Collection not found']);
            return;
        }

        $field = Field::find($id);
        if (!$field || $field->getCollection()->getId() !== $collection->getId()) {
            http_response_code(404);
            echo json_encode(['error' => 'Field not found']);
            return;
        }

        echo json_encode([
            'id' => $field->getId(),
            'name' => $field->getName(),
            'slug' => $field->getSlug(),
            'type' => $field->getType(),
            'options' => $field->getOptions(),
            'is_required' => $field->isRequired(),
            'is_unique' => $field->isUnique(),
            'is_listable' => $field->isListable(),
            'is_searchable' => $field->isSearchable(),
            'is_sortable' => $field->isSortable(),
            'default_value' => $field->getDefaultValue(),
            'sort_order' => $field->getSortOrder(),
        ]);
    }

    /**
     * Reorder fields via a JSON array of field IDs.
     */
    public function reorderFields(string $slug): void
    {
        $this->requirePermission('collections.edit');

        header('Content-Type: application/json');

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            http_response_code(404);
            echo json_encode(['error' => 'Collection not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['order']) || !is_array($input['order'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input. Expected {"order": [id1, id2, ...]}']);
            return;
        }

        $order = $input['order'];
        foreach ($order as $position => $fieldId) {
            $field = Field::find((int) $fieldId);
            if ($field && $field->getCollection()->getId() === $collection->getId()) {
                $field->setSortOrder($position);
                $field->save();
            }
        }

        echo json_encode(['success' => true]);
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

        $permsInput = $this->input('perms', []);
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
    /**
     * Parse field-level permission inputs from the form.
     *
     * Expects: field_perm_view[] and field_perm_edit[] arrays of role slugs.
     * Returns: ['view' => ['role1', ...], 'edit' => ['role1', ...]] or empty array.
     */
    private function parseFieldPermissions(): array
    {
        $fieldPermissions = [];

        $viewRoles = $this->input('field_perm_view', []);
        $editRoles = $this->input('field_perm_edit', []);

        if (is_array($viewRoles) && !empty($viewRoles)) {
            $viewRoles = array_filter($viewRoles, fn($r) => preg_match('/^[a-z0-9\-_]+$/', $r));
            if (!empty($viewRoles)) {
                $fieldPermissions['view'] = array_values($viewRoles);
            }
        }

        if (is_array($editRoles) && !empty($editRoles)) {
            $editRoles = array_filter($editRoles, fn($r) => preg_match('/^[a-z0-9\-_]+$/', $r));
            if (!empty($editRoles)) {
                $fieldPermissions['edit'] = array_values($editRoles);
            }
        }

        return $fieldPermissions;
    }

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
