<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\App\AppManager;
use ZephyrPHP\App\AppInstaller;
use ZephyrPHP\Cms\Services\PermissionService;

class AppController extends Controller
{
    private AppManager $appManager;

    public function __construct()
    {
        parent::__construct();
        $this->appManager = AppManager::getInstance();
    }

    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied.']);
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

    /**
     * List all installed marketplace apps.
     */
    public function index(): string
    {
        $this->requirePermission('apps.view');

        $apps = $this->appManager->list();

        return $this->render('cms::apps/index', [
            'apps' => $apps,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Install an app from an uploaded ZIP file.
     */
    public function installUpload(): void
    {
        $this->requirePermission('apps.manage');

        $file = $_FILES['app_zip'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing server temp folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $this->flash('errors', [$errorMessages[$code] ?? 'Upload error (code: ' . $code . ')']);
            $this->redirect('/cms/apps');
            return;
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes, true)) {
            $this->flash('errors', ['Only ZIP files are allowed. Detected: ' . $mime]);
            $this->redirect('/cms/apps');
            return;
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('errors', ['Only .zip files are allowed.']);
            $this->redirect('/cms/apps');
            return;
        }

        // Check file size (max 50MB)
        if ($file['size'] > 50 * 1024 * 1024) {
            $this->flash('errors', ['ZIP file exceeds maximum size of 50MB.']);
            $this->redirect('/cms/apps');
            return;
        }

        $overwrite = (bool) $this->input('overwrite', false);
        $installer = new AppInstaller($this->appManager);
        $result = $installer->install($file['tmp_name'], $overwrite);

        if ($result['success']) {
            $this->flash('success', "App \"{$result['name']}\" installed successfully.");
        } else {
            $this->flash('errors', [$result['error']]);
        }

        $this->redirect('/cms/apps');
    }

    /**
     * Enable an app.
     */
    public function enable(string $slug): void
    {
        $this->requirePermission('apps.manage');

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid app slug.']);
            $this->redirect('/cms/apps');
            return;
        }

        $result = $this->appManager->enable($slug);

        if ($result['success']) {
            $apps = $this->appManager->list();
            $name = $apps[$slug]['name'] ?? $slug;
            $this->flash('success', "App \"{$name}\" has been enabled.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to enable app.']);
        }

        $this->redirect('/cms/apps');
    }

    /**
     * Disable an app.
     */
    public function disable(string $slug): void
    {
        $this->requirePermission('apps.manage');

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid app slug.']);
            $this->redirect('/cms/apps');
            return;
        }

        $result = $this->appManager->disable($slug);

        if ($result['success']) {
            $apps = $this->appManager->list();
            $name = $apps[$slug]['name'] ?? $slug;
            $this->flash('success', "App \"{$name}\" has been disabled.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to disable app.']);
        }

        $this->redirect('/cms/apps');
    }

    /**
     * Uninstall an app.
     */
    public function uninstallApp(string $slug): void
    {
        $this->requirePermission('apps.manage');

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid app slug.']);
            $this->redirect('/cms/apps');
            return;
        }

        $apps = $this->appManager->list();
        $name = $apps[$slug]['name'] ?? $slug;

        $installer = new AppInstaller($this->appManager);
        $result = $installer->uninstall($slug);

        if ($result['success']) {
            $this->flash('success', "App \"{$name}\" has been uninstalled.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to uninstall app.']);
        }

        $this->redirect('/cms/apps');
    }

    /**
     * Update an app from a new ZIP.
     */
    public function updateApp(string $slug): void
    {
        $this->requirePermission('apps.manage');

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $this->flash('errors', ['Invalid app slug.']);
            $this->redirect('/cms/apps');
            return;
        }

        $file = $_FILES['app_zip'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('errors', ['File upload failed.']);
            $this->redirect('/cms/apps');
            return;
        }

        // Validate MIME + extension
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes, true)) {
            $this->flash('errors', ['Only ZIP files are allowed.']);
            $this->redirect('/cms/apps');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('errors', ['Only .zip files are allowed.']);
            $this->redirect('/cms/apps');
            return;
        }

        $installer = new AppInstaller($this->appManager);
        $result = $installer->update($slug, $file['tmp_name']);

        if ($result['success']) {
            $this->flash('success', "App \"{$result['name'] ?? $slug}\" updated successfully.");
        } else {
            $this->flash('errors', [$result['error'] ?? 'Failed to update app.']);
        }

        $this->redirect('/cms/apps');
    }
}
