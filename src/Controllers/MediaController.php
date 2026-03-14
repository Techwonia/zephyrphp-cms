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

    private function getPublicPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
    }

    private function getUploadBase(): string
    {
        return $this->getPublicPath() . '/storage/cms/uploads';
    }

    public function index(): string
    {
        $this->requirePermission('media.view');

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = 24;
        $folder = trim($this->input('folder', ''));
        $filter = $this->input('filter', 'all');
        $search = trim($this->input('search', ''));

        // Get folders
        $folders = $this->getFolders();

        // Build query criteria
        $criteria = [];
        if ($folder !== '') {
            // Filter by folder path prefix
            $allMedia = Media::findBy([], ['createdAt' => 'DESC']);
            $allMedia = array_filter($allMedia, function ($m) use ($folder) {
                $path = $m->getPath();
                $relDir = dirname(str_replace('storage/cms/uploads/', '', $path));
                return $relDir === $folder || str_starts_with($relDir, $folder . '/');
            });
        } else {
            $allMedia = Media::findBy([], ['createdAt' => 'DESC']);
        }

        // Search filter
        if ($search !== '') {
            $searchLower = strtolower($search);
            $allMedia = array_filter($allMedia, fn($m) => str_contains(strtolower($m->getOriginalName()), $searchLower));
        }

        // Type filter
        if ($filter === 'images') {
            $allMedia = array_filter($allMedia, fn($m) => $m->isImage());
        } elseif ($filter === 'documents') {
            $allMedia = array_filter($allMedia, fn($m) => !$m->isImage() && !str_starts_with($m->getMimeType(), 'video/') && !str_starts_with($m->getMimeType(), 'audio/'));
        } elseif ($filter === 'video') {
            $allMedia = array_filter($allMedia, fn($m) => str_starts_with($m->getMimeType(), 'video/'));
        } elseif ($filter === 'audio') {
            $allMedia = array_filter($allMedia, fn($m) => str_starts_with($m->getMimeType(), 'audio/'));
        }

        $allMedia = array_values($allMedia);
        $total = count($allMedia);
        $media = array_slice($allMedia, ($page - 1) * $perPage, $perPage);

        return $this->render('cms::media/index', [
            'media' => $media,
            'total' => $total,
            'currentPage' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
            'folders' => $folders,
            'currentFolder' => $folder,
            'currentFilter' => $filter,
            'search' => $search,
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

        // Determine target folder
        $folder = trim($this->input('folder', ''));
        if ($folder !== '') {
            // Sanitize folder path — prevent directory traversal
            $folder = $this->sanitizeFolderPath($folder);
            if ($folder === false) {
                $this->flash('errors', ['file' => 'Invalid folder path.']);
                $this->back();
                return;
            }
            $uploadDir = $this->getUploadBase() . '/' . $folder;
            $relativePrefix = 'storage/cms/uploads/' . $folder;
        } else {
            $uploadDir = $this->getUploadBase() . '/' . date('Y') . '/' . date('m');
            $relativePrefix = 'storage/cms/uploads/' . date('Y') . '/' . date('m');
        }

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $relativePath = $relativePrefix . '/' . $filename;
            $thumbnailRelPath = null;

            // Optimize and generate thumbnail for images
            if (str_starts_with($realMime, 'image/') && $realMime !== 'image/svg+xml') {
                ImageService::optimizeImage($filePath, 1920, 1920, 85);

                $thumbAbsPath = ImageService::createThumbnail($filePath, 400, 400, 80);
                if ($thumbAbsPath) {
                    $thumbFilename = basename($thumbAbsPath);
                    $thumbnailRelPath = $relativePrefix . '/' . $thumbFilename;
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

        $this->deleteMediaFile($media);
        $media->delete();

        $this->flash('success', 'File deleted.');
        $this->back();
    }

    /**
     * Update media metadata (alt text, original name).
     */
    public function update(int $id): void
    {
        $this->requirePermission('media.upload');

        $media = Media::find($id);
        if (!$media) {
            $this->flash('errors', ['media' => 'File not found.']);
            $this->back();
            return;
        }

        $altText = trim($this->input('alt_text', ''));
        $originalName = trim($this->input('original_name', ''));

        $media->setAltText($altText !== '' ? htmlspecialchars($altText, ENT_QUOTES, 'UTF-8') : null);
        if ($originalName !== '') {
            $media->setOriginalName(htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'));
        }
        $media->save();

        $this->flash('success', 'Media updated.');
        $this->back();
    }

    /**
     * Show single media detail/edit page.
     */
    public function detail(int $id): string
    {
        $this->requirePermission('media.view');

        $media = Media::find($id);
        if (!$media) {
            $this->flash('errors', ['media' => 'File not found.']);
            $this->redirect('/cms/media');
            return '';
        }

        // Get image dimensions if applicable
        $dimensions = null;
        if ($media->isImage()) {
            $filePath = $this->getPublicPath() . '/' . $media->getPath();
            if (file_exists($filePath)) {
                $info = @getimagesize($filePath);
                if ($info) {
                    $dimensions = ['width' => $info[0], 'height' => $info[1]];
                }
            }
        }

        // Find usage across collections
        $usage = $this->findMediaUsage($media);

        return $this->render('cms::media/detail', [
            'item' => $media,
            'dimensions' => $dimensions,
            'usage' => $usage,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Bulk operations: delete, move to folder.
     */
    public function bulk(): void
    {
        $this->requirePermission('media.delete');

        $action = $this->input('action', '');
        $ids = $this->input('ids', []);

        if (!is_array($ids) || empty($ids)) {
            $this->flash('errors', ['media' => 'No files selected.']);
            $this->back();
            return;
        }

        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (empty($ids)) {
            $this->flash('errors', ['media' => 'Invalid selection.']);
            $this->back();
            return;
        }

        switch ($action) {
            case 'delete':
                $this->bulkDelete($ids);
                break;
            case 'move':
                $this->requirePermission('media.upload');
                $targetFolder = trim($this->input('target_folder', ''));
                $this->bulkMove($ids, $targetFolder);
                break;
            default:
                $this->flash('errors', ['media' => 'Unknown action.']);
        }

        $this->back();
    }

    /**
     * Create a new folder.
     */
    public function createFolder(): void
    {
        $this->requirePermission('media.upload');

        $name = trim($this->input('folder_name', ''));
        $parent = trim($this->input('parent_folder', ''));

        if ($name === '') {
            $this->flash('errors', ['folder' => 'Folder name is required.']);
            $this->back();
            return;
        }

        // Sanitize folder name — alphanumeric, hyphens, underscores only
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        if ($safeName === '' || $safeName !== $name) {
            $this->flash('errors', ['folder' => 'Folder name can only contain letters, numbers, hyphens, and underscores.']);
            $this->back();
            return;
        }

        $path = $parent !== '' ? $parent . '/' . $safeName : $safeName;

        // Validate full path
        $sanitized = $this->sanitizeFolderPath($path);
        if ($sanitized === false) {
            $this->flash('errors', ['folder' => 'Invalid folder path.']);
            $this->back();
            return;
        }

        $fullPath = $this->getUploadBase() . '/' . $sanitized;

        if (is_dir($fullPath)) {
            $this->flash('errors', ['folder' => 'Folder already exists.']);
            $this->back();
            return;
        }

        if (mkdir($fullPath, 0755, true)) {
            $this->flash('success', 'Folder created: ' . $safeName);
        } else {
            $this->flash('errors', ['folder' => 'Failed to create folder.']);
        }

        $this->back();
    }

    /**
     * Rename a folder.
     */
    public function renameFolder(): void
    {
        $this->requirePermission('media.upload');

        $oldPath = trim($this->input('old_path', ''));
        $newName = trim($this->input('new_name', ''));

        if ($oldPath === '' || $newName === '') {
            $this->flash('errors', ['folder' => 'Folder path and new name are required.']);
            $this->back();
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $newName);
        if ($safeName === '') {
            $this->flash('errors', ['folder' => 'Invalid folder name.']);
            $this->back();
            return;
        }

        $sanitizedOld = $this->sanitizeFolderPath($oldPath);
        if ($sanitizedOld === false) {
            $this->flash('errors', ['folder' => 'Invalid folder path.']);
            $this->back();
            return;
        }

        $parentDir = dirname($sanitizedOld);
        $newPath = ($parentDir !== '.' ? $parentDir . '/' : '') . $safeName;

        $fullOld = $this->getUploadBase() . '/' . $sanitizedOld;
        $fullNew = $this->getUploadBase() . '/' . $newPath;

        if (!is_dir($fullOld)) {
            $this->flash('errors', ['folder' => 'Folder not found.']);
            $this->back();
            return;
        }

        if (is_dir($fullNew)) {
            $this->flash('errors', ['folder' => 'A folder with that name already exists.']);
            $this->back();
            return;
        }

        if (rename($fullOld, $fullNew)) {
            // Update media paths in database
            $allMedia = Media::findBy([], []);
            $oldPrefix = 'storage/cms/uploads/' . $sanitizedOld . '/';
            $newPrefix = 'storage/cms/uploads/' . $newPath . '/';
            foreach ($allMedia as $m) {
                $changed = false;
                if (str_starts_with($m->getPath(), $oldPrefix)) {
                    $m->setPath(str_replace($oldPrefix, $newPrefix, $m->getPath()));
                    $changed = true;
                }
                if ($m->getThumbnailPath() && str_starts_with($m->getThumbnailPath(), $oldPrefix)) {
                    $m->setThumbnailPath(str_replace($oldPrefix, $newPrefix, $m->getThumbnailPath()));
                    $changed = true;
                }
                if ($changed) {
                    $m->save();
                }
            }
            $this->flash('success', 'Folder renamed.');
        } else {
            $this->flash('errors', ['folder' => 'Failed to rename folder.']);
        }

        $this->back();
    }

    /**
     * Delete an empty folder.
     */
    public function deleteFolder(): void
    {
        $this->requirePermission('media.delete');

        $path = trim($this->input('folder_path', ''));
        if ($path === '') {
            $this->flash('errors', ['folder' => 'Folder path is required.']);
            $this->back();
            return;
        }

        $sanitized = $this->sanitizeFolderPath($path);
        if ($sanitized === false) {
            $this->flash('errors', ['folder' => 'Invalid folder path.']);
            $this->back();
            return;
        }

        $fullPath = $this->getUploadBase() . '/' . $sanitized;

        if (!is_dir($fullPath)) {
            $this->flash('errors', ['folder' => 'Folder not found.']);
            $this->back();
            return;
        }

        // Check if folder has files in DB
        $prefix = 'storage/cms/uploads/' . $sanitized . '/';
        $allMedia = Media::findBy([], []);
        $hasFiles = false;
        foreach ($allMedia as $m) {
            if (str_starts_with($m->getPath(), $prefix)) {
                $hasFiles = true;
                break;
            }
        }

        if ($hasFiles) {
            $this->flash('errors', ['folder' => 'Cannot delete folder — it contains files. Move or delete them first.']);
            $this->back();
            return;
        }

        // Check if directory is actually empty on disk
        $items = @scandir($fullPath);
        $isEmpty = $items !== false && count(array_diff($items, ['.', '..'])) === 0;

        if (!$isEmpty) {
            $this->flash('errors', ['folder' => 'Cannot delete folder — it is not empty.']);
            $this->back();
            return;
        }

        if (@rmdir($fullPath)) {
            $this->flash('success', 'Folder deleted.');
        } else {
            $this->flash('errors', ['folder' => 'Failed to delete folder.']);
        }

        $this->back();
    }

    /**
     * Find where a media file is used across collections.
     */
    public function usage(int $id): string
    {
        $this->requirePermission('media.view');

        $media = Media::find($id);
        if (!$media) {
            $this->json(['error' => 'File not found.'], 404);
            return '';
        }

        $usage = $this->findMediaUsage($media);

        $this->json(['usage' => $usage]);
        return '';
    }

    // ─── Private helpers ────────────────────────────────────────

    private function bulkDelete(array $ids): void
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $media = Media::find($id);
            if ($media) {
                $this->deleteMediaFile($media);
                $media->delete();
                $deleted++;
            }
        }
        $this->flash('success', $deleted . ' file(s) deleted.');
    }

    private function bulkMove(array $ids, string $targetFolder): void
    {
        if ($targetFolder !== '') {
            $sanitized = $this->sanitizeFolderPath($targetFolder);
            if ($sanitized === false) {
                $this->flash('errors', ['media' => 'Invalid target folder.']);
                return;
            }
            $targetFolder = $sanitized;
            $targetDir = $this->getUploadBase() . '/' . $targetFolder;
            $newRelPrefix = 'storage/cms/uploads/' . $targetFolder;
        } else {
            // Move to root (date-based folder)
            $targetDir = $this->getUploadBase() . '/' . date('Y') . '/' . date('m');
            $newRelPrefix = 'storage/cms/uploads/' . date('Y') . '/' . date('m');
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $moved = 0;
        $publicPath = $this->getPublicPath();

        foreach ($ids as $id) {
            $media = Media::find($id);
            if (!$media) continue;

            $oldAbsPath = $publicPath . '/' . $media->getPath();
            $newAbsPath = $targetDir . '/' . $media->getFilename();

            if (file_exists($oldAbsPath) && @rename($oldAbsPath, $newAbsPath)) {
                $media->setPath($newRelPrefix . '/' . $media->getFilename());

                // Move thumbnail too
                if ($media->getThumbnailPath()) {
                    $oldThumb = $publicPath . '/' . $media->getThumbnailPath();
                    $thumbName = basename($media->getThumbnailPath());
                    $newThumb = $targetDir . '/' . $thumbName;
                    if (file_exists($oldThumb) && @rename($oldThumb, $newThumb)) {
                        $media->setThumbnailPath($newRelPrefix . '/' . $thumbName);
                    }
                }

                $media->save();
                $moved++;
            }
        }

        $this->flash('success', $moved . ' file(s) moved.');
    }

    private function deleteMediaFile(Media $media): void
    {
        $publicPath = $this->getPublicPath();

        $filePath = $publicPath . '/' . $media->getPath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        if ($media->getThumbnailPath()) {
            $thumbPath = $publicPath . '/' . $media->getThumbnailPath();
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    /**
     * Get list of folders from the uploads directory.
     */
    private function getFolders(): array
    {
        $base = $this->getUploadBase();
        if (!is_dir($base)) {
            return [];
        }

        $folders = [];
        $this->scanFolders($base, '', $folders);
        sort($folders);
        return $folders;
    }

    private function scanFolders(string $basePath, string $prefix, array &$folders): void
    {
        $items = @scandir($basePath);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $basePath . '/' . $item;
            if (is_dir($fullPath)) {
                $path = $prefix !== '' ? $prefix . '/' . $item : $item;
                $folders[] = $path;
                $this->scanFolders($fullPath, $path, $folders);
            }
        }
    }

    /**
     * Sanitize folder path to prevent directory traversal.
     * Returns cleaned path or false if invalid.
     */
    private function sanitizeFolderPath(string $path): string|false
    {
        // Remove leading/trailing slashes
        $path = trim($path, '/\\');

        // Block traversal attempts
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        // Only allow alphanumeric, hyphens, underscores, forward slashes
        if (!preg_match('#^[a-zA-Z0-9_\-/]+$#', $path)) {
            return false;
        }

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Remove double slashes
        $path = preg_replace('#/+#', '/', $path);

        // Ensure resolved path is still within uploads
        $resolved = realpath($this->getUploadBase() . '/' . $path);
        $uploadBase = realpath($this->getUploadBase());

        // If dir doesn't exist yet (creating), that's OK — just validate the segments
        if ($resolved === false) {
            $segments = explode('/', $path);
            foreach ($segments as $seg) {
                if ($seg === '' || $seg === '.' || $seg === '..') {
                    return false;
                }
            }
            return $path;
        }

        // If dir exists, verify it's inside upload base
        if ($uploadBase === false || !str_starts_with($resolved, $uploadBase)) {
            return false;
        }

        return $path;
    }

    /**
     * Find which collection entries reference this media file.
     */
    private function findMediaUsage(Media $media): array
    {
        $usage = [];
        $url = $media->getUrl();
        $path = $media->getPath();

        try {
            $conn = \ZephyrPHP\Database\DB::connection();

            // Get all collections
            $collections = $conn->fetchAllAssociative('SELECT * FROM cms_collections');

            foreach ($collections as $col) {
                $tableName = $col['table_name'] ?? null;
                if (!$tableName) continue;

                $sm = $conn->createSchemaManager();
                if (!$sm->tablesExist([$tableName])) continue;

                // Get columns that could hold media references (text, varchar)
                $columns = $sm->listTableColumns($tableName);
                $textCols = [];
                foreach ($columns as $column) {
                    $type = strtolower($column->getType()->getName());
                    if (in_array($type, ['string', 'text'])) {
                        $textCols[] = $column->getName();
                    }
                }

                if (empty($textCols)) continue;

                // Search for media URL or path in text columns
                $conditions = [];
                $params = [];
                foreach ($textCols as $i => $colName) {
                    $conditions[] = $colName . ' LIKE ?';
                    $params[] = '%' . $path . '%';
                }

                $sql = 'SELECT id FROM ' . $tableName . ' WHERE ' . implode(' OR ', $conditions) . ' LIMIT 20';
                $rows = $conn->fetchAllAssociative($sql, $params);

                foreach ($rows as $row) {
                    $usage[] = [
                        'collection' => $col['name'] ?? $col['slug'],
                        'collection_slug' => $col['slug'],
                        'entry_id' => $row['id'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail — usage is informational
        }

        return $usage;
    }
}
