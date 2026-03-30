<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

class ActivityLogController extends Controller
{
    use CmsAccessTrait;

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
