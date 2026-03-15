<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\ThemeInstaller;
use ZephyrPHP\Cms\Services\SectionManager;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

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
        $this->requirePermission('themes.view');

        $themes = $this->themeManager->listThemes();

        // Enrich themes with config from theme.json
        $themeConfigs = [];
        foreach ($themes as $theme) {
            $slug = $theme->getSlug();
            $config = $this->themeManager->getThemeConfig($slug);
            $themeConfigs[$slug] = [
                'version' => $config['version'] ?? '1.0.0',
                'layouts' => array_keys($config['layouts'] ?? []),
            ];
        }

        return $this->render('cms::themes/index', [
            'themes' => $themes,
            'themeConfigs' => $themeConfigs,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('themes.edit');

        $existingThemes = $this->themeManager->listThemes();

        return $this->render('cms::themes/create', [
            'existingThemes' => $existingThemes,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('themes.edit');

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

        ActivityLogger::log('created', 'theme', $slug, $name);
        $this->flash('success', "Theme \"{$name}\" created successfully.");
        $this->redirect('/cms/themes');
    }

    public function edit(string $slug): string
    {
        $this->requirePermission('themes.edit');

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

        // Load available collections for the schema editor collection picker
        $collections = [];
        try {
            $cmsCollections = Collection::findAll();
            foreach ($cmsCollections as $col) {
                $collections[] = ['slug' => $col->getSlug(), 'name' => $col->getName()];
            }
        } catch (\Exception $e) {}

        return $this->render('cms::themes/edit', [
            'theme' => $theme,
            'files' => $files,
            'config' => $config,
            'pages' => $pages,
            'layouts' => $layouts,
            'activeFile' => $activeFile,
            'fileContent' => $fileContent,
            'collections' => $collections,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug): void
    {
        $this->requirePermission('themes.edit');

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
        $this->requirePermission('themes.publish');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        // Publish assets to public directory and update DB status
        $installer = new ThemeInstaller($this->themeManager);
        $result = $installer->activate($slug);

        if ($result['success']) {
            ActivityLogger::log('published', 'theme', $slug, $theme->getName());
            $this->flash('success', "Theme \"{$theme->getName()}\" is now live.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to publish theme.']);
        }

        $this->redirect('/cms/themes');
    }

    public function destroy(string $slug): void
    {
        $this->requirePermission('themes.edit');

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
            ActivityLogger::log('deleted', 'theme', $slug, $theme->getName());
            $this->flash('success', "Theme \"{$theme->getName()}\" deleted.");
        } else {
            $this->flash('errors', ['Failed to delete theme.']);
        }

        $this->redirect('/cms/themes');
    }

    public function preview(string $slug): void
    {
        $this->requirePermission('themes.edit');

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
        $this->requirePermission('themes.edit');

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
        $allowedPrefixes = ['layouts/', 'templates/', 'snippets/', 'sections/', 'config/', 'controllers/', 'assets/'];
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
        $this->requirePermission('themes.edit');
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
        $authRequired = (bool) ($input['auth_required'] ?? false);
        $allowedRoles = $input['allowed_roles'] ?? [];

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

        // Create a minimal template file
        $templateContent = "{% extends \"@theme/layouts/{$layout}.twig\" %}\n\n";
        $templateContent .= "{% block title %}{{ page.title }}{% endblock %}\n\n";
        $templateContent .= "{% block content %}\n";
        $templateContent .= "<div class=\"container\" style=\"padding: 60px 24px;\">\n";
        $templateContent .= "    <h1>{{ page.title }}</h1>\n";
        $templateContent .= "</div>\n";
        $templateContent .= "{% endblock %}\n";

        $this->themeManager->writeTemplate($templateName . '.twig', $templateContent, $slug);

        // Check if this is a dynamic route (has {param} patterns)
        $hasDynamicParams = (bool) preg_match('/\{(\w+)\}/', $pageSlug);
        $collection = trim($input['collection'] ?? '');
        $controllerName = null;

        if ($hasDynamicParams || !empty($collection)) {
            $controllerName = $templateName;
            $controllerContent = $this->generateControllerContent($title, $pageSlug, $collection);

            // Write controller file
            $controllerDir = $this->themeManager->getThemePath($slug) . '/controllers';
            if (!is_dir($controllerDir)) {
                mkdir($controllerDir, 0755, true);
            }
            file_put_contents($controllerDir . '/' . $controllerName . '.php', $controllerContent);
        }

        // Add to pages.json
        $page = [
            'title' => $title,
            'slug' => $pageSlug,
            'template' => $templateName,
            'layout' => $layout,
        ];
        if ($controllerName) {
            $page['controller'] = $controllerName;
        }
        if (!empty($collection)) {
            $page['collection'] = $collection;
        }
        if ($authRequired) {
            $page['auth_required'] = true;
        }
        if (!empty($allowedRoles) && is_array($allowedRoles)) {
            $page['allowed_roles'] = array_values(array_filter(array_map('trim', $allowedRoles)));
        }
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
        $this->requirePermission('themes.edit');
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
        $authRequired = (bool) ($input['auth_required'] ?? false);
        $allowedRoles = $input['allowed_roles'] ?? [];

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

        // Preserve existing controller/collection settings from pages.json
        $existingPages = $this->themeManager->getPages($slug);
        foreach ($existingPages as $ep) {
            if (($ep['template'] ?? '') === $template) {
                if (isset($ep['controller'])) $page['controller'] = $ep['controller'];
                if (isset($ep['collection'])) $page['collection'] = $ep['collection'];
                break;
            }
        }

        if ($authRequired) {
            $page['auth_required'] = true;
        }
        if (!empty($allowedRoles) && is_array($allowedRoles)) {
            $page['allowed_roles'] = array_values(array_filter(array_map('trim', $allowedRoles)));
        }

        $this->themeManager->savePage($slug, $page);

        echo json_encode(['success' => true, 'page' => $page]);
    }

    /**
     * AJAX: Delete a page from the theme.
     */
    public function removePage(string $slug): void
    {
        $this->requirePermission('themes.edit');
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
        $this->requirePermission('themes.edit');
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

    /**
     * Install a theme from an uploaded ZIP file.
     */
    public function installUpload(): void
    {
        $this->requirePermission('themes.edit');

        $file = $_FILES['theme_zip'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing server temp folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $this->flash('errors', [$errorMessages[$code] ?? 'Upload error (code: ' . $code . ')']);
            $this->redirect('/cms/themes');
            return;
        }

        // Validate MIME type — must be a ZIP
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes, true)) {
            $this->flash('errors', ['Only ZIP files are allowed. Detected: ' . $mime]);
            $this->redirect('/cms/themes');
            return;
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('errors', ['Only .zip files are allowed.']);
            $this->redirect('/cms/themes');
            return;
        }

        // Check file size (max 50MB)
        if ($file['size'] > 50 * 1024 * 1024) {
            $this->flash('errors', ['ZIP file exceeds maximum size of 50MB.']);
            $this->redirect('/cms/themes');
            return;
        }

        $overwrite = (bool) $this->input('overwrite', false);
        $installer = new ThemeInstaller($this->themeManager);
        $result = $installer->install($file['tmp_name'], $overwrite);

        if ($result['success']) {
            ActivityLogger::log('installed', 'theme', $result['slug'] ?? '', $result['name']);
            $this->flash('success', "Theme \"{$result['name']}\" installed successfully.");
        } else {
            $this->flash('errors', [$result['error']]);
        }

        $this->redirect('/cms/themes');
    }

    /**
     * Uninstall a theme — removes files, published assets, and DB record.
     */
    public function uninstallTheme(string $slug): void
    {
        $this->requirePermission('themes.edit');

        // Validate slug format
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid theme slug.']);
            $this->redirect('/cms/themes');
            return;
        }

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return;
        }

        if ($theme->isLive()) {
            $this->flash('errors', ['Cannot uninstall the active (live) theme. Publish another theme first.']);
            $this->redirect('/cms/themes');
            return;
        }

        $installer = new ThemeInstaller($this->themeManager);
        $result = $installer->uninstall($slug);

        if ($result['success']) {
            ActivityLogger::log('uninstalled', 'theme', $slug, $theme->getName());
            $this->flash('success', "Theme \"{$theme->getName()}\" has been uninstalled.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to uninstall theme.']);
        }

        $this->redirect('/cms/themes');
    }

    private function generateSlug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    private function generateControllerContent(string $title, string $route, string $collection): string
    {
        $lines = ["<?php"];
        $lines[] = "// Controller: {$title}";
        $lines[] = "// Route: {$route}";
        $lines[] = "";

        // Extract route parameter names
        preg_match_all('/\{(\w+)\}/', $route, $matches);
        $paramNames = $matches[1] ?? [];
        $hasDynamicParams = !empty($paramNames);

        $lines[] = "return function(array \$params) {";

        if ($hasDynamicParams && !empty($collection)) {
            // Detail page: fetch single entry by slug
            $paramName = $paramNames[0]; // use first param
            $lines[] = "    // Fetch entry from '{$collection}' collection";
            $lines[] = "    \$item = entry('{$collection}', \$params['{$paramName}']);";
            $lines[] = "";
            $lines[] = "    if (!\$item) {";
            $lines[] = "        abort(404);";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    return [";
            $lines[] = "        'item' => \$item,";
            $lines[] = "    ];";
        } elseif (!$hasDynamicParams && !empty($collection)) {
            // Listing page: fetch collection with pagination
            $lines[] = "    // Fetch entries from '{$collection}' collection";
            $lines[] = "    \$page = max(1, (int)(\$params['_query']['page'] ?? 1));";
            $lines[] = "    \$items = collection('{$collection}', [";
            $lines[] = "        'per_page' => 10,";
            $lines[] = "        'page' => \$page,";
            $lines[] = "        'sort_by' => 'id',";
            $lines[] = "        'sort_dir' => 'DESC',";
            $lines[] = "    ]);";
            $lines[] = "";
            $lines[] = "    return [";
            $lines[] = "        'items' => \$items,";
            $lines[] = "    ];";
        } else {
            // Generic: just pass params
            if (!empty($paramNames)) {
                $lines[] = "    // Route parameters: " . implode(', ', array_map(fn($p) => "\$params['{$p}']", $paramNames));
            }
            $lines[] = "";
            $lines[] = "    return [";
            $lines[] = "        // Add your data here";
            $lines[] = "    ];";
        }

        $lines[] = "};";
        $lines[] = "";

        return implode("\n", $lines);
    }
}
