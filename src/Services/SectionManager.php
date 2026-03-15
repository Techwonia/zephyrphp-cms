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

        $sections = [];

        // Theme's own sections
        if (is_dir($sectionsDir)) {
            foreach (scandir($sectionsDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (!str_ends_with($file, '.twig')) continue;

                $type = basename($file, '.twig');
                $filePath = $sectionsDir . '/' . $file;
                $schema = $this->parseSchema($filePath);

                if ($schema) {
                    $sections[$type] = $schema;
                } else {
                    $sections[$type] = [
                        'name' => ucwords(str_replace('-', ' ', $type)),
                        'settings' => [],
                    ];
                }
            }
        }

        // Merge in built-in stubs that the theme hasn't overridden
        $stubsDir = dirname(__DIR__, 2) . '/stubs/sections';
        if (is_dir($stubsDir)) {
            foreach (scandir($stubsDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (!str_ends_with($file, '.twig')) continue;

                $type = basename($file, '.twig');
                if (isset($sections[$type])) continue; // theme override takes priority

                $schema = $this->parseSchema($stubsDir . '/' . $file);
                if ($schema) {
                    $sections[$type] = $schema;
                }
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

        $schema = $this->parseSchema($filePath);

        // Fall back to built-in stubs
        if (!$schema) {
            $stubPath = dirname(__DIR__, 2) . '/stubs/sections/' . $sectionType . '.twig';
            $schema = $this->parseSchema($stubPath);
        }

        return $schema;
    }

    /**
     * Get the Twig content of a section (without the schema block).
     */
    public function getSectionTemplate(string $sectionType, ?string $slug = null): ?string
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $filePath = $this->themeManager->getThemePath($slug) . '/sections/' . $sectionType . '.twig';

        // Fall back to built-in stubs
        if (!file_exists($filePath)) {
            $filePath = dirname(__DIR__, 2) . '/stubs/sections/' . $sectionType . '.twig';
        }

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
            return ['current' => [], 'pages' => []];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return ['current' => [], 'pages' => []];
        }

        if (!isset($data['current']) || !is_array($data['current'])) {
            $data['current'] = [];
        }
        if (!isset($data['pages']) || !is_array($data['pages'])) {
            $data['pages'] = [];
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

        // Ensure nested sections/blocks encode as JSON objects, not arrays
        if (is_array($data['pages'])) {
            foreach ($data['pages'] as &$pageData) {
                if (is_array($pageData)) {
                    if (empty($pageData['sections'])) {
                        $pageData['sections'] = new \stdClass();
                    } elseif (is_array($pageData['sections'])) {
                        foreach ($pageData['sections'] as &$section) {
                            if (is_array($section) && isset($section['blocks']) && empty($section['blocks'])) {
                                $section['blocks'] = new \stdClass();
                            }
                            if (is_array($section) && isset($section['settings']) && empty($section['settings'])) {
                                $section['settings'] = new \stdClass();
                            }
                        }
                        unset($section);
                    }
                }
            }
            unset($pageData);
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
            'header_order' => $pageData['header_order'] ?? [],
            'footer_order' => $pageData['footer_order'] ?? [],
        ];
    }

    /**
     * Save section configuration for a specific page template.
     */
    public function savePageSections(string $slug, string $pageTemplate, array $sections, array $order): void
    {
        $data = $this->getSettingsData($slug);

        $data['pages'][$pageTemplate] = [
            'sections' => empty($sections) ? new \stdClass() : $sections,
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
        return !empty($pageSections['order']) || !empty($pageSections['header_order']) || !empty($pageSections['footer_order']);
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

        $allOrder = array_merge(
            $pageSections['header_order'] ?? [],
            $pageSections['order'],
            $pageSections['footer_order'] ?? []
        );

        $html = '';
        foreach ($allOrder as $sectionId) {
            $sectionConfig = $pageSections['sections'][$sectionId] ?? null;
            if (!$sectionConfig) continue;

            // Skip disabled/hidden sections
            if (!empty($sectionConfig['disabled'])) continue;

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

            $html .= $this->renderSingleSection($type, $settings, $orderedBlocks, $globalSettings, $slug, $sectionId);
        }

        return $html;
    }

    /**
     * Render sections from provided data (for live preview without saving to disk).
     */
    public function renderSectionsFromData(array $settingsData, string $pageTemplate, ?string $slug = null): string
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $pageData = $settingsData['pages'][$pageTemplate] ?? null;
        if (!$pageData) {
            return '';
        }

        $sections = $pageData['sections'] ?? [];
        $headerOrder = $pageData['header_order'] ?? [];
        $order = $pageData['order'] ?? [];
        $footerOrder = $pageData['footer_order'] ?? [];
        $allOrder = array_merge($headerOrder, $order, $footerOrder);
        $globalSettings = $this->getGlobalSettingsFromData($settingsData, $slug);

        $html = '';
        foreach ($allOrder as $sectionId) {
            $sectionConfig = $sections[$sectionId] ?? null;
            if (!$sectionConfig) continue;

            // Skip disabled/hidden sections
            if (!empty($sectionConfig['disabled'])) continue;

            $type = $sectionConfig['type'];
            $settings = $sectionConfig['settings'] ?? [];
            $blocks = $sectionConfig['blocks'] ?? [];
            $blockOrder = $sectionConfig['block_order'] ?? array_keys($blocks);

            $schema = $this->getSectionSchema($slug, $type);
            if ($schema) {
                foreach ($schema['settings'] ?? [] as $schemaSetting) {
                    $id = $schemaSetting['id'] ?? null;
                    if ($id && !isset($settings[$id]) && isset($schemaSetting['default'])) {
                        $settings[$id] = $schemaSetting['default'];
                    }
                }
            }

            $orderedBlocks = [];
            foreach ($blockOrder as $blockId) {
                if (isset($blocks[$blockId])) {
                    $block = $blocks[$blockId];
                    $block['id'] = $blockId;

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

            $html .= $this->renderSingleSection($type, $settings, $orderedBlocks, $globalSettings, $slug, $sectionId);
        }

        return $html;
    }

    /**
     * Get global theme settings from provided data (for live preview without reading from disk).
     */
    public function getGlobalSettingsFromData(array $settingsData, ?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $schema = $this->getSettingsSchema($slug);
        $current = $settingsData['current'] ?? [];

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
     * Render a single section by loading its Twig template.
     * Uses getSectionTemplate() to strip {% schema %} blocks before rendering,
     * since Twig does not recognize the schema tag natively.
     *
     * If the section schema declares a "collection" property, the collection data
     * and field definitions are auto-loaded and passed to the template as:
     *   - section.collection_data  → paginated result from collection() helper
     *   - section.collection_fields → array of field definitions [{slug, name, type, options}, ...]
     */
    private function renderSingleSection(string $type, array $settings, array $blocks, array $themeSettings, string $slug, ?string $sectionId = null): string
    {
        try {
            $templateContent = $this->getSectionTemplate($type, $slug);
            if (!$templateContent) {
                $safeType = htmlspecialchars($type);
                return '<div style="background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:16px 20px;margin:8px 0;border-radius:6px;font-family:monospace;font-size:13px;">'
                    . '<strong>Section &ldquo;' . $safeType . '&rdquo;:</strong> Template file not found (sections/' . $safeType . '.twig)'
                    . '</div>';
            }

            // Auto-load collection data if schema declares "collection"
            $collectionData = null;
            $collectionFields = [];
            $schema = $this->getSectionSchema($slug, $type);

            if ($schema && !empty($schema['collection']) && function_exists('collection')) {
                $collSlug = $schema['collection'];
                $perPage = (int) ($settings['count'] ?? $settings['per_page'] ?? $settings['limit'] ?? 6);
                $collectionData = collection($collSlug, [
                    'per_page' => $perPage,
                    'sort_by' => $settings['sort_by'] ?? 'id',
                    'sort_dir' => $settings['sort_dir'] ?? 'DESC',
                ]);

                // Load field definitions for the collection
                if (function_exists('_cms_get_fields')) {
                    $fieldObjects = _cms_get_fields($collSlug);
                    foreach ($fieldObjects as $field) {
                        $collectionFields[] = [
                            'slug' => $field->getSlug(),
                            'name' => $field->getName(),
                            'type' => $field->getType(),
                            'options' => $field->getOptions(),
                        ];
                    }
                }
            }

            // In customizer mode, auto-inject data-block-id/data-block-type into template
            // if not already present. This ensures block-level inspector works even when
            // theme templates don't include these attributes.
            if ($sectionId !== null && !str_contains($templateContent, 'data-block-id')) {
                $templateContent = $this->injectBlockDataAttributes($templateContent);
            }

            $view = \ZephyrPHP\View\View::getInstance();
            $twig = $view->getEngine();
            $template = $twig->createTemplate($templateContent);

            $renderedHtml = $template->render([
                'section' => [
                    'type' => $type,
                    'settings' => $settings,
                    'blocks' => $blocks,
                    'collection_data' => $collectionData,
                    'collection_fields' => $collectionFields,
                ],
                'theme_settings' => $themeSettings,
            ]);

            // Wrap with data attributes for inspector/customizer mode
            if ($sectionId !== null) {
                $safeSectionId = htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8');
                $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
                $renderedHtml = '<div data-section-id="' . $safeSectionId . '" data-section-type="' . $safeType . '">' . $renderedHtml . '</div>';
            }

            return $renderedHtml;
        } catch (\Exception $e) {
            $safeType = htmlspecialchars($type);
            $safeMsg = htmlspecialchars($e->getMessage());
            return '<div style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:16px 20px;margin:8px 0;border-radius:6px;font-family:monospace;font-size:13px;">'
                . '<strong>Section &ldquo;' . $safeType . '&rdquo; render error:</strong><br>' . $safeMsg
                . '</div>';
        }
    }

    /**
     * Auto-inject data-block-id and data-block-type attributes into block elements
     * in a Twig template that doesn't already include them.
     *
     * Looks for patterns like:
     *   {% if block.type == 'feature' %}
     *       <div ...>
     * and transforms the first HTML opening tag after the block type check to include
     *   data-block-id="{{ block.id }}" data-block-type="{{ block.type }}"
     */
    private function injectBlockDataAttributes(string $template): string
    {
        // Match: {% if block.type == '...' %} followed by whitespace then an opening HTML tag
        // Inject data-block-id="{{ block.id }}" data-block-type="{{ block.type }}" into that tag
        return preg_replace_callback(
            '/(\{%[-\s]*if\s+block\.type\s*==\s*[\'"][^\'"]+[\'"]\s*[-\s]*%\}\s*)(<(\w+)\b)/s',
            function ($matches) {
                $before = $matches[1];   // The {% if block.type == '...' %} + whitespace
                $tagStart = $matches[2]; // e.g. <div or <details
                $tagName = $matches[3];  // e.g. div or details

                return $before . '<' . $tagName . ' data-block-id="{{ block.id }}" data-block-type="{{ block.type }}"';
            },
            $template
        );
    }

    /**
     * Generate a unique section ID.
     */
    public function generateSectionId(string $type): string
    {
        return $type . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
