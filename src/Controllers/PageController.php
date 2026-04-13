<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Cms\Services\ThemeManager;
use ZephyrPHP\Cms\Services\SectionManager;
use ZephyrPHP\Cms\Services\AssetBundler;
use ZephyrPHP\View\View;
use ZephyrPHP\Auth\Auth;

/**
 * Renders public-facing theme pages declared in `pages.json` / future
 * per-page JSON. Invoked from the route closures registered by
 * CmsServiceProvider::registerThemePageRoutes() — the closures pass the
 * resolved page descriptor + runtime params, this class does the rest.
 */
class PageController
{
    private ThemeManager $themeManager;
    private ?SectionManager $sectionManager = null;

    public function __construct(?ThemeManager $themeManager = null)
    {
        $this->themeManager = $themeManager ?? ThemeManager::getInstance();
    }

    /**
     * Render a theme page.
     *
     * @param array $page    Page descriptor from the theme registry. Keys:
     *                       template, slug, title, layout, controller,
     *                       auth_required, allowed_roles.
     * @param array $params  Route + query params captured at dispatch.
     */
    public function show(array $page, array $params = []): void
    {
        if (!empty($page['auth_required'])) {
            $this->enforceAuth($page['allowed_roles'] ?? []);
        }

        $template = $page['template'];
        $title = $page['title'] ?? '';
        $layout = $page['layout'] ?? 'base';
        $controllerName = $page['controller'] ?? null;
        $routeSlug = $page['slug'] ?? '/';

        $view = View::getInstance();
        $sectionManager = new SectionManager($this->themeManager);
        $this->sectionManager = $sectionManager;

        // User controller hook — theme-local PHP handler that can return extra view vars
        $controllerData = [];
        if ($controllerName) {
            $controllerPath = $this->themeManager->getActiveThemePath()
                . '/controllers/' . $controllerName . '.php';
            if (file_exists($controllerPath)) {
                $handler = require $controllerPath;
                if (is_callable($handler)) {
                    $result = $handler($params);
                    if (is_array($result)) {
                        $controllerData = $result;
                    }
                }
            }
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $pageData = array_merge($controllerData, [
            'page' => array_merge([
                'title' => $title,
                'template' => $template,
                'slug' => $routeSlug,
                'url' => $requestPath,
            ], $controllerData['page'] ?? []),
            'params' => $params,
            'theme_settings' => $sectionManager->getGlobalSettings(),
        ]);

        if ($sectionManager->hasSections(null, $template)) {
            $sectionsHtml = $sectionManager->renderSections($template);
            $pageData['sections_html'] = $sectionsHtml;
            $pageData['use_sections'] = true;
            $html = $view->render('@theme/layouts/' . $layout, $pageData);
        } else {
            $html = $view->render('@theme/templates/' . $template, $pageData);
        }

        $html = $this->injectPageBundles($html, $template);

        echo $html;
    }

    /**
     * Enforce role-based access control on a page. AuthMiddleware already
     * handles the redirect-to-login — this adds role gating on top.
     */
    public function enforceAuth(array $allowedRoles): void
    {
        if (empty($allowedRoles)) {
            return;
        }

        try {
            $user = Auth::user();
            if (!$user || !method_exists($user, 'hasAnyRole')) {
                return;
            }

            if ($user->hasAnyRole($allowedRoles)) {
                return;
            }

            $this->render403();
            exit;
        } catch (\Throwable $e) {
            // Auth stack not ready — let the request through rather than crashing
        }
    }

    /**
     * Inject per-page bundled CSS/JS into the rendered HTML. Bundles are
     * built from section companions plus any theme `assets/css/{tpl}.css`
     * and `assets/js/{tpl}.js`.
     */
    private function injectPageBundles(string $html, string $template): string
    {
        $themeSlug = $this->themeManager->getEffectiveTheme();
        $assetsPath = $this->themeManager->getThemeAssetsPath($themeSlug);
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $bundler = new AssetBundler($basePath . '/public', $themeSlug);

        // Reuse the SectionManager built during show() so its collected-CSS
        // state reflects the sections just rendered.
        $sectionManager = $this->sectionManager ?? new SectionManager($this->themeManager);

        $cssFiles = $sectionManager->getCollectedCssPaths();
        $pageCssFile = $assetsPath . '/css/' . $template . '.css';
        if (file_exists($pageCssFile)) {
            $cssFiles[] = $pageCssFile;
        }

        $jsFiles = [];
        $pageJsFile = $assetsPath . '/js/' . $template . '.js';
        if (file_exists($pageJsFile)) {
            $jsFiles[] = $pageJsFile;
        }

        $bundleName = 'page-' . preg_replace('/[^a-z0-9_-]/i', '-', $template);

        $cssBundleUrl = $bundler->bundleCss($cssFiles, $bundleName);
        if ($cssBundleUrl) {
            $cssTag = '<link rel="stylesheet" href="' . htmlspecialchars($cssBundleUrl) . '">';
            $html = str_replace('</head>', $cssTag . "\n</head>", $html);
        }

        $jsBundleUrl = $bundler->bundleJs($jsFiles, $bundleName);
        if ($jsBundleUrl) {
            $jsTag = '<script src="' . htmlspecialchars($jsBundleUrl) . '" defer></script>';
            $html = str_replace('</body>', $jsTag . "\n</body>", $html);
        }

        return $html;
    }

    private function render403(): void
    {
        http_response_code(403);
        $view = View::getInstance();
        if ($view->exists('errors/403')) {
            echo $view->render('errors/403', []);
            return;
        }
        if ($view->exists('@errors/403')) {
            echo $view->render('@errors/403', []);
            return;
        }
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body>'
            . '<div style="max-width:600px;margin:4rem auto;text-align:center;font-family:system-ui;">'
            . '<h1>403 — Access Denied</h1>'
            . '<p>You do not have permission to view this page.</p>'
            . '<a href="/">Go Home</a>'
            . '</div></body></html>';
    }
}
