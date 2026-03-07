<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

class SectionManager
{
    private ThemeManager $themeManager;

    public function __construct(ThemeManager $themeManager)
    {
        $this->themeManager = $themeManager;
    }

    /**
     * List all available sections from a theme's sections/ directory.
     * Returns array of [type => schema] where type is the filename without .twig.
     */
    public function listSections(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $sectionsDir = $this->themeManager->getThemePath($slug) . '/sections';

        if (!is_dir($sectionsDir)) {
            return [];
        }

        $sections = [];
        foreach (scandir($sectionsDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!str_ends_with($file, '.twig')) continue;

            $type = basename($file, '.twig');
            $filePath = $sectionsDir . '/' . $file;
            $schema = $this->parseSchema($filePath);

            if ($schema) {
                $sections[$type] = $schema;
            } else {
                // Section without schema — provide minimal info
                $sections[$type] = [
                    'name' => ucwords(str_replace('-', ' ', $type)),
                    'settings' => [],
                ];
            }
        }

        return $sections;
    }

    /**
     * Parse the {% schema %}...{% endschema %} JSON block from a section Twig file.
     */
    public function parseSchema(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        // Extract JSON between {% schema %} and {% endschema %}
        if (preg_match('/\{%[-\s]*schema\s*[-\s]*%\}(.*?)\{%[-\s]*endschema\s*[-\s]*%\}/s', $content, $matches)) {
            $json = trim($matches[1]);
            $schema = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Get the schema for a specific section type.
     */
    public function getSectionSchema(?string $slug, string $sectionType): ?array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $filePath = $this->themeManager->getThemePath($slug) . '/sections/' . $sectionType . '.twig';
        return $this->parseSchema($filePath);
    }

    /**
     * Get the Twig content of a section (without the schema block).
     */
    public function getSectionTemplate(string $sectionType, ?string $slug = null): ?string
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $filePath = $this->themeManager->getThemePath($slug) . '/sections/' . $sectionType . '.twig';

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        // Remove the schema block for rendering
        $content = preg_replace('/\{%[-\s]*schema\s*[-\s]*%\}.*?\{%[-\s]*endschema\s*[-\s]*%\}/s', '', $content);

        return trim($content);
    }

    // --- Settings Schema (global theme settings) ---

