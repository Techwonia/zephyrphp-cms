<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Marketplace\MarketplaceItem;
use ZephyrPHP\Cms\Services\PermissionService;

/**
 * Seller Controller — developer portal for submitting and managing
 * marketplace items (themes, apps, sections).
 */
class SellerController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    private function requireAuth(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
        }
    }

    /**
     * GET /cms/seller — Seller dashboard showing submitted items.
     */
    public function index(): string
    {
        $this->requireAuth();

        $user = Auth::user();
        $items = MarketplaceItem::findBySeller($user->getId());

        // Calculate stats
        $totalDownloads = 0;
        $publishedCount = 0;
        foreach ($items as $item) {
            $totalDownloads += $item->getDownloads();
            if ($item->getStatus() === 'published') $publishedCount++;
        }

        return $this->render('cms::seller/index', [
            'items' => $items,
            'totalDownloads' => $totalDownloads,
            'publishedCount' => $publishedCount,
            'user' => $user,
        ]);
    }

    /**
     * GET /cms/seller/submit — Form to submit a new marketplace item.
     */
    public function create(): string
    {
        $this->requireAuth();

        return $this->render('cms::seller/submit', [
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /cms/seller/submit — Handle new item submission.
     */
    public function store(): void
    {
        $this->requireAuth();

        $user = Auth::user();

        $name = trim($this->input('name', ''));
        $type = $this->input('type', 'app');
        $category = trim($this->input('category', ''));
        $description = trim($this->input('description', ''));
        $pricing = $this->input('pricing', 'free');
        $price = $this->input('price', '0');

        // Validate
        $errors = [];
        if (empty($name)) $errors[] = 'Name is required.';
        if (!in_array($type, ['theme', 'app', 'section'], true)) $errors[] = 'Invalid item type.';
        if (strlen($name) > 100) $errors[] = 'Name must be 100 characters or fewer.';
        if (strlen($description) > 5000) $errors[] = 'Description must be 5000 characters or fewer.';

        if (!in_array($pricing, ['free', 'paid', 'subscription'], true)) {
            $pricing = 'free';
        }

        $priceInCents = 0;
        if ($pricing !== 'free') {
            $priceInCents = (int) round(((float) $price) * 100);
            if ($priceInCents < 100) $errors[] = 'Minimum price is $1.00 for paid items.';
        }

        // Validate ZIP upload
        $file = $_FILES['package'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Package ZIP file is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', [
                'name' => $name, 'type' => $type, 'category' => $category,
                'description' => $description, 'pricing' => $pricing, 'price' => $price,
            ]);
            $this->redirect('/cms/seller/submit');
            return;
        }

        // Validate file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('errors', ['Only .zip files are allowed.']);
            $this->redirect('/cms/seller/submit');
            return;
        }

        if ($file['size'] > 50 * 1024 * 1024) {
            $this->flash('errors', ['Package exceeds 50MB limit.']);
            $this->redirect('/cms/seller/submit');
            return;
        }

        // Generate slug
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
        $slug = trim($slug, '-');
        if (empty($slug)) $slug = 'item-' . time();

        // Check uniqueness
        if (MarketplaceItem::findBySlug($slug)) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        // Store package
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $packagesDir = $basePath . '/storage/marketplace/packages';
        if (!is_dir($packagesDir)) {
            mkdir($packagesDir, 0755, true);
        }

        $packageFile = $packagesDir . '/' . $slug . '-' . time() . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $packageFile)) {
            $this->flash('errors', ['Failed to store package file.']);
            $this->redirect('/cms/seller/submit');
            return;
        }

        // Handle preview image
        $previewImage = null;
        $preview = $_FILES['preview_image'] ?? null;
        if ($preview && $preview['error'] === UPLOAD_ERR_OK) {
            $imgExt = strtolower(pathinfo($preview['name'], PATHINFO_EXTENSION));
            if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $imagesDir = $basePath . '/public/assets/marketplace';
                if (!is_dir($imagesDir)) mkdir($imagesDir, 0755, true);
                $imgName = $slug . '-preview.' . $imgExt;
                if (move_uploaded_file($preview['tmp_name'], $imagesDir . '/' . $imgName)) {
                    $previewImage = '/assets/marketplace/' . $imgName;
                }
            }
        }

        // Create item
        $item = new MarketplaceItem();
        $item->setSlug($slug);
        $item->setName($name);
        $item->setType($type);
        $item->setCategory($category);
        $item->setDescription($description);
        $item->setVersion('1.0.0');
        $item->setSellerId($user->getId());
        $item->setSellerName($user->getName());
        $item->setPricing($pricing);
        $item->setPriceInCents($priceInCents);
        $item->setStatus('pending'); // Requires admin approval
        $item->setPackagePath($packageFile);
        $item->setPreviewImage($previewImage);
        $item->save();

        $this->flash('success', "\"$name\" submitted for review. It will be published after approval.");
        $this->redirect('/cms/seller');
    }
}
