<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\GlobalBlock;
use ZephyrPHP\Cms\Services\PermissionService;

class GlobalBlockController extends Controller
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
        $this->requirePermission('settings.view');

        $search = trim($this->input('search', ''));
        $page = max(1, (int) $this->input('page', '1'));
        $perPage = 25;

        $blocks = [];
        $total = 0;

        if ($search !== '') {
            try {
                $em = GlobalBlock::getEntityManager();
                $conn = $em->em()->getConnection();

                $searchParam = '%' . $search . '%';
                $countSql = 'SELECT COUNT(*) FROM cms_global_blocks WHERE name LIKE ? OR slug LIKE ?';
                $total = (int) $conn->fetchOne($countSql, [$searchParam, $searchParam]);

                $sql = 'SELECT id FROM cms_global_blocks WHERE name LIKE ? OR slug LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?';
                $rows = $conn->fetchAllAssociative($sql, [
                    $searchParam,
                    $searchParam,
                    $perPage,
                    ($page - 1) * $perPage,
                ]);

                foreach ($rows as $row) {
                    $block = GlobalBlock::find((int) $row['id']);
                    if ($block) {
                        $blocks[] = $block;
                    }
                }
            } catch (\Throwable $e) {
                $blocks = [];
                $total = 0;
            }
        } else {
            $total = GlobalBlock::count();
            $blocks = GlobalBlock::findBy([], ['createdAt' => 'DESC'], $perPage, ($page - 1) * $perPage);
        }

        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->render('cms::global-blocks/index', [
            'blocks' => $blocks,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('settings.edit');

        return $this->render('cms::global-blocks/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('settings.edit');

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $type = trim($this->input('type', 'html'));
        $content = $this->input('content', '');
        $active = $this->input('active', '1') === '1';

        if ($name === '') {
            $this->flash('errors', ['Name is required.']);
            $this->redirect(admin_url('global-blocks/create'));
            return;
        }

        if ($slug === '') {
            $slug = $this->generateSlug($name);
        }

        // Validate slug: alphanumeric and hyphens only
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $slug)) {
            $this->flash('errors', ['Slug must contain only lowercase letters, numbers, and hyphens.']);
            $this->redirect(admin_url('global-blocks/create'));
            return;
        }

        if (!in_array($type, ['html', 'twig'], true)) {
            $type = 'html';
        }

        // Check for duplicate slug
        $existing = GlobalBlock::findOneBy(['slug' => $slug]);
        if ($existing) {
            $this->flash('errors', ['A global block with this slug already exists.']);
            $this->redirect(admin_url('global-blocks/create'));
            return;
        }

        try {
            $block = new GlobalBlock();
            $block->setName($name);
            $block->setSlug($slug);
            $block->setType($type);
            $block->setContent($content);
            $block->setIsActive($active);
            $block->save();

            $this->flash('success', 'Global block created successfully.');
        } catch (\Throwable $e) {
            error_log('Global block creation failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to create global block. Please try again.']);
        }

        $this->redirect(admin_url('global-blocks'));
    }

    public function edit(string $id): string
    {
        $this->requirePermission('settings.view');

        $block = GlobalBlock::find((int) $id);
        if (!$block) {
            $this->flash('errors', ['Global block not found.']);
            $this->redirect(admin_url('global-blocks'));
            return '';
        }

        return $this->render('cms::global-blocks/edit', [
            'block' => $block,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $id): void
    {
        $this->requirePermission('settings.edit');

        $block = GlobalBlock::find((int) $id);
        if (!$block) {
            $this->flash('errors', ['Global block not found.']);
            $this->redirect(admin_url('global-blocks'));
            return;
        }

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $type = trim($this->input('type', 'html'));
        $content = $this->input('content', '');
        $active = $this->input('active', '1') === '1';

        if ($name === '') {
            $this->flash('errors', ['Name is required.']);
            $this->redirect(admin_url('global-blocks/' . $id));
            return;
        }

        if ($slug === '') {
            $slug = $this->generateSlug($name);
        }

        // Validate slug: alphanumeric and hyphens only
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $slug)) {
            $this->flash('errors', ['Slug must contain only lowercase letters, numbers, and hyphens.']);
            $this->redirect(admin_url('global-blocks/' . $id));
            return;
        }

        if (!in_array($type, ['html', 'twig'], true)) {
            $type = 'html';
        }

        // Check for duplicate slug (excluding current block)
        $existing = GlobalBlock::findOneBy(['slug' => $slug]);
        if ($existing && $existing->getId() !== (int) $id) {
            $this->flash('errors', ['A global block with this slug already exists.']);
            $this->redirect(admin_url('global-blocks/' . $id));
            return;
        }

        try {
            $block->setName($name);
            $block->setSlug($slug);
            $block->setType($type);
            $block->setContent($content);
            $block->setIsActive($active);
            $block->save();

            $this->flash('success', 'Global block updated successfully.');
        } catch (\Throwable $e) {
            error_log('Global block update failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to update global block. Please try again.']);
        }

        $this->redirect(admin_url('global-blocks/' . $id));
    }

    public function destroy(string $id): void
    {
        $this->requirePermission('settings.edit');

        $block = GlobalBlock::find((int) $id);
        if (!$block) {
            $this->flash('errors', ['Global block not found.']);
            $this->redirect(admin_url('global-blocks'));
            return;
        }

        try {
            $block->delete();
            $this->flash('success', 'Global block deleted.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to delete global block.']);
        }

        $this->redirect(admin_url('global-blocks'));
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }
}
