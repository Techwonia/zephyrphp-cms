<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

class ActivityLogController extends Controller
{
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

        $page = max(1, (int) $this->input('page', 1));
        $filters = [
            'action' => $this->input('action', ''),
            'resource_type' => $this->input('resource_type', ''),
            'search' => $this->input('search', ''),
        ];

        $logs = ActivityLogger::recent($page, 50, array_filter($filters));

        return $this->render('cms::activity-log/index', [
            'logs' => $logs,
            'filters' => $filters,
            'user' => Auth::user(),
        ]);
    }
}
