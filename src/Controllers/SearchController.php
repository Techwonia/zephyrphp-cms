<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\EntryQuery;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\SchemaManager;

class SearchController extends Controller
{
    /**
     * Global search endpoint — returns JSON results across entries, media, and collections.
     */
    public function search(): string
    {
        if (!Auth::check()) {
            http_response_code(401);
            return json_encode(['results' => []]);
        }

        $query = trim($this->input('q', ''));

        header('Content-Type: application/json');

        if (mb_strlen($query) < 2) {
            return json_encode(['results' => []]);
        }

        // Sanitize: strip any control characters
        $query = preg_replace('/[\x00-\x1F\x7F]/', '', $query);
        if ($query === '') {
            return json_encode(['results' => []]);
        }

        $results = [];
        $limit = 5; // per category

        // 1. Search entries across all collections
        if (PermissionService::can('entries.view')) {
            $results = array_merge($results, $this->searchEntries($query, $limit));
        }

        // 2. Search media files
        if (PermissionService::can('media.view')) {
            $results = array_merge($results, $this->searchMedia($query, $limit));
        }

        // 3. Search collections by name
        $results = array_merge($results, $this->searchCollections($query, $limit));

        return json_encode(['results' => $results]);
    }

    private function searchEntries(string $query, int $limit): array
    {
        $results = [];
        $schema = new SchemaManager();

        try {
            $collections = Collection::findAll();

            foreach ($collections as $collection) {
                $tableName = $collection->getTableName();
                if (!$schema->tableExists($tableName)) {
                    continue;
                }

                try {
                    $entries = EntryQuery::for($collection->getSlug())
                        ->search($query)
                        ->limit($limit)
                        ->get();

                    foreach ($entries as $entry) {
                        $title = $entry['title'] ?? $entry['name'] ?? $entry['heading'] ?? "#{$entry['id']}";
                        $results[] = [
                            'type'  => 'entry',
                            'title' => mb_substr((string) $title, 0, 100),
                            'meta'  => $collection->getName(),
                            'url'   => "/cms/collections/{$collection->getSlug()}/entries/{$entry['id']}",
                            'icon'  => $collection->getIcon() ?: 'file',
                        ];

                        if (count($results) >= $limit) {
                            break 2;
                        }
                    }
                } catch (\Exception $e) {
                    // Skip collections that fail (e.g. missing columns)
                    continue;
                }
            }
        } catch (\Exception $e) {
            // Database not available
        }

        return $results;
    }

    private function searchMedia(string $query, int $limit): array
    {
        $results = [];

        try {
            $conn = (new SchemaManager())->getConnection();
            $qb = $conn->createQueryBuilder()
                ->select('id', 'original_name', 'filename', 'mime_type')
                ->from('cms_media')
                ->where('LOWER(original_name) LIKE :q')
                ->orWhere('LOWER(alt_text) LIKE :q')
                ->setParameter('q', '%' . strtolower($query) . '%')
                ->setMaxResults($limit)
                ->orderBy('id', 'DESC');

            $rows = $qb->executeQuery()->fetchAllAssociative();

            foreach ($rows as $row) {
                $results[] = [
                    'type'  => 'media',
                    'title' => mb_substr($row['original_name'] ?: $row['filename'], 0, 100),
                    'meta'  => $row['mime_type'] ?? 'File',
                    'url'   => "/cms/media/{$row['id']}",
                    'icon'  => 'image',
                ];
            }
        } catch (\Exception $e) {
            // Media table may not exist
        }

        return $results;
    }

    private function searchCollections(string $query, int $limit): array
    {
        $results = [];
        $lower = strtolower($query);

        try {
            $collections = Collection::findAll();

            foreach ($collections as $collection) {
                if (
                    str_contains(strtolower($collection->getName()), $lower) ||
                    str_contains(strtolower($collection->getSlug()), $lower)
                ) {
                    $results[] = [
                        'type'  => 'collection',
                        'title' => $collection->getName(),
                        'meta'  => 'Collection',
                        'url'   => "/cms/collections/{$collection->getSlug()}/entries",
                        'icon'  => $collection->getIcon() ?: 'folder',
                    ];

                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Collections unavailable
        }

        return $results;
    }
}
