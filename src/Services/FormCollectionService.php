<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormField;

/**
 * Syncs a Form Builder form with a CMS Collection so that
 * submissions are stored as real collection entries (one column per field).
 */
class FormCollectionService
{
    private SchemaManager $schema;

    public function __construct()
    {
        $this->schema = new SchemaManager();
    }

    /**
     * Ensure a CMS collection exists for this form and its fields are in sync.
     * Creates the collection + table if missing; adds/removes columns as needed.
     */
    public function sync(Form $form): Collection
    {
        $collectionSlug = 'form_' . $form->getSlug();
        $collection = Collection::findOneBy(['slug' => $collectionSlug]);

        if (!$collection) {
            $collection = $this->createCollection($form, $collectionSlug);
        }

        $this->syncFields($form, $collection);

        return $collection;
    }

    /**
     * Create a new CMS collection for this form.
     */
    private function createCollection(Form $form, string $slug): Collection
    {
        $collection = new Collection();
        $collection->setName($form->getName() . ' Submissions');
        $collection->setSlug($slug);
        $collection->setDescription('Auto-created collection for form: ' . $form->getName());
        $collection->setIsPublishable(false);
        $collection->setIsApiEnabled(false);
        $collection->setHasSlug(false);
        $collection->save();

        // Create the physical table with no fields yet (will be added in syncFields)
        $this->schema->createCollectionTable($collection, []);

        // Add meta columns for submission tracking
        $conn = $this->schema->getConnection();
        $tableName = $collection->getTableName();
        $conn->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `_ip` VARCHAR(45) NULL");
        $conn->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `_user_agent` VARCHAR(500) NULL");
        $conn->executeStatement("ALTER TABLE `{$tableName}` ADD COLUMN `_status` VARCHAR(20) NOT NULL DEFAULT 'completed'");

        return $collection;
    }

    /**
     * Sync form fields → collection fields + DB columns.
     */
    private function syncFields(Form $form, Collection $collection): void
    {
        $formFields = $form->getSubmittableFields();
        $tableName = $collection->getTableName();

        // Get existing collection fields keyed by slug
        $existingFields = [];
        foreach ($collection->getFields() as $field) {
            $existingFields[$field->getSlug()] = $field;
        }

        // Track which slugs are still in the form
        $activeSlugs = [];

        foreach ($formFields as $formField) {
            $slug = $formField->getSlug();
            $activeSlugs[] = $slug;
            $collectionType = $this->mapFieldType($formField->getType());

            if (isset($existingFields[$slug])) {
                // Field exists — update type if changed
                $existing = $existingFields[$slug];
                if ($existing->getType() !== $collectionType) {
                    $existing->setType($collectionType);
                    $existing->setName($formField->getLabel());
                    $existing->save();
                    $this->schema->modifyColumn($tableName, $existing);
                }
            } else {
                // New field — create collection field + DB column
                $field = new Field();
                $field->setCollection($collection);
                $field->setName($formField->getLabel());
                $field->setSlug($slug);
                $field->setType($collectionType);
                $field->setIsRequired($formField->isRequired());
                $field->setIsListable(true);
                $field->setIsSearchable(in_array($collectionType, ['text', 'email']));
                $field->setSortOrder($formField->getSortOrder());

                // Map choices for select fields
                if (in_array($formField->getType(), ['select', 'radio'])) {
                    $choices = $formField->getChoices();
                    if (!empty($choices)) {
                        $field->setOptions([
                            'choices' => array_map(fn($c) => [
                                'label' => $c['label'] ?? $c['value'] ?? '',
                                'value' => $c['value'] ?? '',
                            ], $choices),
                        ]);
                    }
                }

                $field->save();

                // Add the physical column
                $this->schema->addColumn($tableName, $field);
            }
        }

        // Remove fields that are no longer in the form
        foreach ($existingFields as $slug => $field) {
            if (!in_array($slug, $activeSlugs)) {
                // Drop column from table
                try {
                    $conn = $this->schema->getConnection();
                    $conn->executeStatement("ALTER TABLE `{$tableName}` DROP COLUMN `{$slug}`");
                } catch (\Exception $e) {
                    // Column may not exist, ignore
                }
                $field->delete();
            }
        }
    }

    /**
     * Map form field type to CMS collection field type.
     */
    private function mapFieldType(string $formType): string
    {
        return match ($formType) {
            'email' => 'email',
            'url' => 'url',
            'textarea' => 'textarea',
            'number', 'range' => 'number',
            'date' => 'date',
            'checkbox' => 'text',       // Stored as comma-separated or single value
            'select', 'radio' => 'select',
            'file' => 'file',
            'hidden', 'password', 'color' => 'text',
            'phone' => 'text',
            default => 'text',          // text, etc.
        };
    }

    /**
     * Get the collection table name for a form.
     */
    public static function getTableName(Form $form): string
    {
        return 'cms_form_' . $form->getSlug();
    }

    /**
     * Get the Collection model for a form (if it exists).
     */
    public static function getCollection(Form $form): ?Collection
    {
        return Collection::findOneBy(['slug' => 'form_' . $form->getSlug()]);
    }
}
