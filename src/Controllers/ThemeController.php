<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Theme;
use ZephyrPHP\Cms\Services\ThemeManager;

class ThemeController extends Controller
{
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
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
        $allowedPrefixes = ['layouts/', 'templates/', 'snippets/', 'sections/'];
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

    private function generateSlug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}
