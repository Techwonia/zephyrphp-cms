<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Models\Redirect;
use ZephyrPHP\Cms\Services\PermissionService;

class RedirectController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $search = trim($this->input('search', ''));
        $page = max(1, (int) $this->input('page', '1'));
        $perPage = 25;

        $criteria = [];
        $redirects = [];
        $total = 0;

        if ($search !== '') {
            // Use query builder for LIKE search
            try {
                $em = Redirect::getEntityManager();
                $conn = $em->em()->getConnection();

                $countSql = 'SELECT COUNT(*) FROM cms_redirects WHERE from_path LIKE ? OR to_url LIKE ?';
                $searchParam = '%' . $search . '%';
                $total = (int) $conn->fetchOne($countSql, [$searchParam, $searchParam]);

                $sql = 'SELECT id FROM cms_redirects WHERE from_path LIKE ? OR to_url LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?';
                $rows = $conn->fetchAllAssociative($sql, [
                    $searchParam,
                    $searchParam,
                    $perPage,
                    ($page - 1) * $perPage,
                ]);

                foreach ($rows as $row) {
                    $redirect = Redirect::find((int) $row['id']);
                    if ($redirect) {
                        $redirects[] = $redirect;
                    }
                }
            } catch (\Throwable $e) {
                $redirects = [];
                $total = 0;
            }
        } else {
            $total = Redirect::count();
            $redirects = Redirect::findBy([], ['createdAt' => 'DESC'], $perPage, ($page - 1) * $perPage);
        }

        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->render('cms::redirects/index', [
            'redirects' => $redirects,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('settings.edit');

        $fromPath = trim($this->input('from_path', ''));
        $toUrl = trim($this->input('to_url', ''));
        $statusCode = (int) $this->input('status_code', '301');

        // Validate
        if ($fromPath === '') {
            $this->flash('errors', ['From Path is required.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        if ($toUrl === '') {
            $this->flash('errors', ['To URL is required.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        if (!in_array($statusCode, [301, 302], true)) {
            $statusCode = 301;
        }

        // Ensure fromPath starts with /
        if (!str_starts_with($fromPath, '/')) {
            $fromPath = '/' . $fromPath;
        }

        // Check for duplicate fromPath
        $existing = Redirect::findOneBy(['fromPath' => $fromPath]);
        if ($existing) {
            $this->flash('errors', ['A redirect for this path already exists.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        try {
            $redirect = new Redirect();
            $redirect->setFromPath($fromPath);
            $redirect->setToUrl($toUrl);
            $redirect->setStatusCode($statusCode);
            $redirect->setIsActive(true);
            $redirect->setHitCount(0);
            $redirect->save();

            $this->flash('success', 'Redirect created successfully.');
        } catch (\Throwable $e) {
            error_log('Redirect creation failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to create redirect. Please try again.']);
        }

        $this->redirect(admin_url('redirects'));
    }

    public function update(string $id): void
    {
        $this->requirePermission('settings.edit');

        $redirect = Redirect::find((int) $id);
        if (!$redirect) {
            $this->flash('errors', ['Redirect not found.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        $fromPath = trim($this->input('from_path', ''));
        $toUrl = trim($this->input('to_url', ''));
        $statusCode = (int) $this->input('status_code', '301');

        if ($fromPath === '') {
            $this->flash('errors', ['From Path is required.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        if ($toUrl === '') {
            $this->flash('errors', ['To URL is required.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        if (!in_array($statusCode, [301, 302], true)) {
            $statusCode = 301;
        }

        if (!str_starts_with($fromPath, '/')) {
            $fromPath = '/' . $fromPath;
        }

        // Check for duplicate fromPath (excluding current redirect)
        $existing = Redirect::findOneBy(['fromPath' => $fromPath]);
        if ($existing && $existing->getId() !== (int) $id) {
            $this->flash('errors', ['A redirect for this path already exists.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        try {
            $redirect->setFromPath($fromPath);
            $redirect->setToUrl($toUrl);
            $redirect->setStatusCode($statusCode);
            $redirect->save();

            $this->flash('success', 'Redirect updated successfully.');
        } catch (\Throwable $e) {
            error_log('Redirect update failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to update redirect. Please try again.']);
        }

        $this->redirect(admin_url('redirects'));
    }

    public function destroy(string $id): void
    {
        $this->requirePermission('settings.edit');

        $redirect = Redirect::find((int) $id);
        if (!$redirect) {
            $this->flash('errors', ['Redirect not found.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        try {
            $redirect->delete();
            $this->flash('success', 'Redirect deleted.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to delete redirect.']);
        }

        $this->redirect(admin_url('redirects'));
    }

    public function toggleStatus(string $id): void
    {
        $this->requirePermission('settings.edit');

        $redirect = Redirect::find((int) $id);
        if (!$redirect) {
            $this->flash('errors', ['Redirect not found.']);
            $this->redirect(admin_url('redirects'));
            return;
        }

        try {
            $redirect->setIsActive(!$redirect->isActive());
            $redirect->save();

            $statusLabel = $redirect->isActive() ? 'enabled' : 'disabled';
            $this->flash('success', "Redirect {$statusLabel}.");
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to toggle redirect status.']);
        }

        $this->redirect(admin_url('redirects'));
    }
}
