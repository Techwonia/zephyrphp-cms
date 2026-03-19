<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * HookManager — WordPress-style actions and filters for the plugin system.
 *
 * Actions: fire callbacks at specific points (no return value expected).
 * Filters: pass a value through a chain of callbacks, each may modify and return it.
 */
class HookManager
{
    /** @var array<string, array<int, array<int, callable>>> Actions keyed by hook name, then priority. */
    private static array $actions = [];

    /** @var array<string, array<int, array<int, callable>>> Filters keyed by hook name, then priority. */
    private static array $filters = [];

    /** @var array<string, int> Tracks how many times each action has fired (prevents infinite loops). */
    private static array $actionCounts = [];

    /** Maximum recursion depth for any single hook to prevent infinite loops. */
    private const MAX_RECURSION = 50;

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Register an action callback for a hook.
     *
     * @param string   $hook     Hook name (e.g. 'before_render', 'plugin.activated').
     * @param callable $callback Callback to invoke.
     * @param int      $priority Lower runs first. Default 10.
     */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $hook = self::sanitizeHookName($hook);
        self::$actions[$hook][$priority][] = $callback;
    }

    /**
     * Fire all callbacks registered for an action hook.
     *
     * @param string $hook Hook name.
     * @param mixed  ...$args Arguments passed to each callback.
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        $hook = self::sanitizeHookName($hook);

        if (!isset(self::$actions[$hook])) {
            return;
        }

        // Guard against infinite recursion
        self::$actionCounts[$hook] = (self::$actionCounts[$hook] ?? 0) + 1;
        if (self::$actionCounts[$hook] > self::MAX_RECURSION) {
            self::$actionCounts[$hook]--;
            return;
        }

        $callbacks = self::$actions[$hook];
        ksort($callbacks, SORT_NUMERIC);

        foreach ($callbacks as $priorityGroup) {
            foreach ($priorityGroup as $callback) {
                $callback(...$args);
            }
        }

        self::$actionCounts[$hook]--;
    }

    /**
     * Remove a specific action callback.
     */
    public static function removeAction(string $hook, callable $callback, int $priority = 10): void
    {
        $hook = self::sanitizeHookName($hook);

        if (!isset(self::$actions[$hook][$priority])) {
            return;
        }

        self::$actions[$hook][$priority] = array_filter(
            self::$actions[$hook][$priority],
            fn(callable $cb) => $cb !== $callback
        );
    }

    /**
     * Check whether any callbacks are registered for an action hook.
     */
    public static function hasAction(string $hook): bool
    {
        $hook = self::sanitizeHookName($hook);
        return !empty(self::$actions[$hook]);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    /**
     * Register a filter callback for a hook.
     *
     * @param string   $hook     Hook name (e.g. 'content', 'page_title').
     * @param callable $callback Receives the current value as first arg, must return modified value.
     * @param int      $priority Lower runs first. Default 10.
     */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $hook = self::sanitizeHookName($hook);
        self::$filters[$hook][$priority][] = $callback;
    }

    /**
     * Pass a value through all filter callbacks registered for a hook.
     *
     * @param string $hook  Hook name.
     * @param mixed  $value The value to filter.
     * @param mixed  ...$args Additional arguments passed to each callback after $value.
     * @return mixed The filtered value.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $hook = self::sanitizeHookName($hook);

        if (!isset(self::$filters[$hook])) {
            return $value;
        }

        $callbacks = self::$filters[$hook];
        ksort($callbacks, SORT_NUMERIC);

        foreach ($callbacks as $priorityGroup) {
            foreach ($priorityGroup as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }

    /**
     * Remove a specific filter callback.
     */
    public static function removeFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $hook = self::sanitizeHookName($hook);

        if (!isset(self::$filters[$hook][$priority])) {
            return;
        }

        self::$filters[$hook][$priority] = array_filter(
            self::$filters[$hook][$priority],
            fn(callable $cb) => $cb !== $callback
        );
    }

    /**
     * Check whether any callbacks are registered for a filter hook.
     */
    public static function hasFilter(string $hook): bool
    {
        $hook = self::sanitizeHookName($hook);
        return !empty(self::$filters[$hook]);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Reset all hooks (useful for testing).
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$actionCounts = [];
    }

    /**
     * Get all registered action hook names.
     *
     * @return string[]
     */
    public static function getRegisteredActions(): array
    {
        return array_keys(self::$actions);
    }

    /**
     * Get all registered filter hook names.
     *
     * @return string[]
     */
    public static function getRegisteredFilters(): array
    {
        return array_keys(self::$filters);
    }

    /**
     * Sanitize a hook name to prevent injection via malformed names.
     */
    private static function sanitizeHookName(string $hook): string
    {
        // Allow only alphanumeric, dots, underscores, hyphens
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $hook);
    }
}
