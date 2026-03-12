<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Marketplace\MarketplaceClient;
use ZephyrPHP\Cms\Services\PermissionService;

/**
 * Marketplace Controller — CMS admin panel for browsing and installing
 * themes, apps, and sections from the marketplace.
 */
class MarketplaceController extends Controller
{
    private MarketplaceClient $client;

    public function __construct()
    {
        parent::__construct();
        $this->client = MarketplaceClient::getInstance();
    }

    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    /**
     * GET /cms/marketplace — Browse marketplace items.
     */
    public function index(): string
    {
        $this->requirePermission('apps.view');

        $filters = [
            'type' => $this->input('type', ''),
            'category' => $this->input('category', ''),
            'search' => $this->input('search', ''),
            'sort' => $this->input('sort', 'popular'),
            'page' => max(1, (int) $this->input('page', 1)),
        ];

        $result = $this->client->browse(array_filter($filters));

        return $this->render('cms::marketplace/index', [
            'items' => $result['items'],
            'pagination' => $result['pagination'] ?? [],
            'filters' => $filters,
            'error' => $result['error'] ?? null,
            'user' => Auth::user(),
        ]);
    }

    /**
     * GET /cms/marketplace/{slug} — View item details.
     */
    public function show(string $slug): string
    {
        $this->requirePermission('apps.view');

        $item = $this->client->getItem($slug);

        if (!$item) {
            $this->flash('errors', ['Item not found on marketplace.']);
            $this->redirect('/cms/marketplace');
            return '';
        }

        $reviews = $this->client->getReviews($slug);

        return $this->render('cms::marketplace/show', [
            'item' => $item,
            'reviews' => $reviews['data'] ?? [],
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /cms/marketplace/{slug}/install — Install item from marketplace.
     */
    public function install(string $slug): void
    {
        $this->requirePermission('apps.manage');

        $licenseKey = $this->input('license_key', null);
        $result = $this->client->install($slug, $licenseKey);

        if ($result['success']) {
            $name = $result['name'] ?? $slug;
            $this->flash('success', "\"{$name}\" installed successfully from marketplace.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to install from marketplace.']);
        }

        $this->redirect('/cms/marketplace');
    }
}
