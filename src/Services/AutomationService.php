<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\AutomationRule;
use ZephyrPHP\Cms\Models\Collection;

/**
 * Automation Service — evaluates and executes automation rules.
 *
 * Supports scheduled rules (run periodically) and event-based rules
 * (triggered on create, update, delete, publish).
 */
class AutomationService
{
    /** Valid trigger types */
    public const TRIGGERS = [
        'schedule'   => 'Scheduled',
        'on_create'  => 'On Create',
        'on_update'  => 'On Update',
        'on_delete'  => 'On Delete',
        'on_publish' => 'On Publish',
    ];

    /** Valid schedule intervals */
    public const SCHEDULES = [
        'hourly'  => 'Every Hour',
        'daily'   => 'Every Day',
        'weekly'  => 'Every Week',
    ];

    /** Valid condition operators */
    public const OPERATORS = [
        'equals'       => 'Equals',
        'not_equals'   => 'Not Equals',
        'contains'     => 'Contains',
        'older_than'   => 'Older Than',
        'newer_than'   => 'Newer Than',
        'is_empty'     => 'Is Empty',
        'is_not_empty' => 'Is Not Empty',
    ];

    /** Valid action types */
    public const ACTION_TYPES = [
        'unpublish'    => 'Unpublish',
        'publish'      => 'Publish',
        'delete'       => 'Delete',
        'update_field' => 'Update Field',
        'notify'       => 'Send Notification',
    ];

