<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Hook\HookManager;
use ZephyrPHP\Core\Http\Request;

class SubmissionService
{
    private FormValidator $validator;
    private SchemaManager $schema;
    private FormCollectionService $collectionService;

    public function __construct()
    {
        $this->validator = new FormValidator();
        $this->schema = new SchemaManager();
        $this->collectionService = new FormCollectionService();
    }

    /**
     * Process a form submission.
     *
     * @return array{success: bool, message?: string, errors?: array, redirect_url?: string}
     */
    public function process(string $slug, array $inputData, Request $request): array
    {
        // 1. Load form
        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form || !$form->isActive()) {
            return ['success' => false, 'errors' => ['_form' => 'Form not found or inactive.']];
        }

        $settings = $form->getSettings();

        // 2. Honeypot check
        if (($settings['honeypot_enabled'] ?? true) && !empty($inputData['_hp_field'])) {
            return ['success' => true, 'message' => $settings['success_message'] ?? 'Thank you!'];
        }

        // 3. Rate limiting
        $rateLimit = (int)($settings['rate_limit_per_ip'] ?? 5);
        $ip = $request->ip();
        if ($rateLimit > 0 && $this->isRateLimited($form, $ip, $rateLimit)) {
            return ['success' => false, 'errors' => ['_form' => 'Too many submissions. Please try again later.']];
        }

        // 4. Build validation rules from fields
        $fields = $form->getSubmittableFields();
        $rules = $this->validator->buildRules($fields);

        // 5. Filter: allow hooks to modify validation rules
        $hooks = HookManager::getInstance();
        $rules = $hooks->applyFilter('form.validation_rules', $rules, $form);

        // 6. Extract only form field data (ignore CSRF, honeypot, etc.)
        $fieldSlugs = array_map(fn($f) => $f->getSlug(), $fields);
        $data = array_intersect_key($inputData, array_flip($fieldSlugs));

        // 7. Validate
        $validator = $this->validator->validate($data, $rules);
        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $validatedData = $validator->validated();

        // 8. Filter: transform data (calculations, formatting)
        $validatedData = $hooks->applyFilter('form.transform_data', $validatedData, $form);

        // 9. Action: before save
        $hooks->doAction('form.before_save', $form, $validatedData);

        // 10. Ensure collection exists and is synced
        $collection = $this->collectionService->sync($form);

        // 11. Save to collection table
        $entryData = $validatedData;

        // Convert array values (checkboxes) to comma-separated strings
        foreach ($entryData as $key => $value) {
            if (is_array($value)) {
                $entryData[$key] = implode(', ', $value);
            }
        }

        // Add meta columns
        $entryData['_ip'] = $request->ip();
        $entryData['_user_agent'] = substr($request->userAgent() ?? '', 0, 500);
        $entryData['_status'] = 'completed';

        $entryId = $this->schema->insertEntry($collection->getTableName(), $entryData);

        // 12. Action: after save
        $hooks->doAction('form.after_save', $form, $entryId, $validatedData);

        // 13. Build response
        $message = $settings['success_message'] ?? 'Thank you for your submission!';
        $redirectUrl = $settings['redirect_url'] ?? null;

        return [
            'success' => true,
            'message' => $message,
            'redirect_url' => $redirectUrl,
            'entry_id' => $entryId,
        ];
    }

    /**
     * Simple file-based rate limiter (no Redis needed for shared hosting).
     */
    private function isRateLimited(Form $form, string $ip, int $maxPerHour): bool
    {
        try {
            $storagePath = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : sys_get_temp_dir());
            $rateLimitDir = $storagePath . '/form-rate-limits';

            if (!is_dir($rateLimitDir)) {
                mkdir($rateLimitDir, 0755, true);
            }

            $key = md5($form->getSlug() . ':' . $ip);
            $file = $rateLimitDir . '/' . $key . '.json';

            $now = time();
            $window = 3600; // 1 hour
            $entries = [];

            if (file_exists($file)) {
                $entries = json_decode(file_get_contents($file), true) ?: [];
                $entries = array_filter($entries, fn($ts) => $ts > ($now - $window));
            }

            if (count($entries) >= $maxPerHour) {
                return true;
            }

            $entries[] = $now;
            file_put_contents($file, json_encode(array_values($entries)), LOCK_EX);

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
