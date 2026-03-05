<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\PageType;
use ZephyrPHP\Cms\Models\PageTypeField;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;

class PageTypeController extends Controller
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

    public function index(): string
    {
        $this->requireAdmin();

        $pageTypes = PageType::findAll();

        $stats = [];
        foreach ($pageTypes as $pt) {
            if ($this->schema->tableExists($pt->getTableName())) {
                $stats[$pt->getSlug()] = $this->schema->countEntries($pt->getTableName());
            } else {
                $stats[$pt->getSlug()] = 0;
            }
        }

        return $this->render('cms::pages/index', [
            'pageTypes' => $pageTypes,
            'stats' => $stats,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requireAdmin();

        return $this->render('cms::pages/types/create', [
            'user' => Auth::user(),
            'layouts' => $this->themeManager->getLayoutNames(),
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $template = trim($this->input('template', ''));
        $description = $this->input('description', '');
        $hasSeo = $this->boolean('has_seo');
        $isPublishable = $this->boolean('is_publishable');
        $pageMode = $this->input('page_mode', 'static');
        $layout = $this->input('layout', 'base');
        $urlPrefix = trim($this->input('url_prefix', ''));
        // Sanitize: strip trailing slashes, reject root-only prefix
        if ($urlPrefix !== '') {
            $urlPrefix = '/' . trim($urlPrefix, '/');
            if ($urlPrefix === '/') {
                $urlPrefix = '';
            }
        }
        $itemsPerPage = (int) ($this->input('items_per_page', '10') ?: 10);

        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->generateSlug($slug);
        }

        // Auto-set template from slug if empty
        if (empty($template)) {
            $template = $slug;
        }

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Page type name is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Page type slug is required.';
        }

        if (empty($errors['slug'])) {
            $existing = PageType::findOneBy(['slug' => $slug]);
            if ($existing) {
                $errors['slug'] = 'A page type with this slug already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', [
                'name' => $name, 'slug' => $slug,
                'template' => $template, 'description' => $description,
                'page_mode' => $pageMode, 'layout' => $layout,
                'url_prefix' => $urlPrefix, 'items_per_page' => $itemsPerPage,
            ]);
            $this->back();
            return;
        }

        $pageType = new PageType();
        $pageType->setName($name);
        $pageType->setSlug($slug);
        $pageType->setTemplate($template);
        $pageType->setDescription($description ?: null);
        $pageType->setHasSeo($hasSeo);
        $pageType->setIsPublishable($isPublishable);
        $pageType->setCreatedBy(Auth::user()?->getId());
        $pageType->setPageMode($pageMode);
        $pageType->setLayout($layout);
        $pageType->setUrlPrefix($urlPrefix ?: null);
        $pageType->setItemsPerPage($itemsPerPage);
        $pageType->save();

        // Create the dynamic table for pages of this type
        $this->createPageTypeTable($pageType);

        // Auto-generate template files
        $this->generateTemplateFiles($pageType);

        $this->flash('success', "Page type \"{$name}\" created successfully.");
        $this->redirect("/cms/pages/types/{$slug}");
    }

    public function edit(string $slug): string
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->flash('errors', ['page_type' => 'Page type not found.']);
            $this->redirect('/cms/pages');
            return '';
        }

        $pageCount = 0;
        if ($this->schema->tableExists($pageType->getTableName())) {
            $pageCount = $this->schema->countEntries($pageType->getTableName());
        }

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
            'json' => 'JSON',
        ];

        // Read template content for the code editor
        $templateContent = $this->themeManager->readTemplate($pageType->getTemplate() . '.twig') ?? '';
        $detailTemplateContent = $this->themeManager->readTemplate($pageType->getTemplate() . '_detail.twig') ?? '';

        // Block snippets for the snippet library
        $snippets = $this->getBlockSnippets();

        return $this->render('cms::pages/types/edit', [
            'pageType' => $pageType,
            'fields' => $pageType->getFields()->toArray(),
            'fieldTypes' => $fieldTypes,
            'pageCount' => $pageCount,
            'user' => Auth::user(),
            'layouts' => $this->themeManager->getLayoutNames(),
            'templateContent' => $templateContent,
            'detailTemplateContent' => $detailTemplateContent,
            'snippets' => $snippets,
        ]);
    }

    public function update(string $slug): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->flash('errors', ['page_type' => 'Page type not found.']);
            $this->redirect('/cms/pages');
            return;
        }

        $name = trim($this->input('name', ''));
        $template = trim($this->input('template', ''));
        $description = $this->input('description', '');
        $hasSeo = $this->boolean('has_seo');
        $isPublishable = $this->boolean('is_publishable');
        $pageMode = $this->input('page_mode', $pageType->getPageMode());
        $layout = $this->input('layout', $pageType->getLayout());
        $urlPrefix = trim($this->input('url_prefix', ''));
        // Sanitize: strip trailing slashes, reject root-only prefix
        if ($urlPrefix !== '') {
            $urlPrefix = '/' . trim($urlPrefix, '/');
            if ($urlPrefix === '/') {
                $urlPrefix = '';
            }
        }
        $itemsPerPage = (int) ($this->input('items_per_page', (string) $pageType->getItemsPerPage()) ?: 10);

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Page type name is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        // Auto-set template from slug if empty
        if (empty($template)) {
            $template = $pageType->getSlug();
        }

        $pageType->setName($name);
        $pageType->setTemplate($template);
        $pageType->setDescription($description ?: null);
        $pageType->setHasSeo($hasSeo);
        $pageType->setIsPublishable($isPublishable);
        $pageType->setPageMode($pageMode);
        $pageType->setLayout($layout);
        $pageType->setUrlPrefix($urlPrefix ?: null);
        $pageType->setItemsPerPage($itemsPerPage);
        $pageType->save();

        $this->flash('success', 'Page type updated successfully.');
        $this->back();
    }

    public function destroy(string $slug): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->flash('errors', ['page_type' => 'Page type not found.']);
            $this->redirect('/cms/pages');
            return;
        }

        $name = $pageType->getName();

        // Drop the dynamic table
        if ($this->schema->tableExists($pageType->getTableName())) {
            $this->schema->dropTable($pageType->getTableName());
        }

        $pageType->delete();

        $this->flash('success', "Page type \"{$name}\" deleted.");
        $this->redirect('/cms/pages');
    }

    /**
     * AJAX: Save template content
     */
    public function saveTemplate(string $slug): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            http_response_code(404);
            echo json_encode(['error' => 'Page type not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $templateType = $input['type'] ?? 'page'; // 'page' or 'detail'
        $content = $input['content'] ?? '';

        $filename = $pageType->getTemplate();
        if ($templateType === 'detail') {
            $filename .= '_detail';
        }
        $filename .= '.twig';

        $success = $this->themeManager->writeTemplate($filename, $content);

        header('Content-Type: application/json');
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Template saved.']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save template.']);
        }
    }

    // ========================================================================
    // FIELD MANAGEMENT
    // ========================================================================

    public function addField(string $slug): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->flash('errors', ['page_type' => 'Page type not found.']);
            $this->redirect('/cms/pages');
            return;
        }

        $name = trim($this->input('field_name', ''));
        $fieldSlug = trim($this->input('field_slug', ''));
        $type = $this->input('field_type', 'text');
        $isRequired = $this->boolean('field_required');
        $isListable = $this->boolean('field_listable');
        $isSearchable = $this->boolean('field_searchable');
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

        // Check slug uniqueness
        if (empty($errors['field_slug'])) {
            foreach ($pageType->getFields() as $existingField) {
                if ($existingField->getSlug() === $fieldSlug) {
                    $errors['field_slug'] = 'A field with this slug already exists.';
                    break;
                }
            }
        }

        // Check reserved columns
        $reserved = ['id', 'title', 'slug', 'seo_title', 'seo_description', 'seo_image', 'status', 'published_at', 'created_by', 'created_at', 'updated_at'];
        if (in_array($fieldSlug, $reserved)) {
            $errors['field_slug'] = "'{$fieldSlug}' is a reserved column name.";
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $options = null;
        if ($type === 'select' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }

        $maxOrder = 0;
        foreach ($pageType->getFields() as $f) {
            if ($f->getSortOrder() > $maxOrder) {
                $maxOrder = $f->getSortOrder();
            }
        }

        $field = new PageTypeField();
        $field->setPageType($pageType);
        $field->setName($name);
        $field->setSlug($fieldSlug);
        $field->setType($type);
        $field->setOptions($options);
        $field->setIsRequired($isRequired);
        $field->setIsListable($isListable);
        $field->setIsSearchable($isSearchable);
        $field->setDefaultValue($defaultValue ?: null);
        $field->setSortOrder($maxOrder + 1);
        $field->save();

        // Add column to the dynamic table
        $this->addPageTypeColumn($pageType->getTableName(), $field);

        $this->flash('success', "Field \"{$name}\" added successfully.");
        $this->redirect("/cms/pages/types/{$slug}");
    }

    public function updateField(string $slug, int $id): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->redirect('/cms/pages');
            return;
        }

        $field = PageTypeField::find($id);
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
        $defaultValue = $this->input('field_default', '');
        $optionsRaw = $this->input('field_options', '');

        if (empty($name)) {
            $this->flash('errors', ['field_name' => 'Field name is required.']);
            $this->back();
            return;
        }

        $options = null;
        if ($type === 'select' && !empty($optionsRaw)) {
            $choices = array_map('trim', explode("\n", $optionsRaw));
            $choices = array_filter($choices);
            $options = ['choices' => array_values($choices)];
        }

        $oldType = $field->getType();
        $oldSlug = $field->getSlug();

        $field->setName($name);
        $field->setType($type);
        $field->setOptions($options);
        $field->setIsRequired($isRequired);
        $field->setIsListable($isListable);
        $field->setIsSearchable($isSearchable);
        $field->setDefaultValue($defaultValue ?: null);
        $field->save();

        if ($oldType !== $type) {
            $this->schema->modifyColumn($pageType->getTableName(), $field, $oldSlug);
        }

        $this->flash('success', "Field \"{$name}\" updated.");
        $this->redirect("/cms/pages/types/{$slug}");
    }

    public function deleteField(string $slug, int $id): void
    {
        $this->requireAdmin();

        $pageType = PageType::findOneBy(['slug' => $slug]);
        if (!$pageType) {
            $this->redirect('/cms/pages');
            return;
        }

        $field = PageTypeField::find($id);
        if (!$field) {
            $this->flash('errors', ['field' => 'Field not found.']);
            $this->back();
            return;
        }

        $fieldName = $field->getName();

        $this->schema->dropColumn($pageType->getTableName(), $field->getSlug());
        $field->delete();

        $this->flash('success', "Field \"{$fieldName}\" deleted.");
        $this->redirect("/cms/pages/types/{$slug}");
    }

    // ========================================================================
    // TEMPLATE GENERATION
    // ========================================================================

    private function generateTemplateFiles(PageType $pageType): void
    {
        $templateSlug = $pageType->getTemplate();

        // Don't overwrite existing templates
        if ($this->themeManager->templateExists($templateSlug . '.twig')) {
            return;
        }

        if ($pageType->isDynamic()) {
            $listingContent = $this->buildDynamicListingTemplate($pageType);
            $this->themeManager->writeTemplate($templateSlug . '.twig', $listingContent);

            $detailContent = $this->buildDynamicDetailTemplate($pageType);
            $this->themeManager->writeTemplate($templateSlug . '_detail.twig', $detailContent);
        } else {
            $content = $this->buildStaticTemplate($pageType);
            $this->themeManager->writeTemplate($templateSlug . '.twig', $content);
        }
    }

    private function buildStaticTemplate(PageType $pageType): string
    {
        $layout = $pageType->getLayout();
        $tpl = "{% extends \"@theme/layouts/{$layout}.twig\" %}\n\n";
        $tpl .= "{% block title %}{{ page.title }}{% endblock %}\n\n";
        $tpl .= "{% block content %}\n";
        $tpl .= "<article class=\"page\">\n";
        $tpl .= "    <h1>{{ page.title }}</h1>\n";
        $tpl .= "</article>\n";
        $tpl .= "{% endblock %}\n";

        return $tpl;
    }

    private function buildDynamicListingTemplate(PageType $pageType): string
    {
        $layout = $pageType->getLayout();
        $name = $pageType->getName();

        $tpl = "{% extends \"@theme/layouts/{$layout}.twig\" %}\n\n";
        $tpl .= "{% block title %}{$name}{% endblock %}\n\n";
        $tpl .= "{% block content %}\n";
        $tpl .= "<div class=\"listing-page\">\n";
        $tpl .= "    <h1>{$name}</h1>\n\n";
        $tpl .= "    {% if entries.data|length > 0 %}\n";
        $tpl .= "    <div class=\"entries-grid\">\n";
        $tpl .= "        {% for item in entries.data %}\n";
        $tpl .= "        <article class=\"entry-card\">\n";
        $tpl .= "            <h2><a href=\"{{ pageType.getPublicUrl(item.slug) }}\">{{ item.title }}</a></h2>\n";
        $tpl .= "        </article>\n";
        $tpl .= "        {% endfor %}\n";
        $tpl .= "    </div>\n\n";
        $tpl .= "    {% if entries.last_page > 1 %}\n";
        $tpl .= "    <nav class=\"pagination\">\n";
        $tpl .= "        {% for p in 1..entries.last_page %}\n";
        $tpl .= "            {% if p == entries.current_page %}\n";
        $tpl .= "                <span class=\"page-current\">{{ p }}</span>\n";
        $tpl .= "            {% else %}\n";
        $tpl .= "                <a href=\"?page={{ p }}\">{{ p }}</a>\n";
        $tpl .= "            {% endif %}\n";
        $tpl .= "        {% endfor %}\n";
        $tpl .= "    </nav>\n";
        $tpl .= "    {% endif %}\n\n";
        $tpl .= "    {% else %}\n";
        $tpl .= "    <p>No entries found.</p>\n";
        $tpl .= "    {% endif %}\n";
        $tpl .= "</div>\n";
        $tpl .= "{% endblock %}\n";

        return $tpl;
    }

    private function buildDynamicDetailTemplate(PageType $pageType): string
    {
        $layout = $pageType->getLayout();

        $tpl = "{% extends \"@theme/layouts/{$layout}.twig\" %}\n\n";
        $tpl .= "{% block title %}{{ page.title }}{% endblock %}\n\n";
        $tpl .= "{% block content %}\n";
        $tpl .= "<article class=\"entry-detail\">\n";
        $tpl .= "    <h1>{{ page.title }}</h1>\n";
        $tpl .= "</article>\n";
        $tpl .= "{% endblock %}\n";

        return $tpl;
    }

    // ========================================================================
    // BLOCK SNIPPETS
    // ========================================================================

    private function getBlockSnippets(): array
    {
        return [
            [
                'name' => 'Hero Section',
                'icon' => '&#9733;',
                'code' => "<section class=\"hero\" style=\"text-align:center;padding:4rem 2rem;background:#f8f9fa;\">\n    <h1>{{ page.title }}</h1>\n    <p style=\"font-size:1.25rem;color:#666;\">{{ page.subtitle ?? '' }}</p>\n</section>",
            ],
            [
                'name' => 'Text Block',
                'icon' => '&#9998;',
                'code' => "<section style=\"max-width:800px;margin:2rem auto;padding:0 1rem;\">\n    <h2>Section Title</h2>\n    <p>Your content here...</p>\n</section>",
            ],
            [
                'name' => 'Image Block',
                'icon' => '&#128247;',
                'code' => "{% if page.image is defined and page.image %}\n<figure style=\"margin:2rem auto;text-align:center;\">\n    <img src=\"/{{ page.image }}\" alt=\"{{ page.title }}\" style=\"max-width:100%;border-radius:8px;\">\n    <figcaption style=\"color:#666;margin-top:0.5rem;\">Image caption</figcaption>\n</figure>\n{% endif %}",
            ],
            [
                'name' => 'Collection Grid',
                'icon' => '&#9638;',
                'code' => "{% set items = collection('SLUG_HERE', {per_page: 6, sort_dir: 'DESC'}) %}\n<div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem;padding:2rem 0;\">\n    {% for item in items.data %}\n    <div style=\"border:1px solid #eee;border-radius:8px;padding:1.5rem;\">\n        <h3>{{ item.title }}</h3>\n        <a href=\"/page/{{ item.slug }}\">Read more</a>\n    </div>\n    {% endfor %}\n</div>",
            ],
            [
                'name' => 'Card List',
                'icon' => '&#128203;',
                'code' => "{% if entries is defined and entries.data|length > 0 %}\n<div class=\"card-list\">\n    {% for item in entries.data %}\n    <article style=\"border-bottom:1px solid #eee;padding:1.5rem 0;\">\n        <h3><a href=\"{{ pageType.getPublicUrl(item.slug) }}\">{{ item.title }}</a></h3>\n        <time style=\"color:#999;\">{{ item.created_at }}</time>\n    </article>\n    {% endfor %}\n</div>\n{% endif %}",
            ],
            [
                'name' => 'CTA Section',
                'icon' => '&#128640;',
                'code' => "<section style=\"text-align:center;padding:3rem 2rem;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:12px;margin:2rem 0;\">\n    <h2>Ready to get started?</h2>\n    <p>Join us today and explore what's possible.</p>\n    <a href=\"#\" style=\"display:inline-block;padding:0.75rem 2rem;background:#fff;color:#667eea;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:1rem;\">Get Started</a>\n</section>",
            ],
            [
                'name' => 'Pagination',
                'icon' => '&#8943;',
                'code' => "{% if entries is defined and entries.last_page > 1 %}\n<nav style=\"display:flex;gap:0.5rem;justify-content:center;padding:2rem 0;\">\n    {% for p in 1..entries.last_page %}\n        {% if p == entries.current_page %}\n            <span style=\"padding:0.5rem 1rem;background:#333;color:#fff;border-radius:4px;\">{{ p }}</span>\n        {% else %}\n            <a href=\"?page={{ p }}\" style=\"padding:0.5rem 1rem;border:1px solid #ddd;border-radius:4px;text-decoration:none;\">{{ p }}</a>\n        {% endif %}\n    {% endfor %}\n</nav>\n{% endif %}",
            ],
        ];
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function createPageTypeTable(PageType $pageType): void
    {
        $tableName = $pageType->getTableName();

        $sql = "CREATE TABLE `{$tableName}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL,
            `seo_title` VARCHAR(255) NULL DEFAULT NULL,
            `seo_description` TEXT NULL DEFAULT NULL,
            `seo_image` VARCHAR(500) NULL DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `published_at` DATETIME NULL DEFAULT NULL,
            `created_by` INT NULL DEFAULT NULL,
            `created_at` DATETIME NULL DEFAULT NULL,
            `updated_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `uniq_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->schema->getConnection()->executeStatement($sql);
    }

    private function addPageTypeColumn(string $tableName, PageTypeField $field): void
    {
        $columnDef = $this->buildColumnDef($field);
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$field->getSlug()}` {$columnDef}";
        $this->schema->getConnection()->executeStatement($sql);
    }

    private function buildColumnDef(PageTypeField $field): string
    {
        $nullable = $field->isRequired() ? 'NOT NULL' : 'NULL DEFAULT NULL';

        return match ($field->getType()) {
            'text', 'email', 'slug', 'select' => "VARCHAR(255) {$nullable}",
            'textarea', 'richtext' => "TEXT {$nullable}",
            'number' => "INT {$nullable}",
            'decimal' => "DECIMAL(10,2) {$nullable}",
            'boolean' => "TINYINT(1) NOT NULL DEFAULT 0",
            'date' => "DATE {$nullable}",
            'datetime' => "DATETIME {$nullable}",
            'url', 'image', 'file' => "VARCHAR(500) {$nullable}",
            'json' => "JSON {$nullable}",
            default => "VARCHAR(255) {$nullable}",
        };
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
        return trim($slug, '_');
    }
}
