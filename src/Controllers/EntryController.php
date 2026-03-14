<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Field;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Models\Revision;
use ZephyrPHP\Cms\Models\SavedView;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\FileValidator;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;
use ZephyrPHP\Cms\Services\SeoService;
use ZephyrPHP\Cms\Services\NotificationService;
use ZephyrPHP\Cms\Services\TranslationService;
use ZephyrPHP\Cms\Services\WorkflowService;
use ZephyrPHP\Cms\Models\Language;
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

    /**
     * Check per-collection permission. Uses collection-level overrides if configured,
     * otherwise falls back to global entries.{action} permission.
     */
    private function requireCollectionPermission(string $action, Collection $collection): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::canForCollection($action, $collection)) {
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
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('view', $collection);

        $fields = $collection->getFields()->toArray();
        $listableFields = $collection->getListableFields();
        $searchableFields = $collection->getSearchableFields();

        // Load saved views for this collection
        $savedViews = SavedView::findBy(['collectionSlug' => $slug], ['sortOrder' => 'ASC']);
        $activeViewSlug = $this->input('view', '');
        $activeView = null;

        $options = [
            'page' => max(1, (int) ($this->input('page') ?? 1)),
            'per_page' => 20,
            'sort_by' => $this->input('sort_by', 'id'),
            'sort_dir' => $this->input('sort_dir', 'DESC'),
            'search' => $this->input('search'),
            'searchFields' => array_map(fn(Field $f) => $f->getSlug(), $searchableFields),
        ];

        // Apply saved view filters if one is selected
        if ($activeViewSlug) {
            foreach ($savedViews as $sv) {
                if ($sv->getSlug() === $activeViewSlug) {
                    $activeView = $sv;
                    break;
                }
            }
        } elseif (empty($this->input('search')) && empty($this->input('sort_by'))) {
            // Auto-apply default view if no explicit params
            foreach ($savedViews as $sv) {
                if ($sv->isDefault()) {
                    $activeView = $sv;
                    $activeViewSlug = $sv->getSlug();
                    break;
                }
            }
        }

        if ($activeView) {
            // Apply saved view's filters
            $filters = [];
            foreach ($activeView->getFilters() as $filter) {
                $field = $filter['field'] ?? '';
                $value = $filter['value'] ?? '';
                if ($field !== '' && $value !== '') {
                    $filters[$field] = $value;
                }
            }
            if (!empty($filters)) {
                $options['filters'] = $filters;
            }
            // Apply saved view's sort (unless user overrides via query params)
            if (empty($this->input('sort_by')) && $activeView->getSortBy()) {
                $options['sort_by'] = $activeView->getSortBy();
                $options['sort_dir'] = $activeView->getSortDir();
            }
        }

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
            'savedViews' => $savedViews,
            'activeViewSlug' => $activeViewSlug,
            'user' => Auth::user(),
        ]);
    }

    public function create(string $slug): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('create', $collection);

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
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('create', $collection);

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

        // SEO meta fields
        if ($collection->isSeoEnabled()) {
            $data['meta_title'] = trim($this->input('meta_title', ''));
            $data['meta_description'] = trim($this->input('meta_description', ''));
            $data['og_image'] = trim($this->input('og_image', ''));
            $data['canonical_url'] = trim($this->input('canonical_url', ''));
            $data['robots'] = trim($this->input('robots', 'index,follow'));
        }

        $entryId = $this->schema->insertEntry($collection->getTableName(), $data, $collection->isUuid());

        // Sync pivot relations
        $this->syncAllPivotRelations($collection->getTableName(), $fields, $entryId, $pivotData);

        // Record revision
        Revision::record($collection->getTableName(), $entryId, $data, 'create');

        // Invalidate collection cache
        cms_invalidate_cache($slug);

        ActivityLogger::log('created', 'entry', (string) $entryId, $data['title'] ?? $data['name'] ?? "#{$entryId}", ['collection' => $slug]);

        // Notify on publish
        if (($data['status'] ?? '') === 'published') {
            $entryTitle = $data['title'] ?? $data['name'] ?? "#{$entryId}";
            NotificationService::notifyAdmins(
                'entry_published',
                "Entry published: {$entryTitle}",
                "The entry \"{$entryTitle}\" in {$collection->getName()} has been published.",
                "/cms/collections/{$slug}/entries/{$entryId}",
                ['collection' => $slug, 'entry_id' => $entryId],
                [
                    'entry_title' => $entryTitle,
                    'collection_name' => $collection->getName(),
                    'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$slug}/entries/{$entryId}",
                ]
            );
        }

        $this->flash('success', 'Entry created successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function edit(string $slug, string $id): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('edit', $collection);

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
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('edit', $collection);

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

        // SEO meta fields
        if ($collection->isSeoEnabled()) {
            $data['meta_title'] = trim($this->input('meta_title', ''));
            $data['meta_description'] = trim($this->input('meta_description', ''));
            $data['og_image'] = trim($this->input('og_image', ''));
            $data['canonical_url'] = trim($this->input('canonical_url', ''));
            $data['robots'] = trim($this->input('robots', 'index,follow'));
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

        // Invalidate collection cache
        cms_invalidate_cache($slug);

        ActivityLogger::log('updated', 'entry', (string) $id, $data['title'] ?? $data['name'] ?? "#{$id}", ['collection' => $slug, 'changed' => $changedFields]);

        // Notify if just published (status changed to published)
        if (($data['status'] ?? '') === 'published' && ($entry['status'] ?? '') !== 'published') {
            $entryTitle = $data['title'] ?? $data['name'] ?? "#{$id}";
            NotificationService::notifyAdmins(
                'entry_published',
                "Entry published: {$entryTitle}",
                "The entry \"{$entryTitle}\" in {$collection->getName()} has been published.",
                "/cms/collections/{$slug}/entries/{$id}",
                ['collection' => $slug, 'entry_id' => $id],
                [
                    'entry_title' => $entryTitle,
                    'collection_name' => $collection->getName(),
                    'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$slug}/entries/{$id}",
                ]
            );
        }

        $this->flash('success', 'Entry updated successfully.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    public function destroy(string $slug, string $id): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('delete', $collection);

        // Record revision before deleting
        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if ($entry) {
            Revision::record($collection->getTableName(), $id, $entry, 'delete');
        }

        $this->schema->deleteEntry($collection->getTableName(), $id);

        // Invalidate collection cache
        cms_invalidate_cache($slug);

        ActivityLogger::log('deleted', 'entry', (string) $id, null, ['collection' => $slug]);

        $this->flash('success', 'Entry deleted.');
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * Return SEO score as JSON for an entry (AJAX endpoint).
     */
    public function seoAnalysis(string $slug, string $id): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) {
            $this->json(['score' => 0, 'issues' => ['Collection not found.']], 404);
            return;
        }

        if (!$collection->isSeoEnabled()) {
            $this->json(['score' => 0, 'issues' => ['SEO is not enabled for this collection.']]);
            return;
        }

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->json(['score' => 0, 'issues' => ['Entry not found.']], 404);
            return;
        }

        $result = SeoService::calculateSeoScore($entry, $collection);
        $this->json($result);
    }

    /**
     * Show side-by-side translation view for an entry.
     */
    public function translate(string $slug, string $id, string $locale): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('edit', $collection);

        if (!$collection->isTranslatable()) {
            $this->flash('errors', ['translation' => 'Translations are not enabled for this collection.']);
            $this->redirect("/cms/collections/{$slug}/entries/{$id}");
            return '';
        }

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['entry' => 'Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $language = Language::findOneBy(['code' => $locale, 'isActive' => true]);
        if (!$language) {
            $this->flash('errors', ['locale' => 'Language not found.']);
            $this->redirect("/cms/collections/{$slug}/entries/{$id}");
            return '';
        }

        $fields = $collection->getFields()->toArray();
        $translatableFields = TranslationService::getTranslatableFields($fields);
        $translations = TranslationService::getTranslations($collection->getTableName(), $id, $locale);
        $languages = TranslationService::getActiveLanguages();

        return $this->render('cms::entries/translate', [
            'collection' => $collection,
            'entry' => $entry,
            'locale' => $locale,
            'language' => $language,
            'fields' => $fields,
            'translatableFields' => $translatableFields,
            'translations' => $translations,
            'languages' => $languages,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Save translations for an entry.
     */
    public function saveTranslation(string $slug, string $id, string $locale): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('edit', $collection);

        if (!$collection->isTranslatable()) {
            $this->flash('errors', ['translation' => 'Translations are not enabled.']);
            $this->back();
            return;
        }

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['entry' => 'Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        if (!TranslationService::isValidLocale($locale)) {
            $this->flash('errors', ['locale' => 'Invalid locale.']);
            $this->back();
            return;
        }

        $fields = $collection->getFields()->toArray();
        $translatableFields = TranslationService::getTranslatableFields($fields);

        $fieldValues = [];
        foreach ($translatableFields as $field) {
            $value = $this->input('translation_' . $field->getSlug(), '');
            // Sanitize richtext fields
            if ($field->getType() === 'richtext' && !empty($value)) {
                $value = ContentApiController::sanitizeHtml($value);
            }
            $fieldValues[$field->getSlug()] = $value;
        }

        TranslationService::saveTranslations($collection->getTableName(), $id, $locale, $fieldValues);

        $this->flash('success', "Translations saved for {$locale}.");
        $this->redirect("/cms/collections/{$slug}/entries/{$id}/translate/{$locale}");
    }

    /**
     * Advance entry to next workflow stage.
     */
    public function advanceWorkflow(string $slug, string $id): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('edit', $collection);

        if (!$collection->isWorkflowEnabled()) {
            $this->flash('errors', ['workflow' => 'Workflow is not enabled.']);
            $this->back();
            return;
        }

        $userId = Auth::user()?->getId();
        $userName = Auth::user()?->getName();
        $comment = trim($this->input('workflow_comment', ''));

        $newStage = WorkflowService::advance($collection, $id, $userId, $userName, $comment ?: null);

        if ($newStage) {
            $entry = $this->schema->findEntry($collection->getTableName(), $id);
            $entryTitle = $entry['title'] ?? $entry['name'] ?? "#{$id}";

            ActivityLogger::log('workflow_advanced', 'entry', (string) $id, $entryTitle, [
                'collection' => $slug,
                'to_stage' => $newStage,
            ]);

            // Notify about stage change
            NotificationService::notifyAdmins(
                'entry_published',
                "Workflow: \"{$entryTitle}\" moved to {$newStage}",
                "{$userName} advanced \"{$entryTitle}\" to the \"{$newStage}\" stage in {$collection->getName()}.",
                "/cms/collections/{$slug}/entries/{$id}",
                ['collection' => $slug, 'entry_id' => $id, 'stage' => $newStage],
                [
                    'entry_title' => $entryTitle,
                    'collection_name' => $collection->getName(),
                    'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$slug}/entries/{$id}",
                ]
            );

            $this->flash('success', "Entry advanced to \"{$newStage}\".");
        } else {
            $this->flash('errors', ['workflow' => 'Cannot advance. You may not have permission or the entry is at the final stage.']);
        }

        $this->redirect("/cms/collections/{$slug}/entries/{$id}");
    }

    /**
     * Reject entry (send back to previous workflow stage).
     */
    public function rejectWorkflow(string $slug, string $id): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('edit', $collection);

        if (!$collection->isWorkflowEnabled()) {
            $this->flash('errors', ['workflow' => 'Workflow is not enabled.']);
            $this->back();
            return;
        }

        $userId = Auth::user()?->getId();
        $userName = Auth::user()?->getName();
        $comment = trim($this->input('workflow_comment', ''));

        $newStage = WorkflowService::reject($collection, $id, $userId, $userName, $comment ?: null);

        if ($newStage) {
            $entry = $this->schema->findEntry($collection->getTableName(), $id);
            $entryTitle = $entry['title'] ?? $entry['name'] ?? "#{$id}";

            ActivityLogger::log('workflow_rejected', 'entry', (string) $id, $entryTitle, [
                'collection' => $slug,
                'to_stage' => $newStage,
            ]);

            NotificationService::notifyAdmins(
                'entry_published',
                "Workflow: \"{$entryTitle}\" sent back to {$newStage}",
                "{$userName} rejected \"{$entryTitle}\" back to the \"{$newStage}\" stage in {$collection->getName()}.",
                "/cms/collections/{$slug}/entries/{$id}",
                ['collection' => $slug, 'entry_id' => $id, 'stage' => $newStage],
                [
                    'entry_title' => $entryTitle,
                    'collection_name' => $collection->getName(),
                    'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$slug}/entries/{$id}",
                ]
            );

            $this->flash('success', "Entry sent back to \"{$newStage}\".");
        } else {
            $this->flash('errors', ['workflow' => 'Cannot reject. You may not have permission or the entry is at the first stage.']);
        }

        $this->redirect("/cms/collections/{$slug}/entries/{$id}");
    }

    /**
     * Show revision history for an entry.
     */
    public function history(string $slug, string $id): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('view', $collection);

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
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('edit', $collection);

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
     * Compare two revisions side-by-side.
     */
    public function diff(string $slug, string $id): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('view', $collection);

        $entry = $this->schema->findEntry($collection->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['Entry not found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $revisionA = (int) $this->input('a', 0);
        $revisionB = (int) $this->input('b', 0);

        $revisions = Revision::getHistory($collection->getTableName(), $id);

        $dataA = null;
        $dataB = null;
        $metaA = null;
        $metaB = null;

        foreach ($revisions as $rev) {
            if ($rev->getId() === $revisionA) {
                $dataA = $rev->getData();
                $metaA = ['id' => $rev->getId(), 'action' => $rev->getAction(), 'created_at' => $rev->getCreatedAt()];
            }
            if ($rev->getId() === $revisionB) {
                $dataB = $rev->getData();
                $metaB = ['id' => $rev->getId(), 'action' => $rev->getAction(), 'created_at' => $rev->getCreatedAt()];
            }
        }

        // If no specific revisions selected, compare last two
        if ($dataA === null && $dataB === null && count($revisions) >= 2) {
            $dataA = $revisions[1]->getData();
            $metaA = ['id' => $revisions[1]->getId(), 'action' => $revisions[1]->getAction(), 'created_at' => $revisions[1]->getCreatedAt()];
            $dataB = $revisions[0]->getData();
            $metaB = ['id' => $revisions[0]->getId(), 'action' => $revisions[0]->getAction(), 'created_at' => $revisions[0]->getCreatedAt()];
            $revisionA = $revisions[1]->getId();
            $revisionB = $revisions[0]->getId();
        }

        // Build diff
        $diff = [];
        if ($dataA !== null && $dataB !== null) {
            $allKeys = array_unique(array_merge(array_keys($dataA), array_keys($dataB)));
            sort($allKeys);

            foreach ($allKeys as $key) {
                if (in_array($key, ['id', 'created_at', 'updated_at'], true)) {
                    continue;
                }
                $valA = $dataA[$key] ?? null;
                $valB = $dataB[$key] ?? null;
                $diff[] = [
                    'field' => $key,
                    'old' => $valA !== null ? (string) $valA : '',
                    'new' => $valB !== null ? (string) $valB : '',
                    'changed' => $valA !== $valB,
                ];
            }
        }

        $fields = $collection->getFields();

        return $this->render('cms::entries/diff', [
            'collection' => $collection,
            'entry' => $entry,
            'revisions' => $revisions,
            'revisionA' => $revisionA,
            'revisionB' => $revisionB,
            'metaA' => $metaA,
            'metaB' => $metaB,
            'diff' => $diff,
            'fields' => $fields,
            'user' => Auth::user(),
        ]);
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

        // Check per-collection permission based on the actual action
        $actionMap = [
            'delete' => 'delete',
            'publish' => 'publish',
            'unpublish' => 'publish',
        ];
        $requiredAction = $actionMap[$action] ?? 'delete';
        if (!PermissionService::canForCollection($requiredAction, $collection)) {
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

        // Invalidate collection cache
        cms_invalidate_cache($slug);

        ActivityLogger::log("bulk_{$action}", 'entry', null, "{$count} entries", ['collection' => $slug, 'count' => $count]);

        $this->flash('success', "{$count} entries {$actionLabel}.");
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * Export entries as CSV or JSON.
     */
    public function export(string $slug): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('view', $collection);

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
     * Step 1: Upload file and show preview with column mapping.
     */
    public function importPreview(string $slug): string
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return '';
        $this->requireCollectionPermission('create', $collection);

        $file = $_FILES['import_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('errors', ['import' => 'File upload failed.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        // Validate file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $this->flash('errors', ['import' => 'Only CSV and JSON files are supported.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) === 0) {
            $this->flash('errors', ['import' => 'File is empty.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        // Cap file size at 5MB for preview
        if (strlen($content) > 5 * 1024 * 1024) {
            $this->flash('errors', ['import' => 'File too large. Maximum 5MB.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        $entries = $this->parseImportFile($content, $ext);

        if (empty($entries)) {
            $this->flash('errors', ['import' => 'No valid entries found in the file.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return '';
        }

        // Get file columns from first entry
        $fileColumns = array_keys($entries[0]);

        // Get collection fields
        $collectionFields = [];
        foreach ($collection->getFields()->toArray() as $field) {
            $collectionFields[$field->getSlug()] = $field->getName();
        }
        if ($collection->isPublishable()) {
            $collectionFields['status'] = 'Status';
            $collectionFields['published_at'] = 'Published At';
        }
        if ($collection->hasSlug()) {
            $collectionFields['slug'] = 'Slug';
        }

        // Auto-map: match file columns to collection fields by slug/name
        $autoMapping = [];
        foreach ($fileColumns as $col) {
            $colLower = strtolower(str_replace([' ', '-'], '_', $col));
            if (isset($collectionFields[$colLower])) {
                $autoMapping[$col] = $colLower;
            } else {
                // Try matching by name
                foreach ($collectionFields as $fieldSlug => $fieldName) {
                    if (strtolower($fieldName) === strtolower($col)) {
                        $autoMapping[$col] = $fieldSlug;
                        break;
                    }
                }
            }
        }

        // Save file to temp for step 2
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $tempDir = $basePath . '/storage/tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . uniqid('import_') . '.' . $ext;
        file_put_contents($tempFile, $content);

        // Preview: first 10 rows
        $preview = array_slice($entries, 0, 10);

        return $this->render('cms::entries/import-preview', [
            'collection' => $collection,
            'fileColumns' => $fileColumns,
            'collectionFields' => $collectionFields,
            'autoMapping' => $autoMapping,
            'preview' => $preview,
            'totalRows' => count($entries),
            'tempFile' => basename($tempFile),
            'user' => Auth::user(),
        ]);
    }

    /**
     * Step 2: Execute import with column mapping.
     */
    public function import(string $slug): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('create', $collection);

        $tempFilename = $this->input('temp_file', '');

        // Validate temp filename — prevent directory traversal
        if ($tempFilename === '' || !preg_match('/^import_[a-f0-9]+\.(csv|json)$/', $tempFilename)) {
            $this->flash('errors', ['import' => 'Invalid import session. Please re-upload the file.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $tempFile = $basePath . '/storage/tmp/' . $tempFilename;

        if (!file_exists($tempFile)) {
            $this->flash('errors', ['import' => 'Import session expired. Please re-upload the file.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        $content = file_get_contents($tempFile);
        $ext = pathinfo($tempFile, PATHINFO_EXTENSION);
        $entries = $this->parseImportFile($content, $ext);

        // Clean up temp file
        @unlink($tempFile);

        if (empty($entries)) {
            $this->flash('errors', ['import' => 'No valid entries found.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        // Get column mapping from form
        $mapping = $this->input('mapping', []);
        if (!is_array($mapping) || empty(array_filter($mapping))) {
            $this->flash('errors', ['import' => 'No columns mapped. Please map at least one column.']);
            $this->redirect("/cms/collections/{$slug}/entries");
            return;
        }

        // Get valid field slugs
        $validFields = [];
        foreach ($collection->getFields()->toArray() as $field) {
            $validFields[] = $field->getSlug();
        }
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
        $skipped = 0;

        foreach ($entries as $entry) {
            $data = [];
            foreach ($mapping as $fileCol => $targetField) {
                if ($targetField === '' || $targetField === '_skip') continue;
                if (!in_array($targetField, $validFields)) continue;
                $data[$targetField] = $entry[$fileCol] ?? null;
            }

            if (empty($data)) {
                $skipped++;
                continue;
            }

            // Sanitize imported data
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

        $msg = "{$imported} entries imported successfully.";
        if ($skipped > 0) {
            $msg .= " {$skipped} rows skipped (no mapped data).";
        }

        $this->flash('success', $msg);
        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * Parse import file content into array of entries.
     */
    private function parseImportFile(string $content, string $ext): array
    {
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

        return $entries;
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

    // ========================================================================
    // SAVED VIEWS
    // ========================================================================

    /**
     * POST /cms/collections/{slug}/views — Create or update a saved view.
     */
    public function saveView(string $slug): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('view', $collection);

        $name = trim($this->input('view_name', ''));
        if (empty($name)) {
            $this->flash('errors', ['view_name' => 'View name is required.']);
            $this->back();
            return;
        }

        $viewSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        $viewSlug = trim($viewSlug, '-');
        if (empty($viewSlug)) {
            $viewSlug = 'view';
        }

        // Build filters from form input
        $filterFields = (array) ($this->input('filter_field') ?? []);
        $filterValues = (array) ($this->input('filter_value') ?? []);
        $filters = [];
        foreach ($filterFields as $i => $field) {
            $field = trim((string) $field);
            $value = trim((string) ($filterValues[$i] ?? ''));
            if ($field !== '' && $value !== '') {
                $filters[] = ['field' => $field, 'value' => $value];
            }
        }

        $sortBy = $this->input('view_sort_by', '') ?: null;
        $sortDir = $this->input('view_sort_dir', 'DESC');
        $isDefault = $this->boolean('view_is_default');

        // If setting as default, clear other defaults for this collection
        if ($isDefault) {
            $existing = SavedView::findBy(['collectionSlug' => $slug]);
            foreach ($existing as $sv) {
                if ($sv->isDefault()) {
                    $sv->setIsDefault(false);
                    $sv->save();
                }
            }
        }

        // Check if view with this slug already exists for this collection
        $existing = SavedView::findOneBy(['collectionSlug' => $slug, 'slug' => $viewSlug]);
        if ($existing) {
            $existing->setName($name);
            $existing->setFilters($filters);
            $existing->setSortBy($sortBy);
            $existing->setSortDir($sortDir);
            $existing->setIsDefault($isDefault);
            $existing->save();
            $this->flash('success', "View \"{$name}\" updated.");
        } else {
            $view = new SavedView();
            $view->setCollectionSlug($slug);
            $view->setName($name);
            $view->setSlug($viewSlug);
            $view->setFilters($filters);
            $view->setSortBy($sortBy);
            $view->setSortDir($sortDir);
            $view->setIsDefault($isDefault);
            $view->setCreatedBy(Auth::user()?->getId());
            $view->save();
            $this->flash('success', "View \"{$name}\" created.");
        }

        $this->redirect("/cms/collections/{$slug}/entries");
    }

    /**
     * POST /cms/collections/{slug}/views/{viewId}/delete — Delete a saved view.
     */
    public function deleteView(string $slug, int $viewId): void
    {
        $collection = $this->resolveCollection($slug);
        if (!$collection) return;
        $this->requireCollectionPermission('view', $collection);

        $view = SavedView::find($viewId);
        if ($view && $view->getCollectionSlug() === $slug) {
            $viewName = $view->getName();
            $view->delete();
            $this->flash('success', "View \"{$viewName}\" deleted.");
        }

        $this->redirect("/cms/collections/{$slug}/entries");
    }
}
