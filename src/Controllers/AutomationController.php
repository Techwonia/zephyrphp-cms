<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Models\AutomationRule;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\AutomationService;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Cms\Services\ActivityLogger;

class AutomationController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $page = max(1, (int) $this->input('page', '1'));
        $perPage = 25;

        $total = AutomationRule::count();
        $rules = AutomationRule::findBy([], ['createdAt' => 'DESC'], $perPage, ($page - 1) * $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Build collection name map
        $collections = Collection::findAll();
        $collectionMap = [];
        foreach ($collections as $c) {
            $collectionMap[$c->getSlug()] = $c->getName();
        }

        return $this->render('cms::automations/index', [
            'rules' => $rules,
            'collectionMap' => $collectionMap,
            'triggers' => AutomationService::TRIGGERS,
            'schedules' => AutomationService::SCHEDULES,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('settings.edit');

        $collections = Collection::findAll();

        return $this->render('cms::automations/create', [
            'collections' => $collections,
            'triggers' => AutomationService::TRIGGERS,
            'schedules' => AutomationService::SCHEDULES,
            'operators' => AutomationService::OPERATORS,
            'actionTypes' => AutomationService::ACTION_TYPES,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('settings.edit');

        $name = trim($this->input('name', ''));
        $collectionSlug = trim($this->input('collection_slug', ''));
        $triggerType = trim($this->input('trigger_type', 'schedule'));
        $schedule = trim($this->input('schedule', ''));
        $isActive = (bool) $this->input('is_active', '1');

        // Validate required fields
        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($collectionSlug === '') {
            $errors[] = 'Collection is required.';
        }
        if (!array_key_exists($triggerType, AutomationService::TRIGGERS)) {
            $errors[] = 'Invalid trigger type.';
        }
        if ($triggerType === 'schedule' && !array_key_exists($schedule, AutomationService::SCHEDULES)) {
            $errors[] = 'Schedule interval is required for scheduled triggers.';
        }

        // Validate collection exists
        if ($collectionSlug !== '') {
            $collection = Collection::findOneBy(['slug' => $collectionSlug]);
            if (!$collection) {
                $errors[] = 'Selected collection does not exist.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', $_POST);
            $this->back();
            return;
        }

        // Parse conditions and actions from form
        $conditions = $this->parseConditionsFromForm();
        $actions = $this->parseActionsFromForm();

        try {
            $rule = new AutomationRule();
            $rule->setName($name);
            $rule->setCollectionSlug($collectionSlug);
            $rule->setTriggerType($triggerType);
            $rule->setConditions($conditions);
            $rule->setActions($actions);
            $rule->setSchedule($triggerType === 'schedule' ? $schedule : null);
            $rule->setIsActive($isActive);
            $rule->save();

            ActivityLogger::log('created', 'automation', (string) $rule->getId(), $name);

            $this->flash('success', 'Automation rule created successfully.');
        } catch (\Throwable $e) {
            error_log('Automation rule creation failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to create automation rule. Please try again.']);
            $this->flash('_old_input', $_POST);
            $this->back();
            return;
        }

        $this->redirect(admin_url('automations'));
    }

    public function edit(string $id): string
    {
        $this->requirePermission('settings.edit');

        $rule = AutomationRule::find((int) $id);
        if (!$rule) {
            $this->flash('errors', ['Automation rule not found.']);
            $this->redirect(admin_url('automations'));
            return '';
        }

        $collections = Collection::findAll();

        return $this->render('cms::automations/edit', [
            'rule' => $rule,
            'collections' => $collections,
            'triggers' => AutomationService::TRIGGERS,
            'schedules' => AutomationService::SCHEDULES,
            'operators' => AutomationService::OPERATORS,
            'actionTypes' => AutomationService::ACTION_TYPES,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $id): void
    {
        $this->requirePermission('settings.edit');

        $rule = AutomationRule::find((int) $id);
        if (!$rule) {
            $this->flash('errors', ['Automation rule not found.']);
            $this->redirect(admin_url('automations'));
            return;
        }

        $name = trim($this->input('name', ''));
        $collectionSlug = trim($this->input('collection_slug', ''));
        $triggerType = trim($this->input('trigger_type', 'schedule'));
        $schedule = trim($this->input('schedule', ''));
        $isActive = (bool) $this->input('is_active', '1');

        // Validate
        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($collectionSlug === '') {
            $errors[] = 'Collection is required.';
        }
        if (!array_key_exists($triggerType, AutomationService::TRIGGERS)) {
            $errors[] = 'Invalid trigger type.';
        }
        if ($triggerType === 'schedule' && !array_key_exists($schedule, AutomationService::SCHEDULES)) {
            $errors[] = 'Schedule interval is required for scheduled triggers.';
        }

        if ($collectionSlug !== '') {
            $collection = Collection::findOneBy(['slug' => $collectionSlug]);
            if (!$collection) {
                $errors[] = 'Selected collection does not exist.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $conditions = $this->parseConditionsFromForm();
        $actions = $this->parseActionsFromForm();

        try {
            $rule->setName($name);
            $rule->setCollectionSlug($collectionSlug);
            $rule->setTriggerType($triggerType);
            $rule->setConditions($conditions);
            $rule->setActions($actions);
            $rule->setSchedule($triggerType === 'schedule' ? $schedule : null);
            $rule->setIsActive($isActive);
            $rule->save();

            ActivityLogger::log('updated', 'automation', (string) $rule->getId(), $name);

            $this->flash('success', 'Automation rule updated successfully.');
        } catch (\Throwable $e) {
            error_log('Automation rule update failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to update automation rule. Please try again.']);
            $this->back();
            return;
        }

        $this->redirect(admin_url('automations'));
    }

    public function destroy(string $id): void
    {
        $this->requirePermission('settings.edit');

        $rule = AutomationRule::find((int) $id);
        if (!$rule) {
            $this->flash('errors', ['Automation rule not found.']);
            $this->redirect(admin_url('automations'));
            return;
        }

        try {
            $name = $rule->getName();
            $rule->delete();
            ActivityLogger::log('deleted', 'automation', $id, $name);
            $this->flash('success', 'Automation rule deleted.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to delete automation rule.']);
        }

        $this->redirect(admin_url('automations'));
    }

    public function run(string $id): void
    {
        $this->requirePermission('settings.edit');

        $rule = AutomationRule::find((int) $id);
        if (!$rule) {
            $this->flash('errors', ['Automation rule not found.']);
            $this->redirect(admin_url('automations'));
            return;
        }

        try {
            $affected = AutomationService::executeRule($rule);
            ActivityLogger::log('executed', 'automation', $id, $rule->getName(), ['affected' => $affected]);
            $this->flash('success', "Automation rule executed. {$affected} " . ($affected === 1 ? 'entry' : 'entries') . ' affected.');
        } catch (\Throwable $e) {
            error_log('Automation rule manual run failed: ' . $e->getMessage());
            $this->flash('errors', ['Failed to execute automation rule: ' . $e->getMessage()]);
        }

        $this->redirect(admin_url('automations'));
    }

    /**
     * Parse conditions from POST form data.
     * Expects: condition_field[], condition_operator[], condition_value[]
     */
    private function parseConditionsFromForm(): array
    {
        $fields = $_POST['condition_field'] ?? [];
        $operators = $_POST['condition_operator'] ?? [];
        $values = $_POST['condition_value'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        $conditions = [];
        $validOperators = array_keys(AutomationService::OPERATORS);

        for ($i = 0, $len = count($fields); $i < $len; $i++) {
            $field = trim((string) ($fields[$i] ?? ''));
            $operator = trim((string) ($operators[$i] ?? ''));
            $value = trim((string) ($values[$i] ?? ''));

            if ($field === '' || !in_array($operator, $validOperators, true)) {
                continue;
            }

            // Validate field name (alphanumeric + underscore only)
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                continue;
            }

            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $conditions;
    }

    /**
     * Parse actions from POST form data.
     * Expects: action_type[], action_field[], action_value[], action_message[]
     */
    private function parseActionsFromForm(): array
    {
        $types = $_POST['action_type'] ?? [];
        $fields = $_POST['action_field'] ?? [];
        $values = $_POST['action_value'] ?? [];
        $messages = $_POST['action_message'] ?? [];

        if (!is_array($types)) {
            return [];
        }

        $actions = [];
        $validTypes = array_keys(AutomationService::ACTION_TYPES);

        for ($i = 0, $len = count($types); $i < $len; $i++) {
            $type = trim((string) ($types[$i] ?? ''));

            if (!in_array($type, $validTypes, true)) {
                continue;
            }

            $action = ['type' => $type];

            if ($type === 'update_field') {
                $field = trim((string) ($fields[$i] ?? ''));
                $value = trim((string) ($values[$i] ?? ''));
                if ($field === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                    continue;
                }
                $action['field'] = $field;
                $action['value'] = $value;
            }

            if ($type === 'notify') {
                $action['message'] = trim((string) ($messages[$i] ?? 'Automation triggered'));
            }

            $actions[] = $action;
        }

        return $actions;
    }
}
