<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Router\Route;

class RouteViewerController extends Controller
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

        $search = $this->input('search', '');
        $filterMethod = $this->input('method', '');

        $routes = $this->getRoutes();

        // Apply filters
        if ($search !== '') {
            $routes = array_filter($routes, fn($r) => stripos($r['uri'], $search) !== false
                || stripos($r['action'], $search) !== false
                || stripos($r['name'] ?? '', $search) !== false
            );
        }

        if ($filterMethod !== '') {
            $routes = array_filter($routes, fn($r) => strtoupper($r['method']) === strtoupper($filterMethod));
        }

        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        return $this->render('cms::system/routes', [
            'routes' => array_values($routes),
            'search' => $search,
            'filterMethod' => $filterMethod,
            'methods' => $methods,
            'totalRoutes' => count($routes),
            'user' => Auth::user(),
        ]);
    }

    private function getRoutes(): array
    {
        $routes = [];

        // Use Route's static method if available
        if (method_exists(Route::class, 'getRoutes')) {
            $registeredRoutes = Route::getRoutes();

            foreach ($registeredRoutes as $route) {
                $action = $route['action'] ?? '';
                if (is_array($action)) {
                    $action = implode('@', $action);
                } elseif ($action instanceof \Closure) {
                    $action = 'Closure';
                }

                $middleware = $route['middleware'] ?? [];
                if (is_array($middleware)) {
                    $middleware = array_map(fn($m) => is_string($m) ? class_basename_str($m) : 'Closure', $middleware);
                }

                $routes[] = [
                    'method' => strtoupper($route['method'] ?? 'GET'),
                    'uri' => $route['uri'] ?? $route['pattern'] ?? '',
                    'action' => $action,
                    'name' => $route['name'] ?? '',
                    'middleware' => is_array($middleware) ? implode(', ', $middleware) : '',
                ];
            }
        }

        // Sort by URI
        usort($routes, fn($a, $b) => strcmp($a['uri'], $b['uri']));

        return $routes;
    }
}

/**
 * Get class basename from a fully-qualified class name string.
 */
function class_basename_str(string $class): string
{
    $pos = strrpos($class, '\\');
    return $pos === false ? $class : substr($class, $pos + 1);
}
