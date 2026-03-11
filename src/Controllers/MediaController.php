<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\ImageService;
use ZephyrPHP\Cms\Services\FileValidator;
use ZephyrPHP\Cms\Services\PermissionService;

class MediaController extends Controller
{
    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect('/login');
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission('media.view');

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = 24;

        $media = Media::findBy([], ['createdAt' => 'DESC'], $perPage, ($page - 1) * $perPage);
        $total = Media::count();

        return $this->render('cms::media/index', [
            'media' => $media,
            'total' => $total,
            'currentPage' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
            'user' => Auth::user(),
        ]);
    }

    public function upload(): void
    {
        $this->requirePermission('media.upload');

        $file = $_FILES['file'] ?? null;
        if (!$file) {
            $this->flash('errors', ['file' => 'No file uploaded.']);
            $this->back();
            return;
        }

        // Validate file using FileValidator (checks size, real MIME, extension, dangerous files)
        $validation = FileValidator::validate($file);
        if (!$validation['valid']) {
            $this->flash('errors', ['file' => $validation['error']]);
            $this->back();
            return;
        }

        $realMime = $validation['mime'];

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $uploadDir = $publicPath . '/storage/cms/uploads/' . date('Y') . '/' . date('m');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $relativePath = 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $filename;
            $thumbnailRelPath = null;

            // Optimize and generate thumbnail for images
            if (str_starts_with($realMime, 'image/') && $realMime !== 'image/svg+xml') {
                ImageService::optimizeImage($filePath, 1920, 1920, 85);

                $thumbAbsPath = ImageService::createThumbnail($filePath, 400, 400, 80);
                if ($thumbAbsPath) {
                    $thumbFilename = basename($thumbAbsPath);
                    $thumbnailRelPath = 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $thumbFilename;
                }

                $file['size'] = filesize($filePath);
            }

            $media = new Media();
            $media->setFilename($filename);
            $media->setOriginalName($file['name']);
            $media->setPath($relativePath);
            $media->setMimeType($realMime);
            $media->setSize((int) $file['size']);
            $media->setThumbnailPath($thumbnailRelPath);
            $media->setUploadedBy(Auth::user()?->getId());
            $media->save();

            $this->flash('success', 'File uploaded successfully.');
        } else {
            $this->flash('errors', ['file' => 'Failed to move uploaded file.']);
        }

        $this->back();
    }

    /**
     * JSON endpoint for media browser modal in entry forms.
     */
    public function browse(): string
    {
        $this->requirePermission('media.view');

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = 24;
        $search = $this->input('search', '');

        $allMedia = Media::findBy([], ['createdAt' => 'DESC']);

        // Search by original name
        if (!empty($search)) {
            $searchLower = strtolower($search);
            $allMedia = array_filter($allMedia, fn($m) => str_contains(strtolower($m->getOriginalName()), $searchLower));
        }

        // Filter images only if requested
        $filter = $this->input('filter', 'all');
        if ($filter === 'images') {
            $allMedia = array_filter($allMedia, fn($m) => $m->isImage());
        }

        $total = count($allMedia);
        $media = array_slice(array_values($allMedia), ($page - 1) * $perPage, $perPage);

        $items = [];
        foreach ($media as $item) {
            $items[] = [
                'id' => $item->getId(),
                'url' => $item->getUrl(),
                'thumbnail' => $item->getThumbnailUrl() ?? $item->getUrl(),
                'name' => $item->getOriginalName(),
                'size' => $item->getHumanSize(),
                'mime' => $item->getMimeType(),
                'is_image' => $item->isImage(),
                'alt' => $item->getAltText() ?? '',
            ];
        }

        $this->json([
            'data' => $items,
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
        return '';
    }

    public function destroy(int $id): void
    {
        $this->requirePermission('media.delete');

        $media = Media::find($id);
        if (!$media) {
            $this->flash('errors', ['media' => 'File not found.']);
            $this->back();
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';

        // Delete the actual file
        $filePath = $publicPath . '/' . $media->getPath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete thumbnail
        if ($media->getThumbnailPath()) {
            $thumbPath = $publicPath . '/' . $media->getThumbnailPath();
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
        }

        $media->delete();

        $this->flash('success', 'File deleted.');
        $this->back();
    }
}
