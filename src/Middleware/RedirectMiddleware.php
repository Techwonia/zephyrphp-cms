<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Middleware;

use ZephyrPHP\Cms\Models\Redirect;

/**
 * Intercepts incoming requests and performs URL redirects
 * based on the cms_redirects table entries.
 *
 * Skips admin paths to avoid interfering with the CMS panel.
 */
class RedirectMiddleware
{
    public function handle($request = null, ?callable $next = null)
    {
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

            // Skip admin paths
            $adminPrefix = function_exists('admin_path') ? '/' . admin_path() : '/admin';
            if (str_starts_with($path, $adminPrefix)) {
                return $next ? $next($request) : true;
            }

            $redirect = Redirect::findOneBy(['fromPath' => $path, 'isActive' => true]);

            if ($redirect) {
                $redirect->setHitCount($redirect->getHitCount() + 1);
                $redirect->setLastHitAt(new \DateTime());
                $redirect->save();

                header('Location: ' . $redirect->getToUrl(), true, $redirect->getStatusCode());
                exit;
            }
        } catch (\Throwable $e) {
            // Silently fail — redirect lookup should not break the request
        }

        return $next ? $next($request) : true;
    }
}
