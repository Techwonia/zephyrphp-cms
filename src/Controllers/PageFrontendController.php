<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\PageType;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;

class PageFrontendController extends Controller
{
    private SchemaManager $schema;
    private ThemeManager $themeManager;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
        $this->themeManager = new ThemeManager();
    }

    /**
     * Dynamic page listing (e.g. /blog)
     */
    public function listing(string $ptSlug): string
    {
        $pageType = PageType::findOneBy(['slug' => $ptSlug]);
        if (!$pageType) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $tableName = $pageType->getTableName();
        if (!$this->schema->tableExists($tableName)) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $options = [
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => $pageType->getItemsPerPage(),
            'sort_by' => 'id',
            'sort_dir' => 'DESC',
            'filters' => ['status' => 'published'],
        ];

        $entries = $this->schema->listEntries($tableName, $options);

        return $this->renderThemeTemplate($pageType, [
            'page' => null,
            'pageType' => $pageType,
            'entries' => $entries,
            'seo' => [
                'title' => $pageType->getName(),
                'description' => $pageType->getDescription() ?? '',
                'image' => '',
            ],
        ]);
    }

    /**
     * Dynamic page detail (e.g. /blog/my-post)
     */
    public function detail(string $ptSlug, string $slug): string
    {
        $pageType = PageType::findOneBy(['slug' => $ptSlug]);
        if (!$pageType) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $tableName = $pageType->getTableName();
        if (!$this->schema->tableExists($tableName)) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $conn = $this->schema->getConnection();
        $entry = $conn->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('slug = :slug')
            ->andWhere('status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', 'published')
            ->executeQuery()
            ->fetchAssociative();

        if (!$entry) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $templateSlug = $pageType->getTemplate();
        $detailTemplate = $templateSlug . '_detail';

        return $this->renderThemeTemplate($pageType, [
            'page' => $entry,
            'pageType' => $pageType,
            'seo' => [
                'title' => $entry['seo_title'] ?? $entry['title'] ?? '',
                'description' => $entry['seo_description'] ?? '',
                'image' => $entry['seo_image'] ?? '',
            ],
        ], $detailTemplate);
    }

    /**
     * Static page (e.g. /about or /about/team)
     */
    public function staticPage(string $ptSlug, ?string $slug = null): string
    {
        $pageType = PageType::findOneBy(['slug' => $ptSlug]);
        if (!$pageType) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $tableName = $pageType->getTableName();
        if (!$this->schema->tableExists($tableName)) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        $conn = $this->schema->getConnection();

        if ($slug) {
            // Specific page entry
            $entry = $conn->createQueryBuilder()
                ->select('*')
                ->from($tableName)
                ->where('slug = :slug')
                ->setParameter('slug', $slug)
                ->executeQuery()
                ->fetchAssociative();
        } else {
            // First/only page entry for this page type
            $entry = $conn->createQueryBuilder()
                ->select('*')
                ->from($tableName)
                ->where('status = :status')
                ->setParameter('status', 'published')
                ->orderBy('id', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!$entry) {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        // Check published status
        if ($pageType->isPublishable() && ($entry['status'] ?? 'draft') !== 'published') {
            http_response_code(404);
            return $this->render('errors/404', []);
        }

        return $this->renderThemeTemplate($pageType, [
            'page' => $entry,
            'pageType' => $pageType,
            'seo' => [
                'title' => $entry['seo_title'] ?? $entry['title'] ?? '',
                'description' => $entry['seo_description'] ?? '',
                'image' => $entry['seo_image'] ?? '',
            ],
        ]);
    }

    /**
     * Legacy route: /page/{slug} — backward compatibility
     */
    public function show(string $slug): string
    {
        $pageTypes = PageType::findAll();

        foreach ($pageTypes as $pageType) {
            if (!$this->schema->tableExists($pageType->getTableName())) {
                continue;
            }

            $conn = $this->schema->getConnection();
            $entry = $conn->createQueryBuilder()
                ->select('*')
                ->from($pageType->getTableName())
                ->where('slug = :slug')
                ->setParameter('slug', $slug)
                ->executeQuery()
                ->fetchAssociative();

            if ($entry) {
                if ($pageType->isPublishable() && ($entry['status'] ?? 'draft') !== 'published') {
                    continue;
                }

                return $this->renderThemeTemplate($pageType, [
                    'page' => $entry,
                    'pageType' => $pageType,
                    'seo' => [
                        'title' => $entry['seo_title'] ?? $entry['title'] ?? '',
                        'description' => $entry['seo_description'] ?? '',
                        'image' => $entry['seo_image'] ?? '',
                    ],
                ]);
            }
        }

        http_response_code(404);
        return $this->render('errors/404', []);
    }

    /**
     * Render using theme template with fallback
     */
    private function renderThemeTemplate(PageType $pageType, array $data, ?string $overrideTemplate = null): string
    {
        $templateSlug = $overrideTemplate ?? $pageType->getTemplate();
        $view = \ZephyrPHP\View\View::getInstance();

        // Try theme template first: @theme/templates/{slug}.twig
        $themeTemplate = "theme::templates/{$templateSlug}";
        if ($view->exists($themeTemplate)) {
            return $this->render($themeTemplate, $data);
        }

        // Fallback: templates/{slug} in the default views path
        $fallback = "templates/{$templateSlug}";
        if ($view->exists($fallback)) {
            return $this->render($fallback, $data);
        }

        // Last resort: generate a basic page when no template exists
        return $this->renderDefaultPage($pageType, $data);
    }

    /**
     * Generate a basic rendered page when no template exists
     */
    private function renderDefaultPage(PageType $pageType, array $data): string
    {
        $page = $data['page'] ?? null;
        $entries = $data['entries'] ?? null;

        $html = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . htmlspecialchars($data['seo']['title'] ?? $pageType->getName()) . '</title>';
        $html .= '</head><body>';
        $html .= '<div style="max-width:800px;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif;">';

        if ($page) {
            $html .= '<h1>' . htmlspecialchars($page['title'] ?? '') . '</h1>';
            foreach ($pageType->getFields() as $field) {
                $val = $page[$field->getSlug()] ?? '';
                if ($val) {
                    $html .= '<div style="margin:1rem 0;"><strong>' . htmlspecialchars($field->getName()) . ':</strong> ';
                    $html .= htmlspecialchars((string) $val) . '</div>';
                }
            }
        } elseif ($entries) {
            $html .= '<h1>' . htmlspecialchars($pageType->getName()) . '</h1>';
            foreach ($entries['data'] as $entry) {
                $html .= '<article style="margin:1.5rem 0;padding:1rem;border:1px solid #eee;border-radius:8px;">';
                $url = $pageType->getPublicUrl($entry['slug'] ?? '');
                $html .= '<h2><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($entry['title'] ?? '') . '</a></h2>';
                $html .= '</article>';
            }
        }

        $html .= '</div></body></html>';
        return $html;
    }
}
