<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Models\Collection;

class RelationshipGraphController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('collections.view');

        // Load all collections
        $collections = Collection::findBy([], ['name' => 'ASC']);

        // Build graph data: nodes (collections) and edges (relations)
        $nodes = [];
        $edges = [];

        foreach ($collections as $collection) {
            $nodes[] = [
                'id' => $collection->getSlug(),
                'name' => $collection->getName(),
                'fieldCount' => $collection->getFields()->count(),
                'icon' => $collection->getIcon() ?? 'database',
            ];

            foreach ($collection->getFields() as $field) {
                $type = $field->getType();
                $options = $field->getOptions() ?? [];

                if ($type === 'relation') {
                    $targetCollection = $options['relation_collection'] ?? $options['collection'] ?? null;
                    $relationType = $options['relation_type'] ?? 'one_to_one';

                    if ($targetCollection) {
                        $edges[] = [
                            'from' => $collection->getSlug(),
                            'to' => $targetCollection,
                            'field' => $field->getName(),
                            'type' => str_replace('_', '-', $relationType),
                        ];
                    }
                } elseif (in_array($type, ['image', 'file'])) {
                    $isMultiple = !empty($options['multiple']);
                    $edges[] = [
                        'from' => $collection->getSlug(),
                        'to' => '__media__',
                        'field' => $field->getName(),
                        'type' => $isMultiple ? 'many-to-many' : 'one-to-one',
                    ];
                }
            }
        }

        // Add Media node if any image/file edges exist
        $hasMediaEdge = false;
        foreach ($edges as $edge) {
            if ($edge['to'] === '__media__') {
                $hasMediaEdge = true;
                break;
            }
        }
        if ($hasMediaEdge) {
            $nodes[] = [
                'id' => '__media__',
                'name' => 'Media Library',
                'fieldCount' => 0,
                'icon' => 'image',
                'isSystem' => true,
            ];
        }

        return $this->render('cms::relationships/index', [
            'nodes' => $nodes,
            'edges' => $edges,
            'nodesJson' => json_encode($nodes),
            'edgesJson' => json_encode($edges),
            'user' => Auth::user(),
        ]);
    }
}
