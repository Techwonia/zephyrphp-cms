<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Services\SchemaManager;

class CollectionController extends Controller
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

    public function index(): string
    {
        $this->requireAdmin();

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
        $this->requireAdmin();

        return $this->render('cms::collections/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = $this->input('description', '');
        $isApiEnabled = $this->boolean('is_api_enabled');
        $isPublishable = $this->boolean('is_publishable');

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

        $collection = new Collection();
        $collection->setName($name);
        $collection->setSlug($slug);
        $collection->setDescription($description ?: null);
        $collection->setIsApiEnabled($isApiEnabled);
        $collection->setIsPublishable($isPublishable);
        $collection->setCreatedBy(Auth::user()?->getId());
        $collection->save();

        // Create the dynamic table
        $this->schema->createCollectionTable($collection);

        $this->flash('success', "Collection \"{$name}\" created successfully.");
        $this->redirect("/cms/collections/{$slug}");
    }

    public function edit(string $slug): string
    {
        $this->requireAdmin();

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

        return $this->render('cms::collections/edit', [
            'collection' => $collection,
            'collections' => $allCollections,
            'fields' => $collection->getFields()->toArray(),
            'fieldTypes' => $fieldTypes,
            'entryCount' => $entryCount,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug): void
    {
        $this->requireAdmin();

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return;
        }

        $name = trim($this->input('name', ''));
        $description = $this->input('description', '');
        $isApiEnabled = $this->boolean('is_api_enabled');
        $isPublishable = $this->boolean('is_publishable');

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Collection name is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $collection->setName($name);
        $collection->setDescription($description ?: null);
        $collection->setIsApiEnabled($isApiEnabled);
        $collection->setIsPublishable($isPublishable);
        $collection->save();

        $this->flash('success', 'Collection updated successfully.');
        $this->back();
    }

    public function destroy(string $slug): void
    {
        $this->requireAdmin();

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection) {
            $this->flash('errors', ['collection' => 'Collection not found.']);
            $this->redirect('/cms/collections');
            return;
        }

        // Drop the dynamic table
        $this->schema->dropCollectionTable($collection->getTableName());

        // Delete the collection (cascade deletes fields)
        $collection->delete();

        $this->flash('success', "Collection \"{$collection->getName()}\" deleted.");
        $this->redirect('/cms/collections');
    }

    // ========================================================================
    // FIELD MANAGEMENT
    // ========================================================================

    public function addField(string $slug): void
    {
        $this->requireAdmin();

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
        $reserved = ['id', 'status', 'published_at', 'created_by', 'created_at', 'updated_at'];
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
            $options = [
                'relation_collection' => trim($optionsRaw),
                'relation_type' => $relationType,
            ];
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

        // Add column to the dynamic table
        $this->schema->addColumn($collection->getTableName(), $field);

        $this->flash('success', "Field \"{$name}\" added successfully.");
        $this->redirect("/cms/collections/{$slug}");
    }

    public function updateField(string $slug, int $id): void
    {
        $this->requireAdmin();

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
            $options = [
                'relation_collection' => trim($optionsRaw),
                'relation_type' => $relationType,
            ];
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
        $this->requireAdmin();

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

        // Drop the column from the dynamic table
        $this->schema->dropColumn($collection->getTableName(), $fieldSlug);

        // Delete the field record
        $field->delete();

        $this->flash('success', "Field \"{$fieldName}\" deleted.");
        $this->redirect("/cms/collections/{$slug}");
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
        $slug = trim($slug, '_');
        return $slug;
    }
}
