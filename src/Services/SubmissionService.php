<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormSubmission;
use ZephyrPHP\Hook\HookManager;
use ZephyrPHP\Core\Http\Request;

class SubmissionService
{
    private FormValidator $validator;

    public function __construct()
    {
        $this->validator = new FormValidator();
    }

    /**
     * Process a form submission.
     *
     * @return array{success: bool, message?: string, errors?: array, redirect_url?: string, submission?: FormSubmission}
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
            // Bot detected — return fake success
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

        // 10. Check payment
        $paymentEnabled = (bool)($settings['payment_enabled'] ?? false);
        if ($paymentEnabled) {
            return $this->handlePaymentSubmission($form, $validatedData, $request, $settings);
        }

        // 11. Save submission
        $submission = $this->saveSubmission($form, $validatedData, $request, 'completed');

        // 12. Optionally store to CMS collection
        $storageMode = $settings['storage_mode'] ?? 'submissions';
        if ($storageMode === 'collection') {
            $this->storeToCollection($form, $validatedData);
        }

        // 13. Action: after save
        $hooks->doAction('form.after_save', $form, $submission);

        // 14. Build response
        $message = $settings['success_message'] ?? 'Thank you for your submission!';
        $redirectUrl = $settings['redirect_url'] ?? null;

        return [
            'success' => true,
            'message' => $message,
            'redirect_url' => $redirectUrl,
            'submission' => $submission,
        ];
    }

    /**
     * Save a submission to fb_submissions table.
     */
    private function saveSubmission(Form $form, array $data, Request $request, string $status): FormSubmission
    {
        $submission = new FormSubmission();
        $submission->setForm($form);
        $submission->setData($data);
        $submission->setMeta([
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->header('Referer'),
        ]);
        $submission->setStatus($status);
        $submission->save();

        return $submission;
    }

    /**
     * Handle payment submission flow.
     */
    private function handlePaymentSubmission(Form $form, array $data, Request $request, array $settings): array
    {
        // Save with pending status
        $submission = $this->saveSubmission($form, $data, $request, 'pending_payment');

        // Determine amount
        $amountField = $settings['payment_amount_field'] ?? null;
        $fixedAmount = $settings['payment_fixed_amount'] ?? null;

        if ($amountField && isset($data[$amountField])) {
            $amount = (int)(floatval($data[$amountField]) * 100); // Convert to cents
        } elseif ($fixedAmount) {
            $amount = (int)$fixedAmount;
        } else {
            return ['success' => false, 'errors' => ['_form' => 'Payment amount not configured.']];
        }

        // Apply payment amount filter
        $hooks = HookManager::getInstance();
        $amount = $hooks->applyFilter('form.payment_amount', $amount, $form, $data);

        $gatewayName = $settings['payment_gateway'] ?? 'stripe';
        $currency = $settings['payment_currency'] ?? 'USD';

        // Resolve gateway
        $gateways = $hooks->applyFilter('form.payment_gateways', [
            'stripe' => new \ZephyrPHP\Cms\Services\StripeGateway(),
            'paypal' => new \ZephyrPHP\Cms\Services\PayPalGateway(),
        ]);

        $gateway = $gateways[$gatewayName] ?? null;
        if (!$gateway) {
            return ['success' => false, 'errors' => ['_form' => 'Payment gateway not available.']];
        }

        try {
            $result = $gateway->createSession($form, $submission, $amount, $currency);
            $submission->setPaymentAmount($amount);
            $submission->save();

            return [
                'success' => true,
                'redirect_url' => $result['redirect_url'],
                'submission' => $submission,
            ];
        } catch (\Exception $e) {
            $submission->setStatus('failed');
            $submission->save();
            return ['success' => false, 'errors' => ['_form' => 'Payment initialization failed.']];
        }
    }

    /**
     * Store submission data into a CMS collection table.
     */
    private function storeToCollection(Form $form, array $data): void
    {
        try {
            $collectionSlug = $form->getSetting('collection_slug', 'form_' . $form->getSlug());
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $tableName = 'cms_' . preg_replace('/[^a-z0-9_]/', '_', $collectionSlug);

            $sm = $conn->createSchemaManager();
            if (!$sm->tablesExist([$tableName])) {
                return; // Collection table doesn't exist yet
            }

            $data['created_at'] = date('Y-m-d H:i:s');
            $conn->insert($tableName, $data);
        } catch (\Exception $e) {
            // Log but don't fail the submission
        }
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
                // Remove expired entries
                $entries = array_filter($entries, fn($ts) => $ts > ($now - $window));
            }

            if (count($entries) >= $maxPerHour) {
                return true;
            }

            $entries[] = $now;
            file_put_contents($file, json_encode(array_values($entries)), LOCK_EX);

            return false;
        } catch (\Exception $e) {
            return false; // On error, allow through
        }
    }
}
