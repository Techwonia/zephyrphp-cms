<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use ZephyrPHP\Asset\Asset;
use ZephyrPHP\Cms\Services\ThemeManager;

/**
 * Twig Extension for theme asset management.
 *
 * Reads asset declarations from theme.json and outputs properly formatted HTML.
 * Supports defer, async, preload, preconnect, crossorigin, integrity, CSP.
 *
 * All-in-one functions (recommended):
 *   {{ assets_head()|raw }}   — preconnects + preloads + external CSS + theme CSS
 *   {{ assets_footer()|raw }} — external JS + theme JS
 *   {{ assets_csp()|raw }}    — Content-Security-Policy <meta> tag
 *
 * Individual functions:
 *   {{ assets_css()|raw }}          — theme CSS only
 *   {{ assets_js()|raw }}           — theme JS only
 *   {{ assets_preloads()|raw }}     — <link rel="preload"> only
 *   {{ assets_preconnects()|raw }}  — <link rel="preconnect"> only
 *   {{ assets_external_css()|raw }} — external CDN stylesheets only
 *   {{ assets_external_js()|raw }}  — external CDN scripts only
 */
class ThemeAssetExtension extends AbstractExtension
{
    public function __construct(
        private ThemeManager $themeManager
    ) {}

    public function getFunctions(): array
    {
        return [
            // All-in-one helpers
            new TwigFunction('assets_head', [$this, 'assetsHead'], ['is_safe' => ['html']]),
            new TwigFunction('assets_footer', [$this, 'assetsFooter'], ['is_safe' => ['html']]),
            new TwigFunction('assets_csp', [$this, 'assetsCsp'], ['is_safe' => ['html']]),

            // Individual asset type helpers
            new TwigFunction('assets_css', [$this, 'assetsCss'], ['is_safe' => ['html']]),
            new TwigFunction('assets_js', [$this, 'assetsJs'], ['is_safe' => ['html']]),
            new TwigFunction('assets_preloads', [$this, 'assetsPreloads'], ['is_safe' => ['html']]),
            new TwigFunction('assets_preconnects', [$this, 'assetsPreconnects'], ['is_safe' => ['html']]),
            new TwigFunction('assets_external_css', [$this, 'assetsExternalCss'], ['is_safe' => ['html']]),
            new TwigFunction('assets_external_js', [$this, 'assetsExternalJs'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * All-in-one <head> output: preconnects + preloads + external CSS + theme CSS.
     */
    public function assetsHead(): string
    {
        $config = $this->getAssetsConfig();
        $html = '';

        $html .= $this->renderPreconnects($config);
        $html .= $this->renderPreloads($config);
        $html .= $this->renderExternalCss($config);
        $html .= $this->renderCss($config);

        return $html;
    }

    /**
     * All-in-one footer output: external JS + theme JS.
     */
    public function assetsFooter(): string
    {
        $config = $this->getAssetsConfig();
        $html = '';

        $html .= $this->renderExternalJs($config);
        $html .= $this->renderJs($config);

        return $html;
    }

    /**
     * Content-Security-Policy <meta> tag.
     */
    public function assetsCsp(): string
    {
        $config = $this->getAssetsConfig();
        $csp = $config['csp'] ?? [];
        if (empty($csp)) {
            return '';
        }

        $directives = [];
        foreach ($csp as $directive => $value) {
            $directives[] = htmlspecialchars($directive, ENT_QUOTES, 'UTF-8')
                . ' ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return '<meta http-equiv="Content-Security-Policy" content="' . implode('; ', $directives) . '">';
    }

    /**
     * Theme CSS files with attribute support (media, crossorigin, integrity).
     */
    public function assetsCss(): string
    {
        return $this->renderCss($this->getAssetsConfig());
    }

    /**
     * Theme JS files with attribute support (defer, async, type, crossorigin, integrity).
     */
    public function assetsJs(): string
    {
        return $this->renderJs($this->getAssetsConfig());
    }

    /**
     * Preload link tags for fonts, critical CSS/JS.
     */
    public function assetsPreloads(): string
    {
        return $this->renderPreloads($this->getAssetsConfig());
    }

    /**
     * Preconnect link tags for external domains.
     */
    public function assetsPreconnects(): string
    {
        return $this->renderPreconnects($this->getAssetsConfig());
    }

    /**
     * External CDN stylesheets.
     */
    public function assetsExternalCss(): string
    {
        return $this->renderExternalCss($this->getAssetsConfig());
    }

    /**
     * External CDN scripts.
     */
    public function assetsExternalJs(): string
    {
        return $this->renderExternalJs($this->getAssetsConfig());
    }

    // ========================================================================
    // PRIVATE RENDERERS
    // ========================================================================

    private function getAssetsConfig(): array
    {
        $slug = $this->themeManager->getEffectiveTheme();
        $config = $this->themeManager->getThemeConfig($slug);
        return $config['assets'] ?? [];
    }

    private function cleanPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }
        return $path;
    }

    private function renderCss(array $config): string
    {
        $html = '';
        foreach ($config['css'] ?? [] as $entry) {
            $path = is_string($entry) ? $entry : ($entry['path'] ?? '');
            if (empty($path)) continue;

            $attrs = [];
            if (is_array($entry)) {
                if (!empty($entry['media'])) $attrs['media'] = $entry['media'];
                if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $this->normalizeCrossorigin($entry['crossorigin']);
                if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
            }
            $html .= Asset::css($this->cleanPath($path), $attrs);
        }
        return $html;
    }

    private function renderJs(array $config): string
    {
        $html = '';
        foreach ($config['js'] ?? [] as $entry) {
            $path = is_string($entry) ? $entry : ($entry['path'] ?? '');
            if (empty($path)) continue;

            $attrs = [];
            if (is_array($entry)) {
                if (!empty($entry['defer'])) $attrs['defer'] = true;
                if (!empty($entry['async'])) $attrs['async'] = true;
                if (!empty($entry['type'])) $attrs['type'] = $entry['type'];
                if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $this->normalizeCrossorigin($entry['crossorigin']);
                if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
            }
            $html .= Asset::js($this->cleanPath($path), $attrs);
        }
        return $html;
    }

    private function renderPreloads(array $config): string
    {
        $html = '';
        foreach ($config['preload'] ?? [] as $entry) {
            $path = $entry['path'] ?? '';
            if (empty($path)) continue;

            $as = $entry['as'] ?? 'fetch';
            $attrs = [];
            if (!empty($entry['type'])) $attrs['type'] = $entry['type'];
            if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $this->normalizeCrossorigin($entry['crossorigin']);

            $html .= Asset::preload($this->cleanPath($path), $as, $attrs) . "\n";
        }
        return $html;
    }

    private function renderPreconnects(array $config): string
    {
        $html = '';
        foreach ($config['preconnect'] ?? [] as $entry) {
            $url = $entry['url'] ?? '';
            if (empty($url)) continue;
            $html .= Asset::preconnect($url, !empty($entry['crossorigin'])) . "\n";
        }
        return $html;
    }

    private function renderExternalCss(array $config): string
    {
        $html = '';
        foreach ($config['external_css'] ?? [] as $entry) {
            $url = is_string($entry) ? $entry : ($entry['url'] ?? '');
            if (empty($url)) continue;

            $attrs = [];
            if (is_array($entry)) {
                if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $this->normalizeCrossorigin($entry['crossorigin']);
                if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
                if (!empty($entry['media'])) $attrs['media'] = $entry['media'];
            }
            $html .= Asset::externalCss($url, $attrs) . "\n";
        }
        return $html;
    }

    private function renderExternalJs(array $config): string
    {
        $html = '';
        foreach ($config['external_js'] ?? [] as $entry) {
            $url = is_string($entry) ? $entry : ($entry['url'] ?? '');
            if (empty($url)) continue;

            $attrs = [];
            if (is_array($entry)) {
                if (!empty($entry['defer'])) $attrs['defer'] = true;
                if (!empty($entry['async'])) $attrs['async'] = true;
                if (!empty($entry['type'])) $attrs['type'] = $entry['type'];
                if (!empty($entry['crossorigin'])) $attrs['crossorigin'] = $this->normalizeCrossorigin($entry['crossorigin']);
                if (!empty($entry['integrity'])) $attrs['integrity'] = $entry['integrity'];
            }
            $html .= Asset::externalJs($url, $attrs) . "\n";
        }
        return $html;
    }

    private function normalizeCrossorigin(mixed $value): string
    {
        return $value === true ? 'anonymous' : (string) $value;
    }
}
