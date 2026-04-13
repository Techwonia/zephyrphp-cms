<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

class SectionManager
{
    private ThemeManager $themeManager;
    private array $collectedCssPaths = [];

    public function __construct(ThemeManager $themeManager)
    {
        $this->themeManager = $themeManager;
    }

    /**
     * Get companion CSS file paths collected during section rendering.
     * @return string[] Absolute file paths (deduplicated)
     */
    public function getCollectedCssPaths(): array
    {
        return array_keys($this->collectedCssPaths);
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
     * Read the theme's settings schema. Prefers the `settings_schema` key
     * inside `theme.blueprint.json`; falls back to the legacy
     * `config/settings_schema.json` file.
     */
    public function getSettingsSchema(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();

        // Prefer blueprint
        $blueprint = $this->themeManager->getThemeConfig($slug);
        if (!empty($blueprint['settings_schema']) && is_array($blueprint['settings_schema'])) {
            return $blueprint['settings_schema'];
        }

        // Legacy fallback
        $legacy = $this->themeManager->getThemePath($slug) . '/config/settings_schema.json';
        if (file_exists($legacy)) {
            $data = json_decode(file_get_contents($legacy), true);
            return is_array($data) ? $data : [];
        }

        return [];
    }

    // --- Global settings (theme.settings.json) ---

    /**
     * Path to the theme's global-settings file. Holds the live values for
     * the schema defined in theme.blueprint.json (or legacy settings_schema.json).
     */
    private function getGlobalSettingsPath(string $slug): string
    {
        return $this->themeManager->getThemePath($slug) . '/theme.settings.json';
    }

    /**
     * Read the raw current-values map from theme.settings.json.
     * Returns an empty array if the file is missing or invalid.
     */
    private function readGlobalSettingsFile(string $slug): array
    {
        $path = $this->getGlobalSettingsPath($slug);
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeGlobalSettingsFile(string $slug, array $values): bool
    {
        $path = $this->getGlobalSettingsPath($slug);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $payload = empty($values) ? new \stdClass() : $values;
        return file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    // --- Per-page section data (pages/{tpl}/{tpl}.json) ---

    private function readPageJson(string $slug, string $template): array
    {
        $path = $this->themeManager->getPageJsonPath($slug, $template);
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function writePageJson(string $slug, string $template, array $data): bool
    {
        $path = $this->themeManager->getPageJsonPath($slug, $template);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Normalise empty collections so JSON encodes as objects, not arrays
        if (isset($data['sections']) && empty($data['sections'])) {
            $data['sections'] = new \stdClass();
        } elseif (isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as &$section) {
                if (!is_array($section)) continue;
                if (isset($section['settings']) && empty($section['settings'])) {
                    $section['settings'] = new \stdClass();
                }
                if (isset($section['blocks']) && empty($section['blocks'])) {
                    $section['blocks'] = new \stdClass();
                } elseif (isset($section['blocks']) && is_array($section['blocks'])) {
                    foreach ($section['blocks'] as &$block) {
                        if (is_array($block) && isset($block['settings']) && empty($block['settings'])) {
                            $block['settings'] = new \stdClass();
                        }
                    }
                    unset($block);
                }
            }
            unset($section);
        }

        return file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    // --- Legacy compatibility shims ---
    //
    // Older controllers (ThemeCustomizerController, ThemeController) still
    // call getSettingsData / saveSettingsData with the old flat shape:
    //   { current: {...}, pages: { tpl: { sections, order, header_order, footer_order } } }
    // These shims synthesise that shape from the new split storage so those
    // callers keep working without modification.

    /**
     * Assemble the legacy settings_data shape from the new split files.
     */
    public function getSettingsData(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();

        $pages = [];
        foreach ($this->themeManager->getPages($slug) as $page) {
            $tpl = $page['template'] ?? null;
            if (!$tpl) continue;
            $pages[$tpl] = [
                'sections' => $page['sections'] ?? [],
                'order' => $page['order'] ?? [],
                'header_order' => $page['header_order'] ?? [],
                'footer_order' => $page['footer_order'] ?? [],
            ];
        }

        return [
            'current' => $this->readGlobalSettingsFile($slug),
            'pages' => $pages,
        ];
    }

    /**
     * Persist the legacy settings_data shape by splitting it back into the
     * new files. `current` → theme.settings.json; each `pages[tpl]` merges
     * into the existing pages/{tpl}/{tpl}.json (preserving metadata like
     * title/slug/layout/controller).
     */
    public function saveSettingsData(string $slug, array $data): bool
    {
        $this->writeGlobalSettingsFile($slug, $data['current'] ?? []);

        $pages = $data['pages'] ?? [];
        if (is_array($pages)) {
            foreach ($pages as $template => $pageData) {
                if (!is_array($pageData)) continue;
                $existing = $this->readPageJson($slug, $template);
                $merged = array_merge($existing, [
                    'sections' => $pageData['sections'] ?? [],
                    'order' => $pageData['order'] ?? [],
                    'header_order' => $pageData['header_order'] ?? [],
                    'footer_order' => $pageData['footer_order'] ?? [],
                ]);
                $this->writePageJson($slug, (string) $template, $merged);
            }
        }

        return true;
    }

    // --- Page Sections (new primary API) ---

    /**
     * Get section configuration for a specific page template.
     */
    public function getPageSections(?string $slug, string $pageTemplate): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $page = $this->readPageJson($slug, $pageTemplate);

        return [
            'sections' => $page['sections'] ?? [],
            'order' => $page['order'] ?? [],
            'header_order' => $page['header_order'] ?? [],
            'footer_order' => $page['footer_order'] ?? [],
        ];
    }

    /**
     * Save section configuration for a specific page template. Preserves
     * any non-section metadata (title, slug, layout, controller, etc.)
     * already present in the page's JSON.
     */
    public function savePageSections(string $slug, string $pageTemplate, array $sections, array $order): void
    {
        $existing = $this->readPageJson($slug, $pageTemplate);
        $merged = array_merge($existing, [
            'sections' => empty($sections) ? new \stdClass() : $sections,
            'order' => $order,
        ]);
        $this->writePageJson($slug, $pageTemplate, $merged);
    }

    // --- Global Settings (new primary API) ---

    /**
     * Get the current global theme settings (merged with defaults from schema).
     */
    public function getGlobalSettings(?string $slug = null): array
    {
        $slug = $slug ?? $this->themeManager->getEffectiveTheme();
        $schema = $this->getSettingsSchema($slug);
        $current = $this->readGlobalSettingsFile($slug);

        // Merge schema defaults with saved values
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
        $this->writeGlobalSettingsFile($slug, $settings);
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
                    if ($id && !isset($settings[$id])) {
                        $settings[$id] = $schemaSetting['default'] ?? '';
                    }
                }
            }

            // Build ordered blocks array
            $orderedBlocks = [];
            foreach ($blockOrder as $blockId) {
                if (isset($blocks[$blockId])) {
                    $block = $blocks[$blockId];
                    $block['id'] = $blockId;

                    // Merge block schema defaults — ensure all defined settings exist
                    if ($schema && isset($schema['blocks'])) {
                        foreach ($schema['blocks'] as $blockSchema) {
                            if (($blockSchema['type'] ?? '') === ($block['type'] ?? '')) {
                                foreach ($blockSchema['settings'] ?? [] as $bs) {
                                    $bsId = $bs['id'] ?? null;
                                    if ($bsId && !isset($block['settings'][$bsId])) {
                                        $block['settings'][$bsId] = $bs['default'] ?? '';
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
                    if ($id && !isset($settings[$id])) {
                        $settings[$id] = $schemaSetting['default'] ?? '';
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
                                    if ($bsId && !isset($block['settings'][$bsId])) {
                                        $block['settings'][$bsId] = $bs['default'] ?? '';
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

            // Collect companion CSS path for bundling
            $themeSlug = $slug ?? $this->themeManager->getEffectiveTheme();
            $assetsPath = $this->themeManager->getThemeAssetsPath($themeSlug);
            $cssFile = $assetsPath . '/css/sections/' . $type . '.css';
            if (file_exists($cssFile)) {
                $this->collectedCssPaths[$cssFile] = true;
            }

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
