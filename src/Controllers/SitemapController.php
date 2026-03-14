<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\ThemeManager;

class SitemapController extends Controller
{
    public function index(): string
    {
        $schema = new SchemaManager();
        $urls = [];

        // Add homepage
        $baseUrl = $this->getBaseUrl();
        $urls[] = [
            'loc' => $baseUrl . '/',
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        // Add theme pages (from pages.json)
        try {
            $themeManager = new ThemeManager();
            $pages = $themeManager->getPages();
            foreach ($pages as $page) {
                $pageSlug = $page['slug'] ?? '';
                // Skip dynamic routes (containing {params})
                if (str_contains($pageSlug, '{')) continue;
                if ($pageSlug === '/' || empty($pageSlug)) continue;

                $urls[] = [
                    'loc' => $baseUrl . '/' . ltrim($pageSlug, '/'),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ];
            }
        } catch (\Exception $e) {
            // pages.json might not exist
        }

        // Add collection entries that have slugs and are publishable
        try {
            $collections = Collection::findAll();
            foreach ($collections as $collection) {
                if (!$collection->hasSlug()) continue;

                $tableName = $collection->getTableName();
                if (!$schema->tableExists($tableName)) continue;

                $conn = $schema->getConnection();

                // Select SEO columns if available
                $selectCols = 'slug, updated_at';
                if ($collection->isSeoEnabled()) {
                    $selectCols .= ', meta_title, robots';
                }

                $qb = $conn->createQueryBuilder()
                    ->select($selectCols)
                    ->from($tableName);

                if ($collection->isPublishable()) {
                    $qb->where('status = :status')->setParameter('status', 'published');
                }

                $entries = $qb->executeQuery()->fetchAllAssociative();

                // Determine URL prefix for this collection
                $urlPrefix = $collection->getUrlPrefix();
                $collPath = $urlPrefix ? ltrim($urlPrefix, '/') : $collection->getSlug();

                foreach ($entries as $entry) {
                    if (empty($entry['slug'])) continue;

                    // Skip entries with noindex robots directive
                    $robots = $entry['robots'] ?? '';
                    if (str_contains($robots, 'noindex')) continue;

                    // Determine priority based on recency
                    $priority = '0.5';
                    if (!empty($entry['updated_at'])) {
                        $daysSinceUpdate = (time() - strtotime($entry['updated_at'])) / 86400;
                        if ($daysSinceUpdate < 7) {
                            $priority = '0.8';
                        } elseif ($daysSinceUpdate < 30) {
                            $priority = '0.6';
                        }
                    }

                    // Determine changefreq based on update recency
                    $changefreq = 'monthly';
                    if (!empty($entry['updated_at'])) {
                        $daysSinceUpdate = $daysSinceUpdate ?? ((time() - strtotime($entry['updated_at'])) / 86400);
                        if ($daysSinceUpdate < 7) {
                            $changefreq = 'daily';
                        } elseif ($daysSinceUpdate < 30) {
                            $changefreq = 'weekly';
                        }
                    }

                    $url = [
                        'loc' => $baseUrl . '/' . $collPath . '/' . $entry['slug'],
                        'changefreq' => $changefreq,
                        'priority' => $priority,
                    ];
                    if (!empty($entry['updated_at'])) {
                        $url['lastmod'] = date('Y-m-d', strtotime($entry['updated_at']));
                    }
                    $urls[] = $url;
                }
            }
        } catch (\Exception $e) {
            // Collections might not exist yet
        }

        // Generate XML
        header('Content-Type: application/xml; charset=utf-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc']) . '</loc>' . "\n";
            if (isset($url['lastmod'])) {
                $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            }
            if (isset($url['changefreq'])) {
                $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            }
            if (isset($url['priority'])) {
                $xml .= '    <priority>' . $url['priority'] . '</priority>' . "\n";
            }
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        echo $xml;
        exit;
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
