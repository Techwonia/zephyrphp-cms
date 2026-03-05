<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\PageType;
use ZephyrPHP\Cms\Models\PageTypeField;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;

class PageController extends Controller
{
    private SchemaManager $schema;
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
        $this->themeManager = new ThemeManager();
    }

    private function requireAdmin(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!Auth::user()->hasRole('admin')) {
            $this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            $this->redirect('/cms');
        }
    }

    private function resolvePageType(string $ptSlug): ?PageType
    {
        $pageType = PageType::findOneBy(['slug' => $ptSlug]);
        if (!$pageType) {
            $this->flash('errors', ['page_type' => 'Page type not found.']);
            $this->redirect('/cms/pages');
            return null;
        }
        return $pageType;
    }

    public function index(string $ptSlug): string
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return '';

        $listableFields = $pageType->getListableFields();
        $searchableFields = $pageType->getSearchableFields();

        $searchFieldSlugs = array_map(fn(PageTypeField $f) => $f->getSlug(), $searchableFields);
        // Always search in title and slug too
        $searchFieldSlugs = array_unique(array_merge(['title', 'slug'], $searchFieldSlugs));

        $options = [
            'page' => max(1, (int) ($this->input('page') ?? 1)),
            'per_page' => 20,
            'sort_by' => $this->input('sort_by', 'id'),
            'sort_dir' => $this->input('sort_dir', 'DESC'),
            'search' => $this->input('search'),
            'searchFields' => $searchFieldSlugs,
        ];

        $entries = $this->schema->listEntries($pageType->getTableName(), $options);

        return $this->render('cms::pages/entries/index', [
            'pageType' => $pageType,
            'listableFields' => $listableFields,
            'entries' => $entries,
            'search' => $options['search'] ?? '',
            'sortBy' => $options['sort_by'],
            'sortDir' => $options['sort_dir'],
            'user' => Auth::user(),
        ]);
    }

    public function create(string $ptSlug): string
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return '';

        return $this->render('cms::pages/entries/create', [
            'pageType' => $pageType,
            'fields' => $pageType->getFields()->toArray(),
            'user' => Auth::user(),
        ]);
    }

    public function store(string $ptSlug): void
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return;

        $title = trim($this->input('title', ''));
        $slug = trim($this->input('slug', ''));
        $status = $this->input('status', 'draft');

        if (empty($slug)) {
            $slug = $this->generateSlug($title);
        } else {
            $slug = $this->generateSlug($slug);
        }

        $errors = [];
        if (empty($title)) {
            $errors['title'] = 'Title is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Slug is required.';
        }

        // Check slug uniqueness across ALL page type tables
        if (empty($errors['slug']) && $this->slugExists($slug)) {
            $errors['slug'] = 'A page with this slug already exists.';
        }

        // Build field data
        $fields = $pageType->getFields()->toArray();
        $data = $this->buildPageData($fields);
        $fieldErrors = $this->validatePageData($fields, $data);
        $errors = array_merge($errors, $fieldErrors);

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', array_merge(['title' => $title, 'slug' => $slug], $data));
            $this->back();
            return;
        }

        // Merge core page fields
        $data['title'] = $title;
        $data['slug'] = $slug;
        $data['status'] = $status;
        $data['created_by'] = Auth::user()?->getId();

        if ($status === 'published') {
            $data['published_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }

        // SEO fields
        if ($pageType->hasSeo()) {
            $data['seo_title'] = $this->input('seo_title') ?: null;
            $data['seo_description'] = $this->input('seo_description') ?: null;
            $data['seo_image'] = $this->handleSeoImageUpload();
        }

        $this->schema->insertEntry($pageType->getTableName(), $data);

        $this->flash('success', 'Page created successfully.');
        $this->redirect("/cms/pages/{$ptSlug}/list");
    }

    public function edit(string $ptSlug, string $id): string
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return '';

        $entry = $this->schema->findEntry($pageType->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['page' => 'Page not found.']);
            $this->redirect("/cms/pages/{$ptSlug}/list");
            return '';
        }

        return $this->render('cms::pages/entries/edit', [
            'pageType' => $pageType,
            'fields' => $pageType->getFields()->toArray(),
            'entry' => $entry,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $ptSlug, string $id): void
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return;

        $entry = $this->schema->findEntry($pageType->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['page' => 'Page not found.']);
            $this->redirect("/cms/pages/{$ptSlug}/list");
            return;
        }

        $title = trim($this->input('title', ''));
        $slug = trim($this->input('slug', ''));
        $status = $this->input('status', $entry['status'] ?? 'draft');

        $errors = [];
        if (empty($title)) {
            $errors['title'] = 'Title is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Slug is required.';
        }

        // Check slug uniqueness (exclude current page)
        if (empty($errors['slug']) && $this->slugExists($slug, $id, $pageType->getTableName())) {
            $errors['slug'] = 'A page with this slug already exists.';
        }

        $fields = $pageType->getFields()->toArray();
        $data = $this->buildPageData($fields, $entry);
        $fieldErrors = $this->validatePageData($fields, $data);
        $errors = array_merge($errors, $fieldErrors);

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $data['title'] = $title;
        $data['slug'] = $slug;
        $data['status'] = $status;

        if ($status === 'published' && ($entry['published_at'] ?? null) === null) {
            $data['published_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }

        if ($pageType->hasSeo()) {
            $data['seo_title'] = $this->input('seo_title') ?: null;
            $data['seo_description'] = $this->input('seo_description') ?: null;
            $seoImage = $this->handleSeoImageUpload();
            if ($seoImage !== null) {
                $data['seo_image'] = $seoImage;
            }
        }

        $this->schema->updateEntry($pageType->getTableName(), $id, $data);

        $this->flash('success', 'Page updated successfully.');
        $this->redirect("/cms/pages/{$ptSlug}/list");
    }

    public function destroy(string $ptSlug, string $id): void
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return;

        $this->schema->deleteEntry($pageType->getTableName(), $id);

        $this->flash('success', 'Page deleted.');
        $this->redirect("/cms/pages/{$ptSlug}/list");
    }

    // ========================================================================
    // PREVIEW
    // ========================================================================

    public function preview(string $ptSlug, string $id): string
    {
        $this->requireAdmin();

        $pageType = $this->resolvePageType($ptSlug);
        if (!$pageType) return '';

        $entry = $this->schema->findEntry($pageType->getTableName(), $id);
        if (!$entry) {
            $this->flash('errors', ['page' => 'Page not found.']);
            $this->redirect("/cms/pages/{$ptSlug}/list");
            return '';
        }

        $view = \ZephyrPHP\View\View::getInstance();
        $templateSlug = $pageType->getTemplate();

        // For detail pages (dynamic mode), try detail template first
        $templateName = $templateSlug;
        if ($pageType->isDynamic()) {
            $detailTemplate = $templateSlug . '_detail';
            if ($view->exists("theme::templates/{$detailTemplate}") || $view->exists("templates/{$detailTemplate}")) {
                $templateName = $detailTemplate;
            }
        }

        $data = [
            'page' => $entry,
            'pageType' => $pageType,
            'seo' => [
                'title' => '[PREVIEW] ' . ($entry['seo_title'] ?? $entry['title'] ?? ''),
                'description' => $entry['seo_description'] ?? '',
                'image' => $entry['seo_image'] ?? '',
            ],
        ];

        // Try theme template first
        if ($view->exists("theme::templates/{$templateName}")) {
            return $this->render("theme::templates/{$templateName}", $data);
        }

        // Fallback to default templates path
        if ($view->exists("templates/{$templateName}")) {
            return $this->render("templates/{$templateName}", $data);
        }

        // Basic preview when no template exists
        $html = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="UTF-8"><title>[PREVIEW] ' . htmlspecialchars($entry['title'] ?? '') . '</title>';
        $html .= '</head><body>';
        $html .= '<div style="max-width:800px;margin:2rem auto;padding:0 1rem;font-family:system-ui;">';
        $html .= '<div style="background:#fff3cd;border:1px solid #ffc107;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:0.9rem;">';
        $html .= 'PREVIEW MODE — This page is not published yet.</div>';
        $html .= '<h1>' . htmlspecialchars($entry['title'] ?? '') . '</h1>';

        foreach ($pageType->getFields() as $field) {
            $val = $entry[$field->getSlug()] ?? '';
            if ($val) {
                $html .= '<div style="margin:1rem 0;"><strong>' . htmlspecialchars($field->getName()) . ':</strong> ';
                if ($field->getType() === 'richtext') {
                    $html .= $val;
                } else {
                    $html .= htmlspecialchars((string) $val);
                }
                $html .= '</div>';
            }
        }

        $html .= '</div></body></html>';
        return $html;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function buildPageData(array $fields, ?array $existing = null): array
    {
        $data = [];
        foreach ($fields as $field) {
            $value = $this->input($field->getSlug());
            $data[$field->getSlug()] = match ($field->getType()) {
                'boolean' => $this->boolean($field->getSlug()) ? 1 : 0,
                'number' => $value !== null && $value !== '' ? (int) $value : null,
                'decimal' => $value !== null && $value !== '' ? (float) $value : null,
                'image', 'file' => $this->handleFieldFileUpload($field, $existing),
                default => $value !== '' ? $value : null,
            };
        }
        return $data;
    }

    private function validatePageData(array $fields, array $data): array
    {
        $errors = [];
        foreach ($fields as $field) {
            $value = $data[$field->getSlug()] ?? null;
            if ($field->isRequired() && ($value === null || $value === '')) {
                $errors[$field->getSlug()] = "{$field->getName()} is required.";
            }
        }
        return $errors;
    }

    private function slugExists(string $slug, ?string $excludeId = null, ?string $excludeTable = null): bool
    {
        $pageTypes = PageType::findAll();
        foreach ($pageTypes as $pt) {
            if (!$this->schema->tableExists($pt->getTableName())) {
                continue;
            }
            $conn = $this->schema->getConnection();
            $qb = $conn->createQueryBuilder()
                ->select('id')
                ->from($pt->getTableName())
                ->where('slug = :slug')
                ->setParameter('slug', $slug);

            if ($excludeId !== null && $excludeTable === $pt->getTableName()) {
                $qb->andWhere('id != :excludeId')->setParameter('excludeId', $excludeId);
            }

            $result = $qb->executeQuery()->fetchAssociative();
            if ($result) {
                return true;
            }
        }
        return false;
    }

    private function handleFieldFileUpload(PageTypeField $field, ?array $existing = null): ?string
    {
        $file = $_FILES[$field->getSlug()] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
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

    private function handleSeoImageUpload(): ?string
    {
        $file = $_FILES['seo_image'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return null;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $uploadDir = $publicPath . '/storage/cms/uploads/' . date('Y') . '/' . date('m');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'seo_' . uniqid() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            return 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $filename;
        }

        return null;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($name)));
        return trim($slug, '-');
    }
}
