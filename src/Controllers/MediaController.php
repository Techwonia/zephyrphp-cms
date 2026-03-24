<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\Csrf;
use ZephyrPHP\Cms\Models\Media;
use ZephyrPHP\Cms\Services\ImageService;
use ZephyrPHP\Cms\Services\FileValidator;
use ZephyrPHP\Cms\Services\PermissionService;

class MediaController extends Controller
{
    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect(login_url());
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect(login_url());
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect(admin_url());
        }
    }

    /**
     * Ensure the `tags` column exists on the cms_media table.
     */
    private function ensureTagsColumn(): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $sm = $conn->createSchemaManager();
            $columns = $sm->listTableColumns('cms_media');

            if (!isset($columns['tags'])) {
                $conn->executeStatement('ALTER TABLE cms_media ADD COLUMN tags TEXT DEFAULT NULL');
            }
        } catch (\Throwable $e) {
            // Silently fail — column may already exist or table may not exist yet
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

        // Ensure tags column exists
        $this->ensureTagsColumn();

        $page = max(1, (int) ($this->input('page') ?? 1));
        $perPage = 24;
        $folder = trim($this->input('folder', ''));
        $filter = $this->input('filter', 'all');
        $search = trim($this->input('search', ''));
        $activeTag = trim($this->input('tag', ''));

        // Get folders
        $folders = $this->getFolders();

        // Use direct DB queries with pagination to avoid loading all records
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $qb = $conn->createQueryBuilder();

            // Base conditions
            $conditions = [];
            $params = [];

            if ($folder !== '') {
                $conditions[] = 'path LIKE ?';
                $params[] = 'storage/cms/uploads/' . $folder . '/%';
            }

            if ($search !== '') {
                $conditions[] = 'original_name LIKE ?';
                $params[] = '%' . $search . '%';
            }

            if ($activeTag !== '') {
                $safeTag = $activeTag;
                $conditions[] = '(tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
                $params[] = $safeTag;
                $params[] = $safeTag . ',%';
                $params[] = '%,' . $safeTag;
                $params[] = '%,' . $safeTag . ',%';
            }

            if ($filter === 'recent') {
                $conditions[] = 'createdAt >= ?';
                $params[] = date('Y-m-d H:i:s', strtotime('-7 days'));
            } elseif ($filter === 'images') {
                $conditions[] = 'mime_type LIKE ?';
                $params[] = 'image/%';
            } elseif ($filter === 'video') {
                $conditions[] = 'mime_type LIKE ?';
                $params[] = 'video/%';
            } elseif ($filter === 'audio') {
                $conditions[] = 'mime_type LIKE ?';
                $params[] = 'audio/%';
            } elseif ($filter === 'documents') {
                $conditions[] = 'mime_type NOT LIKE ?';
                $conditions[] = 'mime_type NOT LIKE ?';
                $conditions[] = 'mime_type NOT LIKE ?';
                $params[] = 'image/%';
                $params[] = 'video/%';
                $params[] = 'audio/%';
            }

            $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $total = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_media {$where}",
                $params
            );

            $offset = ($page - 1) * $perPage;
            $rows = $conn->fetchAllAssociative(
                "SELECT id FROM cms_media {$where} ORDER BY createdAt DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
                $params
            );

            $media = [];
            foreach ($rows as $row) {
                $item = Media::find((int) $row['id']);
                if ($item) {
                    $media[] = $item;
                }
            }
        } catch (\Throwable $e) {
            error_log('Media index error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $media = [];
            $total = 0;
        }

        // Collect all unique tags across all media
        $allTags = [];
        try {
            $tagRows = $conn->fetchAllAssociative('SELECT DISTINCT tags FROM cms_media WHERE tags IS NOT NULL AND tags != ?', ['']);
            foreach ($tagRows as $row) {
                foreach (array_map('trim', explode(',', $row['tags'])) as $t) {
                    if ($t !== '') {
                        $allTags[$t] = true;
                    }
                }
            }
            $allTags = array_keys($allTags);
            sort($allTags);
        } catch (\Throwable $e) {
            $allTags = [];
        }

        return $this->render('cms::media/index', [
            'media' => $media,
            'total' => $total,
            'currentPage' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
            'folders' => $folders,
            'currentFolder' => $folder,
            'currentFilter' => $filter,
            'search' => $search,
            'activeTag' => $activeTag,
            'allTags' => $allTags,
            'user' => Auth::user(),
        ]);
    }

    public function upload(): void
    {
        $this->requirePermission('media.upload');

        $isApiCall = !empty($_SERVER['HTTP_X_CSRF_TOKEN']) || $this->isAjax();

        $file = $_FILES['file'] ?? null;
        if (!$file) {
            if ($isApiCall) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['error' => 'No file uploaded.']);
                return;
            }
            $this->flash('errors', ['file' => 'No file uploaded.']);
            $this->redirect(admin_url('media'));
            return;
        }

        // Validate file using FileValidator (checks size, real MIME, extension, dangerous files)
        $validation = FileValidator::validate($file);
        if (!$validation['valid']) {
            if ($isApiCall) {
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['error' => $validation['error']]);
                return;
            }
            $this->flash('errors', ['file' => $validation['error']]);
            $this->redirect(admin_url('media'));
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
                $this->redirect(admin_url('media'));
                return;
            }
            $uploadDir = $this->getUploadBase() . '/' . $folder;
            $relativePrefix = 'storage/cms/uploads/' . $folder;
        } else {
            $uploadDir = $this->getUploadBase();
            $relativePrefix = 'storage/cms/uploads';
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

            if ($isApiCall) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'path' => $relativePath,
                    'thumbnail' => $thumbnailRelPath,
                    'id' => $media->getId(),
                ]);
                return;
            }

            $this->flash('success', 'File uploaded successfully.');
        } else {
            if ($isApiCall) {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['error' => 'Failed to move uploaded file.']);
                return;
            }
            $this->flash('errors', ['file' => 'Failed to move uploaded file.']);
        }

        $this->redirect(admin_url('media'));
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
        $filter = $this->input('filter', 'all');

        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $conditions = [];
            $params = [];

            if (!empty($search)) {
                $conditions[] = 'original_name LIKE ?';
                $params[] = '%' . $search . '%';
            }

            if ($filter === 'images') {
                $conditions[] = 'mime_type LIKE ?';
                $params[] = 'image/%';
            }

            $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $total = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_media {$where}",
                $params
            );

            $offset = ($page - 1) * $perPage;
            $rows = $conn->fetchAllAssociative(
                "SELECT id FROM cms_media {$where} ORDER BY createdAt DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
                $params
            );

            $media = [];
            foreach ($rows as $row) {
                $m = Media::find((int) $row['id']);
                if ($m) {
                    $media[] = $m;
                }
            }
        } catch (\Throwable $e) {
            $media = [];
            $total = 0;
        }

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

        $tagsInput = trim($this->input('tags', ''));

        $media->setAltText($altText !== '' ? htmlspecialchars($altText, ENT_QUOTES, 'UTF-8') : null);
        if ($originalName !== '') {
            $media->setOriginalName(htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'));
        }

        // Sanitize and save tags
        if ($tagsInput !== '') {
            $tags = array_filter(array_map(function ($t) {
                return htmlspecialchars(trim($t), ENT_QUOTES, 'UTF-8');
            }, explode(',', $tagsInput)), fn($t) => $t !== '');
            $media->setTags($tags);
        } else {
            $media->setTagsString(null);
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

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // Always try raw DB first (reliable), then Doctrine
        $rawData = null;
        $media = null;
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $rawData = $conn->fetchAssociative('SELECT * FROM cms_media WHERE id = ?', [$id]);
        } catch (\Throwable $e) {}

        if (!$rawData) {
            $media = Media::find($id);
        }

        if (!$media && !$rawData) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                return '';
            }
            $this->flash('errors', ['media' => 'File not found.']);
            $this->redirect(admin_url('media'));
            return '';
        }

        // Build data array (works with both Doctrine and raw)
        if ($media) {
            $data = [
                'id' => $media->getId(),
                'filename' => $media->getFilename(),
                'original_name' => $media->getOriginalName(),
                'path' => $media->getPath(),
                'mime_type' => $media->getMimeType(),
                'size' => $media->getSize(),
                'human_size' => $media->getHumanSize(),
                'alt_text' => $media->getAltText() ?? '',
                'url' => $media->getUrl(),
                'thumbnail_url' => $media->getThumbnailUrl(),
                'is_image' => $media->isImage(),
                'tags' => $media->getTags(),
                'tags_string' => $media->getTagsString() ?? '',
            ];
        } else {
            $size = (int)($rawData['size'] ?? 0);
            $units = ['B','KB','MB','GB'];
            $i = 0;
            $s = $size;
            while ($s >= 1024 && $i < 3) { $s /= 1024; $i++; }
            $humanSize = round($s, 1) . ' ' . $units[$i];

            $data = [
                'id' => (int)$rawData['id'],
                'filename' => $rawData['filename'] ?? '',
                'original_name' => $rawData['original_name'] ?? '',
                'path' => $rawData['path'] ?? '',
                'mime_type' => $rawData['mime_type'] ?? '',
                'size' => $size,
                'human_size' => $humanSize,
                'alt_text' => $rawData['alt_text'] ?? '',
                'url' => '/' . ltrim($rawData['path'] ?? '', '/'),
                'thumbnail_url' => ($rawData['thumbnail_path'] ?? '') ? '/' . ltrim($rawData['thumbnail_path'], '/') : null,
                'is_image' => str_starts_with($rawData['mime_type'] ?? '', 'image/'),
                'tags' => ($rawData['tags'] ?? '') ? array_map('trim', explode(',', $rawData['tags'])) : [],
                'tags_string' => $rawData['tags'] ?? '',
            ];
        }

        // Get image dimensions
        $dimensions = null;
        if ($data['is_image']) {
            $filePath = $this->getPublicPath() . '/' . $data['path'];
            if (file_exists($filePath)) {
                $info = @getimagesize($filePath);
                if ($info) {
                    $dimensions = ['width' => $info[0], 'height' => $info[1]];
                }
            }
        }
        $data['dimensions'] = $dimensions;

        // Return JSON for AJAX requests
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($data);
            return '';
        }

        // Find usage across collections
        $usage = $media ? $this->findMediaUsage($media) : [];

        return $this->render('cms::media/detail', [
            'item' => $media ?: (object)$data,
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

        $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        // CSRF validation
        $csrfToken = $isJson
            ? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
            : ($this->input('csrf_token') ?? null);
        if (!Csrf::validate($csrfToken)) {
            if ($isJson) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => 'CSRF token invalid or missing.']);
                return;
            }
            $this->flash('errors', ['media' => 'CSRF token invalid. Please refresh and try again.']);
            $this->back();
            return;
        }

        if ($isJson) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $input['action'] ?? '';
            $ids = $input['ids'] ?? [];
        } else {
            $action = $this->input('action', '');
            $ids = $this->input('ids', []);
        }

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
                $targetFolder = $isJson ? ($input['target_folder'] ?? '') : trim($this->input('target_folder', ''));
                $this->bulkMove($ids, $targetFolder);
                break;
            default:
                if ($isJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Unknown action.']);
                    return;
                }
                $this->flash('errors', ['media' => 'Unknown action.']);
        }

        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            return;
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

    /**
     * Return all unique tags as JSON for autocomplete.
     */
    public function tags(): void
    {
        $this->requirePermission('media.view');

        $this->ensureTagsColumn();

        $uniqueTags = [];
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $rows = $conn->fetchAllAssociative('SELECT DISTINCT tags FROM cms_media WHERE tags IS NOT NULL AND tags != ?', ['']);
            foreach ($rows as $row) {
                foreach (array_map('trim', explode(',', $row['tags'])) as $t) {
                    if ($t !== '') {
                        $uniqueTags[$t] = true;
                    }
                }
            }
            $uniqueTags = array_keys($uniqueTags);
            sort($uniqueTags);
        } catch (\Throwable $e) {
            $uniqueTags = [];
        }

        header('Content-Type: application/json');
        echo json_encode(['tags' => $uniqueTags]);
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
            // Move to root
            $targetDir = $this->getUploadBase();
            $newRelPrefix = 'storage/cms/uploads';
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
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

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
