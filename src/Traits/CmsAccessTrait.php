<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Traits;

use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

/**
 * Provides CMS access control methods for controllers.
 * Replaces the duplicated requireCmsAccess/requirePermission in every controller.
 */
trait CmsAccessTrait
{
    protected function requireCmsAccess(): void
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

    protected function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect(admin_url());
        }
    }
}
