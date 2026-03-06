<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\SectionManager;

class ThemeCustomizerController extends Controller
{
    private ThemeManager $themeManager;
    private SectionManager $sectionManager;

    public function __construct()
    {
        parent::__construct();
        $this->themeManager = new ThemeManager();
        $this->sectionManager = new SectionManager($this->themeManager);
    }

    private function requireAdmin(): bool
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return false;
        }
        if (!Auth::user()->hasRole('admin')) {
            $this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            $this->redirect('/cms');
            return false;
        }
        return true;
    }

    /**
     * Main customizer page — full-page Shopify-like editor.
     */
    public function customize(string $slug): string
    {
        if (!$this->requireAdmin()) return '';

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            $this->flash('errors', ['Theme not found.']);
            $this->redirect('/cms/themes');
            return '';
        }

        // Load all data needed by the customizer
        $pages = $this->themeManager->getPages($slug);
        $sections = $this->sectionManager->listSections($slug);
        $settingsSchema = $this->sectionManager->getSettingsSchema($slug);
        $settingsData = $this->sectionManager->getSettingsData($slug);
        $layouts = $this->themeManager->getLayoutFiles($slug);

        // Load available collections for the "collection" setting type
        $collections = [];
        try {
            $allCollections = \ZephyrPHP\Cms\Models\PageType::findAll();
            foreach ($allCollections as $col) {
                $collections[] = [
                    'slug' => $col->getSlug(),
                    'name' => $col->getName(),
                ];
            }
        } catch (\Exception $e) {
            // DB may not be ready
        }

        // Also include CMS collections
        try {
            $cmsCollections = Collection::findAll();
            foreach ($cmsCollections as $col) {
                $collections[] = [
                    'slug' => $col->getSlug(),
                    'name' => $col->getName(),
                ];
            }
        } catch (\Exception $e) {
            // ignore
        }

        // Current page (from query param or first page)
        $currentPage = $this->input('page', '');
        if (empty($currentPage) && !empty($pages)) {
            $currentPage = $pages[0]['template'] ?? '';
        }

        return $this->render('cms::themes/customizer', [
            'theme' => $theme,
            'pages' => $pages,
            'sections' => $sections,
            'settingsSchema' => $settingsSchema,
            'settingsData' => $settingsData,
            'collections' => $collections,
            'layouts' => $layouts,
            'currentPage' => $currentPage,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Preview endpoint — renders the actual theme page with section data.
     * Used as the iframe src in the customizer.
     */
    public function preview(string $slug): string
    {
        if (!$this->requireAdmin()) return '';

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            return '<p>Theme not found.</p>';
        }

        $pageTemplate = $this->input('page', 'home');
        $pages = $this->themeManager->getPages($slug);

        // Find the page config
        $pageConfig = null;
        foreach ($pages as $p) {
            if (($p['template'] ?? '') === $pageTemplate) {
                $pageConfig = $p;
                break;
            }
        }

        if (!$pageConfig) {
            return '<p>Page not found.</p>';
        }

        $layout = $pageConfig['layout'] ?? 'base';
        $title = $pageConfig['title'] ?? '';

        $view = \ZephyrPHP\View\View::getInstance();

        // Register the specific theme for preview
        $themePath = $this->themeManager->getThemePath($slug);
        if (is_dir($themePath)) {
            $view->addNamespace('theme', $themePath);
            $templatesPath = $themePath . '/templates';
            if (is_dir($templatesPath)) {
                $view->prependTemplatePath($templatesPath);
            }
        }

        // Check if page has sections
        if ($this->sectionManager->hasSections($slug, $pageTemplate)) {
            $sectionsHtml = $this->sectionManager->renderSections($pageTemplate, $slug);
            return $view->render('@theme/layouts/' . $layout . '.twig', [
                'page' => ['title' => $title, 'template' => $pageTemplate],
                'sections_html' => $sectionsHtml,
                'use_sections' => true,
                'theme_settings' => $this->sectionManager->getGlobalSettings($slug),
                'is_customizer_preview' => true,
            ]);
        }

        // Fall back to static template
        try {
            return $view->render($pageTemplate, [
                'page' => ['title' => $title],
                'is_customizer_preview' => true,
            ]);
        } catch (\Exception $e) {
            return '<p>Error rendering template: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    /**
     * AJAX: Save customizer state (global settings + all page sections).
     */
    public function save(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $theme = Theme::findOneBy(['slug' => $slug]);
        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        $settingsData = [
            'current' => $input['current'] ?? [],
            'pages' => $input['pages'] ?? [],
        ];

        if ($this->sectionManager->saveSettingsData($slug, $settingsData)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save settings']);
        }
    }

    /**
     * AJAX: List all available sections with their schemas.
     */
    public function listSections(string $slug): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $sections = $this->sectionManager->listSections($slug);
        echo json_encode(['sections' => $sections]);
    }

    /**
     * AJAX: Get schema for a specific section type.
     */
    public function sectionSchema(string $slug, string $type): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $schema = $this->sectionManager->getSectionSchema($slug, $type);
        if ($schema) {
            echo json_encode(['schema' => $schema]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Section type not found']);
        }
    }

    /**
     * AJAX: List available collections (for collection picker in settings).
     */
    public function listCollections(): void
    {
        if (!$this->requireAdmin()) return;

        header('Content-Type: application/json');

        $collections = [];

        try {
            $pageTypes = \ZephyrPHP\Cms\Models\PageType::findAll();
            foreach ($pageTypes as $pt) {
                $collections[] = [
                    'slug' => $pt->getSlug(),
                    'name' => $pt->getName(),
                    'source' => 'page_type',
                ];
            }
        } catch (\Exception $e) {}

        try {
            $cmsCollections = Collection::findAll();
            foreach ($cmsCollections as $col) {
                $collections[] = [
                    'slug' => $col->getSlug(),
                    'name' => $col->getName(),
                    'source' => 'collection',
                ];
            }
        } catch (\Exception $e) {}

        echo json_encode(['collections' => $collections]);
    }
}
