<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Media;

class MediaController extends Controller
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

    public function index(): string
    {
        $this->requireAdmin();

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
        $this->requireAdmin();

        $file = $_FILES['file'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('errors', ['file' => 'File upload failed.']);
            $this->back();
            return;
        }

        // Validate file size (10MB max)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->flash('errors', ['file' => 'File size exceeds 10MB limit.']);
            $this->back();
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $uploadDir = $publicPath . '/storage/cms/uploads/' . date('Y') . '/' . date('m');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $relativePath = 'storage/cms/uploads/' . date('Y') . '/' . date('m') . '/' . $filename;

            $media = new Media();
            $media->setFilename($filename);
            $media->setOriginalName($file['name']);
            $media->setPath($relativePath);
            $media->setMimeType($file['type']);
            $media->setSize($file['size']);
            $media->setUploadedBy(Auth::user()?->getId());
            $media->save();

            $this->flash('success', 'File uploaded successfully.');
        } else {
            $this->flash('errors', ['file' => 'Failed to move uploaded file.']);
        }

        $this->back();
    }

    public function destroy(int $id): void
    {
        $this->requireAdmin();

        $media = Media::find($id);
        if (!$media) {
            $this->flash('errors', ['media' => 'File not found.']);
            $this->back();
            return;
        }

        // Delete the actual file
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : $basePath . '/public';
        $filePath = $publicPath . '/' . $media->getPath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $media->delete();

        $this->flash('success', 'File deleted.');
        $this->back();
    }
}
