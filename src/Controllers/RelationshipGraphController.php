<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Models\Collection;

class RelationshipGraphController extends Controller
{
    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect(login_url());
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect(admin_url());
        }
    }

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
                if ($field->getType() === 'relation') {
                    $options = $field->getOptions();
                    $targetCollection = $options['collection'] ?? null;
                    $relationType = $options['relation_type'] ?? 'one-to-one';

                    if ($targetCollection) {
                        $edges[] = [
                            'from' => $collection->getSlug(),
                            'to' => $targetCollection,
                            'field' => $field->getName(),
                            'type' => $relationType,
                        ];
                    }
                }
            }
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
