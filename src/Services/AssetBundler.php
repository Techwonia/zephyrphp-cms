<?php

namespace ZephyrPHP\Cms\Services;

class AssetBundler
{
    private string $publicPath;
    private string $themeSlug;
    private string $bundleDir;

    public function __construct(string $publicPath, string $themeSlug)
    {
        $this->publicPath = rtrim($publicPath, '/');
        $this->themeSlug = $themeSlug;
        $this->bundleDir = $this->publicPath . '/themes/' . $themeSlug . '/bundles';
    }

    /**
     * Bundle multiple CSS files into a single minified file.
     *
     * @param array $files Absolute file paths to CSS files
     * @param string $name Bundle name (e.g. "page-home", "page-blogs")
     * @return string|null URL path to the bundled file, or null if no files
     */
    public function bundleCss(array $files, string $name): ?string
    {
        return $this->bundle($files, $name, 'css');
    }

    /**
     * Bundle multiple JS files into a single minified file.
     *
     * @param array $files Absolute file paths to JS files
     * @param string $name Bundle name
     * @return string|null URL path to the bundled file, or null if no files
     */
    public function bundleJs(array $files, string $name): ?string
    {
        return $this->bundle($files, $name, 'js');
    }

    private function bundle(array $files, string $name, string $type): ?string
    {
        // Filter to only existing files
        $files = array_filter($files, 'file_exists');
        if (empty($files)) {
            return null;
        }

        // Build hash from file modification times for cache invalidation
        $hash = $this->computeHash($files);
        $bundleFileName = $name . '.' . $hash . '.' . $type;
        $bundlePath = $this->bundleDir . '/' . $bundleFileName;

        // Return cached bundle if it exists
        if (file_exists($bundlePath)) {
            return '/themes/' . $this->themeSlug . '/bundles/' . $bundleFileName;
        }

        // Ensure bundle directory exists
        if (!is_dir($this->bundleDir)) {
            mkdir($this->bundleDir, 0755, true);
        }

        // Clean old bundles with same name prefix
        $this->cleanOldBundles($name, $type);

        // Concatenate all source files
        $combined = '';
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $combined .= "/* " . basename($file) . " */\n" . $content . "\n\n";
            }
        }

        // Minify
        $minified = $this->minify($combined, $type);

        file_put_contents($bundlePath, $minified);

        return '/themes/' . $this->themeSlug . '/bundles/' . $bundleFileName;
    }

    private function computeHash(array $files): string
    {
        $data = '';
        foreach ($files as $file) {
            $data .= $file . ':' . filemtime($file) . ';';
        }
        return substr(md5($data), 0, 10);
    }

    private function cleanOldBundles(string $name, string $type): void
    {
        $pattern = $this->bundleDir . '/' . $name . '.*.' . $type;
        foreach (glob($pattern) as $oldFile) {
            @unlink($oldFile);
        }
    }

    private function minify(string $content, string $type): string
    {
        try {
            if ($type === 'css' && class_exists(\MatthiasMullie\Minify\CSS::class)) {
                $minifier = new \MatthiasMullie\Minify\CSS($content);
                return $minifier->minify();
            }
            if ($type === 'js' && class_exists(\MatthiasMullie\Minify\JS::class)) {
                $minifier = new \MatthiasMullie\Minify\JS($content);
                return $minifier->minify();
            }
        } catch (\Throwable $e) {
            // Minification failed — return unminified
        }

        return $content;
    }
}
