<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\SchemaManager;

class CmsController extends Controller
{
    private function requireAdmin(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!Auth::user()->hasRole('admin')) {
            $this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            $this->redirect('/v1/dashboard');
        }
    }

    public function dashboard(): string
    {
        $this->requireAdmin();

        $collections = Collection::findAll();
        $schema = new SchemaManager();

        $stats = [];
        $totalEntries = 0;
        foreach ($collections as $collection) {
            $count = $schema->countEntries($collection->getTableName());
            $stats[$collection->getSlug()] = $count;
            $totalEntries += $count;
        }

        $totalMedia = Media::count();

        return $this->render('cms::dashboard', [
            'collections' => $collections,
            'stats' => $stats,
            'totalCollections' => count($collections),
            'totalEntries' => $totalEntries,
            'totalMedia' => $totalMedia,
            'user' => Auth::user(),
        ]);
    }
}
