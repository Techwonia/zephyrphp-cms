<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class FileManagerController extends Controller
{
    /** Directories accessible through the file manager */
    private const ALLOWED_ROOTS = [
        'config' => 'config',
        'routes' => 'routes',
        'lang' => 'lang',
        'views' => 'pages',
        'storage' => 'storage',
    ];

    /** File extensions that can be edited */
    private const EDITABLE_EXTENSIONS = ['php', 'twig', 'html', 'css', 'js', 'json', 'yaml', 'yml', 'md', 'txt', 'env', 'xml', 'ini'];

    /** File extensions that are never accessible */
    private const BLOCKED_EXTENSIONS = ['phar', 'sh', 'bat', 'exe', 'dll', 'so'];

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

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $root = $this->input('root', 'config');
        $path = $this->input('path', '');

        if (!isset(self::ALLOWED_ROOTS[$root])) {
            $root = 'config';
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $rootDir = $basePath . '/' . self::ALLOWED_ROOTS[$root];
        $fullPath = $rootDir . ($path ? '/' . $this->sanitizePath($path) : '');

        // Security: ensure path is within allowed root
        $realRoot = realpath($rootDir);
        $realPath = realpath($fullPath);

        if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot)) {
            $fullPath = $rootDir;
            $path = '';
        }

        $items = $this->listDirectory($fullPath);

        // Breadcrumb
        $breadcrumbs = $this->buildBreadcrumbs($root, $path);

        return $this->render('cms::system/file-manager', [
            'root' => $root,
            'roots' => array_keys(self::ALLOWED_ROOTS),
            'path' => $path,
            'items' => $items,
            'breadcrumbs' => $breadcrumbs,
            'user' => Auth::user(),
        ]);
    }

    public function edit(): string
    {
        $this->requirePermission('settings.view');

        $root = $this->input('root', 'config');
        $file = $this->input('file', '');

        if (!isset(self::ALLOWED_ROOTS[$root]) || $file === '') {
            $this->redirect('/cms/system/files');
            return '';
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $rootDir = $basePath . '/' . self::ALLOWED_ROOTS[$root];
        $filePath = $rootDir . '/' . $this->sanitizePath($file);

        // Security check
        $realRoot = realpath($rootDir);
        $realFile = realpath($filePath);

        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot)) {
            $this->flash('errors', ['File not found.']);
            $this->redirect('/cms/system/files');
            return '';
        }

        if (!is_file($realFile)) {
            $this->flash('errors', ['Not a file.']);
            $this->redirect('/cms/system/files');
            return '';
        }

        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EDITABLE_EXTENSIONS, true)) {
            $this->flash('errors', ['This file type cannot be edited.']);
            $this->redirect('/cms/system/files');
            return '';
        }

        $content = file_get_contents($realFile);

        return $this->render('cms::system/file-edit', [
            'root' => $root,
            'file' => $file,
            'filename' => basename($file),
            'content' => $content,
            'extension' => $ext,
            'size' => $this->formatBytes(filesize($realFile)),
            'modified' => date('Y-m-d H:i:s', filemtime($realFile)),
            'writable' => is_writable($realFile),
            'user' => Auth::user(),
        ]);
    }

    public function save(): void
    {
        $this->requirePermission('settings.edit');

        $root = $this->input('root', 'config');
        $file = $this->input('file', '');
        $content = $this->input('content', '');

        if (!isset(self::ALLOWED_ROOTS[$root]) || $file === '') {
            $this->flash('errors', ['Invalid request.']);
            $this->redirect('/cms/system/files');
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $rootDir = $basePath . '/' . self::ALLOWED_ROOTS[$root];
        $filePath = $rootDir . '/' . $this->sanitizePath($file);

        // Security check
        $realRoot = realpath($rootDir);
        $realFile = realpath($filePath);

        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot)) {
            $this->flash('errors', ['File not found.']);
            $this->redirect('/cms/system/files');
            return;
        }

        if (!is_writable($realFile)) {
            $this->flash('errors', ['File is not writable.']);
            $this->redirect('/cms/system/files?root=' . $root . '&file=' . urlencode($file));
            return;
        }

        // Block potentially dangerous file extensions
        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            $this->flash('errors', ['This file type cannot be modified.']);
            $this->redirect('/cms/system/files');
            return;
        }

        file_put_contents($realFile, $content, LOCK_EX);

        $this->flash('success', 'File saved successfully.');
        $this->redirect('/cms/system/files/edit?root=' . $root . '&file=' . urlencode($file));
    }

    private function listDirectory(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $items = [];
        $entries = scandir($path);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . '/' . $entry;
            $isDir = is_dir($fullPath);
            $ext = $isDir ? '' : strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            // Skip blocked extensions
            if (!$isDir && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                continue;
            }

            $items[] = [
                'name' => $entry,
                'is_dir' => $isDir,
                'size' => $isDir ? '' : $this->formatBytes(filesize($fullPath)),
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'editable' => !$isDir && in_array($ext, self::EDITABLE_EXTENSIONS, true),
                'extension' => $ext,
            ];
        }

        // Directories first, then files
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] <=> $a['is_dir'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    private function buildBreadcrumbs(string $root, string $path): array
    {
        $crumbs = [['label' => ucfirst($root), 'url' => '/cms/system/files?root=' . $root]];

        if ($path !== '') {
            $parts = explode('/', trim($path, '/'));
            $accumulated = '';
            foreach ($parts as $part) {
                $accumulated .= ($accumulated ? '/' : '') . $part;
                $crumbs[] = [
                    'label' => $part,
                    'url' => '/cms/system/files?root=' . $root . '&path=' . urlencode($accumulated),
                ];
            }
        }

        return $crumbs;
    }

    private function sanitizePath(string $path): string
    {
        // Remove dangerous characters and path traversal
        $path = str_replace(['..', "\0"], '', $path);
        $path = preg_replace('#[/\\\\]+#', '/', $path);
        return trim($path, '/');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