    /**
     * Read config/settings_schema.json for a theme.
     */
    public function getSettingsSchema(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $path = $this->themeManager->getThemePath($slug) . '/config/settings_schema.json';

        if (!file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    // --- Settings Data (stored values) ---

    /**
     * Read config/settings_data.json for a theme.
     */
    public function getSettingsData(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $path = $this->themeManager->getThemePath($slug) . '/config/settings_data.json';

        if (!file_exists($path)) {
            return ['current' => new \stdClass(), 'pages' => new \stdClass()];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return ['current' => new \stdClass(), 'pages' => new \stdClass()];
        }

        // Ensure current and pages are associative (objects in JSON, not arrays)
        if (!isset($data['current']) || (is_array($data['current']) && empty($data['current']))) {
            $data['current'] = new \stdClass();
        }
        if (!isset($data['pages']) || (is_array($data['pages']) && empty($data['pages']))) {
            $data['pages'] = new \stdClass();
        }

        return $data;
    }

    /**
     * Write config/settings_data.json for a theme.
     */
    public function saveSettingsData(string $slug, array $data): bool
    {
        $path = $this->themeManager->getThemePath($slug) . '/config/settings_data.json';
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Ensure current and pages encode as JSON objects (not arrays) when empty
        if (empty($data['current'])) {
            $data['current'] = new \stdClass();
        }
        if (empty($data['pages'])) {
            $data['pages'] = new \stdClass();
        }

        return file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    // --- Page Sections ---

    /**
     * Get section configuration for a specific page template.
     * Returns ['sections' => [...], 'order' => [...]]
     */
    public function getPageSections(?string $slug, string $pageTemplate): array
    {
        $data = $this->getSettingsData($slug);
        $pageData = $data['pages'][$pageTemplate] ?? null;

        if (!$pageData) {
            return ['sections' => [], 'order' => []];
        }

        return [
            'sections' => $pageData['sections'] ?? [],
            'order' => $pageData['order'] ?? [],
        ];
    }

    /**
     * Save section configuration for a specific page template.
     */
    public function savePageSections(string $slug, string $pageTemplate, array $sections, array $order): void
    {
        $data = $this->getSettingsData($slug);

        $data['pages'][$pageTemplate] = [
            'sections' => $sections,
            'order' => $order,
        ];

        $this->saveSettingsData($slug, $data);
    }

    // --- Global Settings ---

    /**
     * Get the current global theme settings (merged with defaults from schema).
     */
    public function getGlobalSettings(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $schema = $this->getSettingsSchema($slug);
        $data = $this->getSettingsData($slug);
        $current = $data['current'] ?? [];

        // Merge defaults from schema with saved values
        $settings = [];
        foreach ($schema as $group) {
            foreach ($group['settings'] ?? [] as $setting) {
                $id = $setting['id'] ?? null;
                if (!$id) continue;
                $settings[$id] = $current[$id] ?? $setting['default'] ?? null;
            }
        }

        return $settings;
    }

    /**
     * Save global theme settings.
     */
    public function saveGlobalSettings(string $slug, array $settings): void
    {
        $data = $this->getSettingsData($slug);
        $data['current'] = $settings;
        $this->saveSettingsData($slug, $data);
    }

    /**
     * Check if a page has sections configured in settings_data.json.
     */
    public function hasSections(?string $slug, string $pageTemplate): bool
    {
        $pageSections = $this->getPageSections($slug, $pageTemplate);
        return !empty($pageSections['order']);
    }

    /**
     * Render all sections for a page in order.
     * Returns the concatenated HTML of all sections.
     */
    public function renderSections(string $pageTemplate, ?string $slug = null): string
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $pageSections = $this->getPageSections($slug, $pageTemplate);
        $globalSettings = $this->getGlobalSettings($slug);

        $html = '';
        foreach ($pageSections['order'] as $sectionId) {
            $sectionConfig = $pageSections['sections'][$sectionId] ?? null;
            if (!$sectionConfig) continue;

            $type = $sectionConfig['type'];
            $settings = $sectionConfig['settings'] ?? [];
            $blocks = $sectionConfig['blocks'] ?? [];
            $blockOrder = $sectionConfig['block_order'] ?? array_keys($blocks);

            // Merge section schema defaults with saved settings
            $schema = $this->getSectionSchema($slug, $type);
            if ($schema) {
                foreach ($schema['settings'] ?? [] as $schemaSetting) {
                    $id = $schemaSetting['id'] ?? null;
                    if ($id && !isset($settings[$id]) && isset($schemaSetting['default'])) {
                        $settings[$id] = $schemaSetting['default'];
                    }
                }
            }

            // Build ordered blocks array
            $orderedBlocks = [];
            foreach ($blockOrder as $blockId) {
                if (isset($blocks[$blockId])) {
                    $block = $blocks[$blockId];
                    $block['id'] = $blockId;

                    // Merge block schema defaults
                    if ($schema && isset($schema['blocks'])) {
                        foreach ($schema['blocks'] as $blockSchema) {
                            if (($blockSchema['type'] ?? '') === ($block['type'] ?? '')) {
                                foreach ($blockSchema['settings'] ?? [] as $bs) {
                                    $bsId = $bs['id'] ?? null;
                                    if ($bsId && !isset($block['settings'][$bsId]) && isset($bs['default'])) {
                                        $block['settings'][$bsId] = $bs['default'];
                                    }
                                }
                            }
                        }
                    }

                    $orderedBlocks[] = $block;
                }
            }

            $html .= $this->renderSingleSection($type, $settings, $orderedBlocks, $globalSettings, $slug);
        }

        return $html;
    }

    /**
     * Render a single section by loading its Twig template.
     * Uses getSectionTemplate() to strip {% schema %} blocks before rendering,
     * since Twig does not recognize the schema tag natively.
     */
    private function renderSingleSection(string $type, array $settings, array $blocks, array $themeSettings, string $slug): string
    {
        try {
            $templateContent = $this->getSectionTemplate($type, $slug);
            if (!$templateContent) {
                return '<!-- Section "' . htmlspecialchars($type) . '" template not found -->';
            }

            $view = \ZephyrPHP\View\View::getInstance();
            $twig = $view->getEngine();
            $template = $twig->createTemplate($templateContent);

            return $template->render([
                'section' => [
                    'type' => $type,
                    'settings' => $settings,
                    'blocks' => $blocks,
                ],
                'theme_settings' => $themeSettings,
            ]);
        } catch (\Exception $e) {
            // If template not found or render error, return error comment
            return '<!-- Section "' . htmlspecialchars($type) . '" render error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    /**
     * Generate a unique section ID.
     */
    public function generateSectionId(string $type): string
    {
        return $type . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
