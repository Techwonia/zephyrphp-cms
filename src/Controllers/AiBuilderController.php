<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Ai\AiBuilder;
use ZephyrPHP\Ai\AiProviderManager;
use ZephyrPHP\Cms\Services\PermissionService;

class AiBuilderController extends Controller
{
    private function requirePerm(string $permission): void
    {
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
            exit;
        }
    }

    /**
     * AI Builder main page — generate pages, sections, content.
     */
    public function index(): void
    {
        $this->requirePerm('pages.edit');

        $providerManager = AiProviderManager::getInstance();
        $providers = $providerManager->getProviderInfo();
        $defaultProvider = $providerManager->getDefault();
        $availableProviders = $providerManager->getAvailable();

        // Load generation history from DB
        $history = $this->loadHistory(20);

        echo $this->render('@cms/ai-builder/index', [
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
            'availableProviders' => $availableProviders,
            'history' => $history,
        ]);
    }

    /**
     * Generate a page via AI.
     */
    public function generatePage(): void
    {
        $this->requirePerm('pages.edit');

        $prompt = trim($_POST['prompt'] ?? '');
        $provider = trim($_POST['provider'] ?? '') ?: null;
        $mode = $_POST['mode'] ?? 'page'; // page, section, content, explain, fix

        if (empty($prompt)) {
            $this->jsonError('Please enter a prompt describing what you want to generate.', 422);
            return;
        }

        if (strlen($prompt) > 5000) {
            $this->jsonError('Prompt is too long. Maximum 5000 characters.', 422);
            return;
        }

        // Validate provider is available
        $providerManager = AiProviderManager::getInstance();
        if ($provider && !in_array($provider, $providerManager->getAvailable())) {
            $this->jsonError("Provider '{$provider}' is not available. Check your API key configuration.", 422);
            return;
        }

        try {
            $builder = new AiBuilder($providerManager);

            // Build context from current theme
            $context = $this->buildContext();

            $result = match ($mode) {
                'page' => $this->handlePageGeneration($builder, $prompt, $context, $provider),
                'section' => $this->handleSectionGeneration($builder, $prompt, $context, $provider),
                'content' => $this->handleContentGeneration($builder, $prompt, $context, $provider),
                'explain' => $this->handleExplainCode($builder, $prompt, $provider),
                'fix' => $this->handleFixTemplate($builder, $prompt, $provider),
                default => $this->handlePageGeneration($builder, $prompt, $context, $provider),
            };

            // Save to history
            $this->saveHistory($prompt, $mode, $provider ?? $providerManager->getDefault(), $result);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'mode' => $mode,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Save a generated page as a template file in the current theme.
     */
    public function savePage(): void
    {
        $this->requirePerm('pages.edit');

        $template = $_POST['template'] ?? '';
        $title = trim($_POST['title'] ?? 'AI Generated Page');
        $slug = trim($_POST['slug'] ?? '');
        $css = $_POST['css'] ?? '';

        if (empty($template)) {
            $this->jsonError('No template content to save.', 422);
            return;
        }

        // Sanitize slug
        $slug = $slug ?: strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $title));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));

        if (empty($slug)) {
            $slug = 'ai-page-' . time();
        }

        try {
            $themeManager = new \ZephyrPHP\Cms\Services\ThemeManager();
            $themePath = $themeManager->getActiveThemePath();

            if (!is_dir($themePath)) {
                $this->jsonError('No active theme found.', 500);
                return;
            }

            // Save template
            $templateDir = $themePath . '/templates';
            if (!is_dir($templateDir)) {
                mkdir($templateDir, 0755, true);
            }

            $templatePath = $templateDir . '/' . $slug . '.twig';

            // Prevent overwriting without confirmation
            if (file_exists($templatePath) && empty($_POST['overwrite'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Template already exists.',
                    'exists' => true,
                    'path' => $slug . '.twig',
                ]);
                return;
            }

            file_put_contents($templatePath, $template);

            // Save custom CSS if provided
            if (!empty($css)) {
                $cssDir = $themePath . '/assets/css';
                if (!is_dir($cssDir)) {
                    mkdir($cssDir, 0755, true);
                }
                file_put_contents($cssDir . '/ai-' . $slug . '.css', $css);
            }

            // Add to pages.json
            $this->addToPages($themeManager, $slug, $title);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'slug' => $slug,
                'path' => $slug . '.twig',
                'message' => "Page saved as {$slug}.twig",
            ]);
        } catch (\Exception $e) {
            $this->jsonError('Failed to save page: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save a generated section as a section template.
     */
    public function saveSection(): void
    {
        $this->requirePerm('pages.edit');

        $template = $_POST['template'] ?? '';
        $slug = trim($_POST['slug'] ?? '');
        $css = $_POST['css'] ?? '';

        if (empty($template) || empty($slug)) {
            $this->jsonError('Missing template content or slug.', 422);
            return;
        }

        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));

        try {
            $themeManager = new \ZephyrPHP\Cms\Services\ThemeManager();
            $themePath = $themeManager->getActiveThemePath();

            $sectionDir = $themePath . '/sections';
            if (!is_dir($sectionDir)) {
                mkdir($sectionDir, 0755, true);
            }

            $sectionPath = $sectionDir . '/' . $slug . '.twig';

            if (file_exists($sectionPath) && empty($_POST['overwrite'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Section already exists.',
                    'exists' => true,
                ]);
                return;
            }

            file_put_contents($sectionPath, $template);

            if (!empty($css)) {
                $cssDir = $themePath . '/assets/css';
                if (!is_dir($cssDir)) {
                    mkdir($cssDir, 0755, true);
                }
                file_put_contents($cssDir . '/section-' . $slug . '.css', $css);
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'slug' => $slug,
                'message' => "Section saved as {$slug}.twig",
            ]);
        } catch (\Exception $e) {
            $this->jsonError('Failed to save section: ' . $e->getMessage(), 500);
        }
    }

    /**
     * AI Settings page — configure providers.
     */
    public function settings(): void
    {
        $this->requirePerm('settings.edit');

        $providerManager = AiProviderManager::getInstance();
        $providers = $providerManager->getProviderInfo();
        $defaultProvider = $providerManager->getDefault();

        echo $this->render('@cms/ai-builder/settings', [
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
        ]);
    }

    // ── Private helpers ──

    private function handlePageGeneration(AiBuilder $builder, string $prompt, array $context, ?string $provider): array
    {
        $page = $builder->generatePage($prompt, $context, $provider);
        return $page->toArray();
    }

    private function handleSectionGeneration(AiBuilder $builder, string $prompt, array $context, ?string $provider): array
    {
        return $builder->generateSection($prompt, $context, $provider);
    }

    private function handleContentGeneration(AiBuilder $builder, string $prompt, array $context, ?string $provider): array
    {
        $response = $builder->generateContent($prompt, $context, $provider);
        return [
            'content' => $response->getContent(),
            'usage' => $response->toArray(),
        ];
    }

    private function handleExplainCode(AiBuilder $builder, string $code, ?string $provider): array
    {
        $response = $builder->explainCode($code, $provider);
        return [
            'explanation' => $response->getContent(),
            'usage' => $response->toArray(),
        ];
    }

    private function handleFixTemplate(AiBuilder $builder, string $prompt, ?string $provider): array
    {
        // Prompt may contain code + error separated by "---ERROR---"
        $parts = explode('---ERROR---', $prompt, 2);
        $code = trim($parts[0]);
        $error = isset($parts[1]) ? trim($parts[1]) : '';

        $response = $builder->fixTemplate($code, $error, $provider);
        return [
            'fixed_template' => $response->getContent(),
            'usage' => $response->toArray(),
        ];
    }

    private function buildContext(): array
    {
        $context = [];

        try {
            $themeManager = new \ZephyrPHP\Cms\Services\ThemeManager();
            $config = $themeManager->getThemeConfig();
            if ($config) {
                $context['theme'] = [
                    'name' => $config['display_name'] ?? $config['name'] ?? 'Unknown',
                    'settings' => $config['settings'] ?? [],
                ];
            }

            // Get available sections
            $sectionManager = new \ZephyrPHP\Cms\Services\SectionManager($themeManager);
            $sections = [];
            try {
                $sectionTypes = $sectionManager->listTypes();
                foreach ($sectionTypes as $type) {
                    $sections[] = [
                        'name' => $type['name'] ?? $type['slug'] ?? 'Unknown',
                        'description' => $type['description'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                // Ignore
            }
            if (!empty($sections)) {
                $context['sections'] = $sections;
            }
        } catch (\Exception $e) {
            // Theme not available
        }

        return $context;
    }

    private function addToPages(\ZephyrPHP\Cms\Services\ThemeManager $themeManager, string $slug, string $title): void
    {
        try {
            $pages = $themeManager->getPages();
            // Check if page already exists
            foreach ($pages as $page) {
                if (($page['slug'] ?? '') === '/' . $slug) {
                    return;
                }
            }
            $pages[] = [
                'slug' => '/' . $slug,
                'template' => $slug,
                'title' => $title,
                'layout' => 'base',
            ];
            $themeManager->savePages($pages);
        } catch (\Exception $e) {
            // Ignore — page registration is optional
        }
    }

    private function loadHistory(int $limit = 20): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return [];

            $userId = \ZephyrPHP\Auth\Auth::id();
            if (!$userId) return [];

            $result = $conn->executeQuery(
                "SELECT * FROM cms_ai_history WHERE user_id = ? ORDER BY createdAt DESC LIMIT ?",
                [$userId, $limit]
            );

            return $result->fetchAllAssociative();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function saveHistory(string $prompt, string $mode, string $provider, array $result): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return;

            $userId = \ZephyrPHP\Auth\Auth::id();
            if (!$userId) return;

            $conn->executeStatement(
                "INSERT INTO cms_ai_history (user_id, prompt, mode, provider, result_summary, tokens_used, createdAt)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    mb_substr($prompt, 0, 1000),
                    $mode,
                    $provider,
                    mb_substr($result['title'] ?? $result['name'] ?? substr($prompt, 0, 100), 0, 255),
                    $result['usage']['total_tokens'] ?? 0,
                ]
            );
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
    }
}
