<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

class ThemeManager
{
    private string $themesBasePath;
    private ?array $themeConfig = null;

    public function __construct()
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $viewsPath = $_ENV['VIEWS_PATH'] ?? 'pages';
        $this->themesBasePath = $basePath . '/' . $viewsPath . '/themes';
    }

    public function getActiveTheme(): string
    {
        return $_ENV['CMS_THEME'] ?? 'default';
    }

    public function getActiveThemePath(): string
    {
        return $this->themesBasePath . '/' . $this->getActiveTheme();
    }

    public function getThemeConfig(): array
    {
        if ($this->themeConfig !== null) {
            return $this->themeConfig;
        }

        $configFile = $this->getActiveThemePath() . '/theme.json';
        if (file_exists($configFile)) {
            $this->themeConfig = json_decode(file_get_contents($configFile), true) ?: [];
        } else {
            $this->themeConfig = [];
        }

        return $this->themeConfig;
    }

    public function listThemes(): array
    {
        $themes = [];
        if (!is_dir($this->themesBasePath)) {
            return $themes;
        }

        $dirs = scandir($this->themesBasePath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $path = $this->themesBasePath . '/' . $dir;
            if (!is_dir($path)) continue;

            $configFile = $path . '/theme.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
                $themes[$dir] = $config['name'] ?? $dir;
            } else {
                $themes[$dir] = $dir;
            }
        }

        return $themes;
    }

    public function getLayouts(): array
    {
        $config = $this->getThemeConfig();
        return $config['layouts'] ?? [];
    }

    public function getLayoutNames(): array
    {
        $layouts = $this->getLayouts();
        $names = [];
        foreach ($layouts as $key => $layout) {
            $names[$key] = is_array($layout) ? ($layout['name'] ?? $key) : $layout;
        }
        return $names;
    }

    public function getTemplatesPath(): string
    {
        return $this->getActiveThemePath() . '/templates';
    }

    public function readTemplate(string $filename): ?string
    {
        $path = $this->getTemplatesPath() . '/' . $filename;
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    public function writeTemplate(string $filename, string $content): bool
    {
        $path = $this->getTemplatesPath() . '/' . $filename;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($path, $content) !== false;
    }

    public function templateExists(string $filename): bool
    {
        return file_exists($this->getTemplatesPath() . '/' . $filename);
    }

    public function getThemesBasePath(): string
    {
        return $this->themesBasePath;
    }
}