    /**
     * Run all active scheduled rules that are due.
     * Returns the number of rules executed.
     */
    public static function runScheduledRules(): int
    {
        try {
            $rules = AutomationRule::findBy([
                'triggerType' => 'schedule',
                'isActive'    => true,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }

        $executed = 0;
        $now = new \DateTime();

        foreach ($rules as $rule) {
            if (!self::isDue($rule, $now)) {
                continue;
            }

            try {
                self::executeScheduledRule($rule);
                $rule->setLastRunAt($now);
                $rule->save();
                $executed++;
            } catch (\Throwable $e) {
                error_log("Automation rule #{$rule->getId()} ({$rule->getName()}) failed: " . $e->getMessage());
            }
        }

        return $executed;
    }

    /**
     * Run all active event-based rules matching a trigger and collection.
     */
    public static function runEventRules(string $trigger, string $collectionSlug, array $entryData): void
    {
        $allowedTriggers = ['on_create', 'on_update', 'on_delete', 'on_publish'];
        if (!in_array($trigger, $allowedTriggers, true)) {
            return;
        }

        try {
            $rules = AutomationRule::findBy([
                'triggerType'    => $trigger,
                'collectionSlug' => $collectionSlug,
                'isActive'       => true,
            ]);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rules as $rule) {
            try {
                if (self::evaluateConditions($rule->getConditions(), $entryData)) {
                    self::executeActions($rule->getActions(), $collectionSlug, [$entryData]);

                    $rule->setLastRunAt(new \DateTime());
                    $rule->save();
                }
            } catch (\Throwable $e) {
                error_log("Automation rule #{$rule->getId()} ({$rule->getName()}) event failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Manually execute a single rule (for testing).
     * Returns the number of entries affected.
     */
    public static function executeRule(AutomationRule $rule): int
    {
        $entries = self::findMatchingEntries($rule);
        if (empty($entries)) {
            return 0;
        }

        self::executeActions($rule->getActions(), $rule->getCollectionSlug(), $entries);

        $rule->setLastRunAt(new \DateTime());
        $rule->save();

        return count($entries);
    }

    /**
     * Check if a scheduled rule is due to run.
     */
    private static function isDue(AutomationRule $rule, \DateTime $now): bool
    {
        $lastRun = $rule->getLastRunAt();
        if ($lastRun === null) {
            return true; // Never run — run now
        }

        $schedule = $rule->getSchedule();
        $diff = $now->getTimestamp() - $lastRun->getTimestamp();

        return match ($schedule) {
            'hourly' => $diff >= 3600,
            'daily'  => $diff >= 86400,
            'weekly' => $diff >= 604800,
            default  => false,
        };
    }

    /**
     * Execute a scheduled rule: find matching entries and run actions.
     */
    private static function executeScheduledRule(AutomationRule $rule): void
    {
        $entries = self::findMatchingEntries($rule);
        if (empty($entries)) {
            return;
        }

        self::executeActions($rule->getActions(), $rule->getCollectionSlug(), $entries);
    }

    /**
     * Find all entries matching a rule's conditions.
     */
    private static function findMatchingEntries(AutomationRule $rule): array
    {
        try {
            $allEntries = EntryQuery::collection($rule->getCollectionSlug())
                ->noCache()
                ->limit(1000)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $conditions = $rule->getConditions();
        if (empty($conditions)) {
            return $allEntries;
        }

        $matching = [];
        foreach ($allEntries as $entry) {
            if (self::evaluateConditions($conditions, $entry)) {
                $matching[] = $entry;
            }
        }

        return $matching;
    }

    /**
     * Evaluate all conditions against an entry. All conditions must pass (AND logic).
     */
    private static function evaluateConditions(array $conditions, array $entry): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '';
            $value = $condition['value'] ?? '';
            $entryValue = $entry[$field] ?? null;

            $pass = match ($operator) {
                'equals'       => (string) $entryValue === (string) $value,
                'not_equals'   => (string) $entryValue !== (string) $value,
                'contains'     => is_string($entryValue) && str_contains($entryValue, $value),
                'older_than'   => self::isOlderThan($entryValue, $value),
                'newer_than'   => self::isNewerThan($entryValue, $value),
                'is_empty'     => $entryValue === null || $entryValue === '',
                'is_not_empty' => $entryValue !== null && $entryValue !== '',
                default        => true,
            };

            if (!$pass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute actions on matching entries.
     */
    private static function executeActions(array $actions, string $collectionSlug, array $entries): void
    {
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = $action['type'] ?? '';

            switch ($type) {
                case 'unpublish':
                    self::actionUnpublish($collectionSlug, $entries);
                    break;

                case 'publish':
                    self::actionPublish($collectionSlug, $entries);
                    break;

                case 'delete':
                    self::actionDelete($collectionSlug, $entries);
                    break;

                case 'update_field':
                    $field = $action['field'] ?? '';
                    $fieldValue = $action['value'] ?? '';
                    if ($field !== '') {
                        self::actionUpdateField($collectionSlug, $entries, $field, $fieldValue);
                    }
                    break;

                case 'notify':
                    $message = $action['message'] ?? 'Automation rule triggered';
                    self::actionNotify($collectionSlug, $entries, $message);
                    break;
            }
        }
    }

    /**
     * Check if a date value is older than a given duration string (e.g. "365 days", "30 days").
     */
    private static function isOlderThan($dateValue, string $duration): bool
    {
        if (empty($dateValue)) {
            return false;
        }

        try {
            $date = $dateValue instanceof \DateTime ? $dateValue : new \DateTime((string) $dateValue);
            $threshold = new \DateTime("-{$duration}");
            return $date < $threshold;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a date value is newer than a given duration string.
     */
    private static function isNewerThan($dateValue, string $duration): bool
    {
        if (empty($dateValue)) {
            return false;
        }

        try {
            $date = $dateValue instanceof \DateTime ? $dateValue : new \DateTime((string) $dateValue);
            $threshold = new \DateTime("-{$duration}");
            return $date > $threshold;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // --- Action Implementations ---

    private static function actionUnpublish(string $collectionSlug, array $entries): void
    {
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            if ($id === null) continue;

            try {
                EntryQuery::collection($collectionSlug)->update($id, ['status' => 'draft']);
                ActivityLogger::log('unpublished', 'entry', (string) $id, $entry['title'] ?? $entry['name'] ?? "#{$id}", [
                    'collection' => $collectionSlug,
                    'source' => 'automation',
                ]);
            } catch (\Throwable $e) {
                error_log("Automation unpublish entry #{$id} failed: " . $e->getMessage());
            }
        }
    }

    private static function actionPublish(string $collectionSlug, array $entries): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            if ($id === null) continue;

            try {
                EntryQuery::collection($collectionSlug)->update($id, [
                    'status' => 'published',
                    'published_at' => $now,
                ]);
                ActivityLogger::log('published', 'entry', (string) $id, $entry['title'] ?? $entry['name'] ?? "#{$id}", [
                    'collection' => $collectionSlug,
                    'source' => 'automation',
                ]);
            } catch (\Throwable $e) {
                error_log("Automation publish entry #{$id} failed: " . $e->getMessage());
            }
        }
    }

    private static function actionDelete(string $collectionSlug, array $entries): void
    {
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            if ($id === null) continue;

            try {
                EntryQuery::collection($collectionSlug)->delete($id);
                ActivityLogger::log('deleted', 'entry', (string) $id, $entry['title'] ?? $entry['name'] ?? "#{$id}", [
                    'collection' => $collectionSlug,
                    'source' => 'automation',
                ]);
            } catch (\Throwable $e) {
                error_log("Automation delete entry #{$id} failed: " . $e->getMessage());
            }
        }
    }

    private static function actionUpdateField(string $collectionSlug, array $entries, string $field, string $value): void
    {
        // Whitelist: only allow updating known safe column names (alphanumeric + underscore)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return;
        }

        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            if ($id === null) continue;

            try {
                EntryQuery::collection($collectionSlug)->update($id, [$field => $value]);
                ActivityLogger::log('updated', 'entry', (string) $id, $entry['title'] ?? $entry['name'] ?? "#{$id}", [
                    'collection' => $collectionSlug,
                    'source' => 'automation',
                    'field' => $field,
                ]);
            } catch (\Throwable $e) {
                error_log("Automation update_field entry #{$id} failed: " . $e->getMessage());
            }
        }
    }

    private static function actionNotify(string $collectionSlug, array $entries, string $message): void
    {
        $count = count($entries);
        $title = "Automation: {$message}";
        $body = "Automation rule matched {$count} " . ($count === 1 ? 'entry' : 'entries') . " in collection \"{$collectionSlug}\".";

        try {
            NotificationService::notifyAdmins(
                'automation_triggered',
                $title,
                $body,
                admin_url("collections/{$collectionSlug}/entries"),
                ['collection' => $collectionSlug, 'count' => $count]
            );
        } catch (\Throwable $e) {
            error_log("Automation notify failed: " . $e->getMessage());
        }
    }
}
