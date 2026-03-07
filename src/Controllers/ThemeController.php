<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\SectionManager;

class ThemeController extends Controller
{
    private ThemeManager $themeManager;
    private SectionManager $sectionManager;

    public function __construct()
    {
        parent::__construct();
        $this->themeManager = new ThemeManager();
        $this->sectionManager = new SectionManager($this->themeManager);
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

        $themes = $this->themeManager->listThemes();

        return $this->render('cms::themes/index', [
            'themes' => $themes,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requireAdmin();

        $existingThemes = $this->themeManager->listThemes();

        return $this->render('cms::themes/create', [
            'existingThemes' => $existingThemes,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = $this->input('description', '');
        $copyFrom = $this->input('copy_from', '');

        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->generateSlug($slug);
        }

        $errors = [];
        if (empty($name)) {
            $errors[] = 'Theme name is required.';
        }
        if (empty($slug)) {
            $errors[] = 'Theme slug is required.';
        }

        // Check uniqueness
        if ($slug && Theme::findOneBy(['slug' => $slug])) {
            $errors[] = 'A theme with this slug already exists.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['name' => $name, 'slug' => $slug, 'description' => $description]);
            $this->redirect('/cms/themes/create');
            return;
        }

        $this->themeManager->createTheme(
            $name,
            $slug,
            $description ?: null,
            $copyFrom ?: null
        );

        $this->flash('success', "Theme \"{$name}\" created successfully.");
        $this->redirect('/cms/themes');
    }

    public function edit(string $slug): string
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return '';
        }

        $files = $this->themeManager->listFiles($slug);
        $config = $this->themeManager->getThemeConfig($slug);
        $pages = $this->themeManager->getPages($slug);
        $layouts = $this->themeManager->getLayoutFiles($slug);

        // Load first file content for editor
        $activeFile = $this->input('file', '');
        $fileContent = '';
        if ($activeFile) {
            $fileContent = $this->themeManager->readFile($activeFile, $slug) ?? '';
        }

        return $this->render('cms::themes/edit', [
            'theme' => $theme,
            'files' => $files,
            'config' => $config,
            'pages' => $pages,
            'layouts' => $layouts,
            'activeFile' => $activeFile,
            'fileContent' => $fileContent,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug): void
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        $name = trim($this->input('name', ''));
        $description = $this->input('description', '');

        if (empty($name)) {
            $this->flash('errors', ['Theme name is required.']);
            $this->redirect('/cms/themes/' . $slug);
            return;
        }

        $theme->setName($name);
        $theme->setDescription($description ?: null);
        $theme->save();

        // Update theme.json name
        $config = $this->themeManager->getThemeConfig($slug);
        $config['name'] = $name;
        $configPath = $this->themeManager->getThemePath($slug) . '/theme.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->flash('success', 'Theme updated successfully.');
        $this->redirect('/cms/themes/' . $slug);
    }

    public function publish(string $slug): void
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        if ($this->themeManager->publishTheme($slug)) {
            $this->flash('success', "Theme \"{$theme->getName()}\" is now live.");
        } else {
            $this->flash('errors', ['Failed to publish theme.']);
        }

        $this->redirect('/cms/themes');
    }

    public function destroy(string $slug): void
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        if ($theme->isLive()) {
            $this->flash('errors', ['Cannot delete the live theme. Publish another theme first.']);
            $this->redirect('/cms/themes');
            return;
        }

        if ($this->themeManager->deleteTheme($slug)) {
            $this->flash('success', "Theme \"{$theme->getName()}\" deleted.");
        } else {
            $this->flash('errors', ['Failed to delete theme.']);
        }

        $this->redirect('/cms/themes');
    }

