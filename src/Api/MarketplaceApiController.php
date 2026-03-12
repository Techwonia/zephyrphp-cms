<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Marketplace\MarketplaceItem;
use ZephyrPHP\Marketplace\MarketplaceReview;

/**
 * Marketplace API Controller — public API for browsing, downloading, and reviewing items.
 *
 * Endpoints:
 *   GET    /marketplace/api/v1/items
 *   GET    /marketplace/api/v1/items/{slug}
 *   GET    /marketplace/api/v1/items/{slug}/download  (authenticated)
 *   POST   /marketplace/api/v1/items/{slug}/review    (authenticated)
 *   GET    /marketplace/api/v1/items/{slug}/reviews
 */
class MarketplaceApiController extends Controller
{
    /**
     * GET /marketplace/api/v1/items — Browse/search marketplace.
     */
    public function index(): void
    {
        $filters = [
            'type' => $_GET['type'] ?? null,
            'category' => $_GET['category'] ?? null,
            'search' => $_GET['search'] ?? null,
            'sort' => $_GET['sort'] ?? 'popular',
            'page' => (int) ($_GET['page'] ?? 1),
            'per_page' => (int) ($_GET['per_page'] ?? 20),
        ];

        $result = MarketplaceItem::browse($filters);

        $items = array_map(fn(MarketplaceItem $i) => $i->toArray(), $result['items']);

        $page = $filters['page'];
        $perPage = min(50, max(1, $filters['per_page']));
        $total = $result['total'];

        $this->json([
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /marketplace/api/v1/items/{slug} — Get item details.
     */
    public function show(string $slug): void
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $item = MarketplaceItem::findBySlug($slug);

        if (!$item) {
            $this->json(['error' => ['message' => 'Item not found.', 'status' => 404]], 404);
            return;
        }

        $this->json(['data' => $item->toArray()]);
    }

    /**
     * GET /marketplace/api/v1/items/{slug}/download — Download item ZIP.
     * Requires site token or license key.
     */
    public function download(string $slug): void
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $item = MarketplaceItem::findBySlug($slug);

        if (!$item) {
            $this->json(['error' => ['message' => 'Item not found.']], 404);
            return;
        }

        // For paid items, validate license
        if ($item->isPaid()) {
            $licenseKey = $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';
            if (empty($licenseKey)) {
                $this->json(['error' => ['message' => 'License key required for paid items.']], 402);
                return;
            }

            if (!$this->validateLicense($item, $licenseKey)) {
                $this->json(['error' => ['message' => 'Invalid or expired license key.']], 403);
                return;
            }
        }

        $packagePath = $item->getPackagePath();
        if (empty($packagePath) || !file_exists($packagePath)) {
            $this->json(['error' => ['message' => 'Package file not available.']], 500);
            return;
        }

        // Increment download counter
        $item->incrementDownloads();

        // Send file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $item->getVersion() . '.zip"');
        header('Content-Length: ' . filesize($packagePath));
        header('Cache-Control: no-cache');
        readfile($packagePath);
        exit;
    }

    /**
     * GET /marketplace/api/v1/items/{slug}/reviews — Get reviews.
     */
    public function reviews(string $slug): void
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $item = MarketplaceItem::findBySlug($slug);

        if (!$item) {
            $this->json(['error' => ['message' => 'Item not found.']], 404);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $reviews = MarketplaceReview::findByItem($item->getId(), $page);

        $this->json([
            'data' => array_map(fn(MarketplaceReview $r) => $r->toArray(), $reviews),
        ]);
    }

    /**
     * POST /marketplace/api/v1/items/{slug}/review — Submit a review.
     */
    public function submitReview(string $slug): void
    {
        // Require authentication
        if (!class_exists(\ZephyrPHP\Auth\Auth::class) || !\ZephyrPHP\Auth\Auth::check()) {
            $this->json(['error' => ['message' => 'Authentication required.']], 401);
            return;
        }

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $item = MarketplaceItem::findBySlug($slug);

        if (!$item) {
            $this->json(['error' => ['message' => 'Item not found.']], 404);
            return;
        }

        $user = \ZephyrPHP\Auth\Auth::user();
        $userId = $user->getId();

        // One review per user per item
        if (MarketplaceReview::hasReviewed($item->getId(), $userId)) {
            $this->json(['error' => ['message' => 'You have already reviewed this item.']], 409);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->json(['error' => ['message' => 'Invalid JSON body.']], 400);
            return;
        }

        $rating = (int) ($input['rating'] ?? 0);
        $body = trim($input['body'] ?? '');

        if ($rating < 1 || $rating > 5) {
            $this->json(['error' => ['message' => 'Rating must be between 1 and 5.']], 422);
            return;
        }

        if (strlen($body) > 2000) {
            $this->json(['error' => ['message' => 'Review body must be 2000 characters or fewer.']], 422);
            return;
        }

        $review = new MarketplaceReview();
        $review->setItemId($item->getId());
        $review->setUserId($userId);
        $review->setUserName($user->getName());
        $review->setRating($rating);
        $review->setBody($body);
        $review->save();

        // Update item's average rating
        $item->updateRating();

        $this->json(['data' => $review->toArray()], 201);
    }

    /**
     * POST /marketplace/api/v1/updates/check — Check for updates.
     */
    public function checkUpdates(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $installed = $input['items'] ?? [];

        if (!is_array($installed)) {
            $this->json(['data' => []]);
            return;
        }

        $updates = [];

        foreach ($installed as $slug => $currentVersion) {
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
            $item = MarketplaceItem::findBySlug($slug);

            if ($item && version_compare($item->getVersion(), $currentVersion, '>')) {
                $updates[$slug] = [
                    'current' => $currentVersion,
                    'latest' => $item->getVersion(),
                    'name' => $item->getName(),
                ];
            }
        }

        $this->json(['data' => $updates]);
    }

    /**
     * Validate a license key for a paid item.
     */
    private function validateLicense(MarketplaceItem $item, string $licenseKey): bool
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $row = $conn->fetchAssociative(
                'SELECT * FROM cms_marketplace_licenses WHERE item_id = ? AND license_key = ? AND is_active = 1',
                [$item->getId(), hash('sha256', $licenseKey)]
            );

            if (!$row) return false;

            // Check expiry
            if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