    public function preview(string $slug): void
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        $this->redirect('/?theme_preview=' . urlencode($slug));
    }

    /**
     * AJAX endpoint to save a file in the theme.
     */
    public function saveFile(string $slug): void
    {
        $this->requireAdmin();

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $filePath = $input['file'] ?? '';
        $content = $input['content'] ?? '';

        if (empty($filePath)) {
            http_response_code(400);
            echo json_encode(['error' => 'File path is required']);
            return;
        }

        // Security: only allow files within known subdirectories
        $allowedPrefixes = ['layouts/', 'templates/', 'snippets/', 'sections/', 'config/'];
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed || str_contains($filePath, '..')) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        if ($this->themeManager->writeFile($filePath, $content, $slug)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
        }
    }

    /**
     * AJAX: Add a new page to the theme.
     */
    public function addPage(string $slug): void
    {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $pageSlug = trim($input['slug'] ?? '');
        $layout = trim($input['layout'] ?? 'base');

        if (empty($title) || empty($pageSlug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Title and URL slug are required']);
            return;
        }

        // Ensure slug starts with /
        if (!str_starts_with($pageSlug, '/')) {
            $pageSlug = '/' . $pageSlug;
        }

        // Generate template name from title
        $templateName = $this->generateSlug($title);
        if (empty($templateName)) {
            $templateName = 'page-' . time();
        }

        // Check if template already exists
        if ($this->themeManager->templateExists($templateName . '.twig', $slug)) {
            http_response_code(409);
            echo json_encode(['error' => 'A page with this template name already exists']);
            return;
        }

        // Create a minimal template file (sections handle the content)
        $templateContent = "{% extends \"@theme/layouts/{$layout}.twig\" %}\n\n";
        $templateContent .= "{% block title %}{{ page.title }}{% endblock %}\n";

        $this->themeManager->writeTemplate($templateName . '.twig', $templateContent, $slug);

        // Add to pages.json
        $page = [
            'title' => $title,
            'slug' => $pageSlug,
            'template' => $templateName,
            'layout' => $layout,
        ];
        $this->themeManager->savePage($slug, $page);

        // Initialize empty sections in settings_data.json
        $this->sectionManager->savePageSections($slug, $templateName, [], []);

        echo json_encode(['success' => true, 'page' => $page]);
    }

    /**
     * AJAX: Update page settings (title, slug, layout).
     */
    public function updatePage(string $slug): void
    {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $template = trim($input['template'] ?? '');
        $title = trim($input['title'] ?? '');
        $pageSlug = trim($input['slug'] ?? '');
        $layout = trim($input['layout'] ?? 'base');

        if (empty($template) || empty($title) || empty($pageSlug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Template, title, and slug are required']);
            return;
        }

        if (!str_starts_with($pageSlug, '/')) {
            $pageSlug = '/' . $pageSlug;
        }

        $page = [
            'title' => $title,
            'slug' => $pageSlug,
            'template' => $template,
            'layout' => $layout,
        ];
        $this->themeManager->savePage($slug, $page);

        echo json_encode(['success' => true, 'page' => $page]);
    }

    /**
     * AJAX: Delete a page from the theme.
     */
    public function removePage(string $slug): void
    {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $template = trim($input['template'] ?? '');

        if (empty($template)) {
            http_response_code(400);
            echo json_encode(['error' => 'Template name is required']);
            return;
        }

        if ($this->themeManager->deletePage($slug, $template)) {
            // Clean up section data from settings_data.json
            $data = $this->sectionManager->getSettingsData($slug);
            if (isset($data['pages'][$template])) {
                unset($data['pages'][$template]);
                $this->sectionManager->saveSettingsData($slug, $data);
            }

            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
        }
    }

    /**
     * AJAX: Create a new section .twig file in the theme.
     */
    public function createSection(string $slug): void
    {
        $this->requireAdmin();
        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Section name is required']);
            return;
        }

        // Generate type slug from name
        $typeSlug = $this->generateSlug($name);
        if (empty($typeSlug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid section name']);
            return;
        }

        // Check if section already exists
        $sectionPath = $this->themeManager->getThemePath($slug) . '/sections/' . $typeSlug . '.twig';
        if (file_exists($sectionPath)) {
            http_response_code(409);
            echo json_encode(['error' => 'A section with this name already exists']);
            return;
        }

        // Generate section template content
        $templateContent = '<section class="section-' . htmlspecialchars($typeSlug) . '" style="padding:{{ section.settings.padding|default(40) }}px 0; background:{{ section.settings.bg_color|default(\'#ffffff\') }};">' . "\n";
        $templateContent .= '    <div class="container">' . "\n";
        $templateContent .= '        {% if section.settings.heading %}' . "\n";
        $templateContent .= '            <h2 style="color:{{ section.settings.heading_color|default(\'#303030\') }}; text-align:{{ section.settings.text_align|default(\'left\') }};">{{ section.settings.heading }}</h2>' . "\n";
        $templateContent .= '        {% endif %}' . "\n";
        $templateContent .= '        {% if section.settings.content %}' . "\n";
        $templateContent .= '            <div style="color:{{ section.settings.text_color|default(\'#616161\') }};">{{ section.settings.content|raw }}</div>' . "\n";
        $templateContent .= '        {% endif %}' . "\n";
        $templateContent .= '    </div>' . "\n";
        $templateContent .= '</section>' . "\n\n";
        $templateContent .= '{% schema %}' . "\n";
        $templateContent .= json_encode([
            'name' => $name,
            'description' => 'Custom section: ' . $name,
            'icon' => 'layout',
            'settings' => [
                ['type' => 'text', 'id' => 'heading', 'label' => 'Heading', 'default' => $name],
                ['type' => 'richtext', 'id' => 'content', 'label' => 'Content', 'default' => '<p>Add your content here.</p>'],
                ['type' => 'select', 'id' => 'text_align', 'label' => 'Text Alignment', 'default' => 'left',
                 'options' => [['value' => 'left', 'label' => 'Left'], ['value' => 'center', 'label' => 'Center'], ['value' => 'right', 'label' => 'Right']]],
                ['type' => 'color', 'id' => 'bg_color', 'label' => 'Background Color', 'default' => '#ffffff'],
                ['type' => 'color', 'id' => 'heading_color', 'label' => 'Heading Color', 'default' => '#303030'],
                ['type' => 'color', 'id' => 'text_color', 'label' => 'Text Color', 'default' => '#616161'],
                ['type' => 'range', 'id' => 'padding', 'label' => 'Section Padding (px)', 'min' => 0, 'max' => 120, 'step' => 4, 'default' => 40],
            ],
            'presets' => [
                ['name' => $name, 'settings' => ['heading' => $name]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $templateContent .= '{% endschema %}' . "\n";

        // Write file
        if ($this->themeManager->writeFile('sections/' . $typeSlug . '.twig', $templateContent, $slug)) {
            echo json_encode(['success' => true, 'type' => $typeSlug, 'name' => $name]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create section file']);
        }
    }

    private function generateSlug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}
