<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Collection;
use ZephyrPHP\Cms\Services\SchemaManager;
use ZephyrPHP\Cms\Services\NotificationService;
use ZephyrPHP\Security\Csrf;

class PublicSubmitController extends Controller
{
    private SchemaManager $schema;

    public function __construct()
    {
        parent::__construct();
        $this->schema = new SchemaManager();
    }

    public function submit(string $slug): void
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        $collection = Collection::findOneBy(['slug' => $slug]);
        if (!$collection || !$collection->isSubmittable()) {
            $this->json(['error' => 'Not found or submissions not enabled.'], 404);
            return;
        }

        $settings = $collection->getSubmitSettings() ?? [];

        // CSRF validation
        $csrfToken = $this->input('csrf_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!Csrf::validate($csrfToken)) {
            $this->handleError($collection, ['csrf' => 'Invalid or expired security token. Please try again.']);
            return;
        }

        // Honeypot check (silent reject)
        if (!empty($settings['honeypot_enabled'])) {
            $honeypot = $this->input('_hp_email');
            if (!empty($honeypot)) {
                // Bot detected — pretend success
                $this->handleSuccess($collection, $settings);
                return;
            }
        }

        // Rate limiting
        $rateLimit = (int) ($settings['rate_limit_per_ip'] ?? 0);
        if ($rateLimit > 0) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $rateLimitError = $this->checkRateLimit($collection->getSlug(), $ip, $rateLimit);
            if ($rateLimitError) {
                $this->handleError($collection, ['rate_limit' => $rateLimitError]);
                return;
            }
        }

        $fields = $collection->getFields()->toArray();
        $tableName = $collection->getTableName();

        if (!$this->schema->tableExists($tableName)) {
            $this->json(['error' => 'Collection table not found.'], 500);
            return;
        }

        // Build and validate entry data
        $data = $this->buildSubmitData($fields);
        $errors = $this->validateSubmitData($fields, $data);

        if (!empty($errors)) {
            $this->handleError($collection, $errors, $data);
            return;
        }

        // Sanitize data
        $data = $this->sanitizeData($fields, $data);

        // Auto-generate slug if collection has slug enabled
        if ($collection->hasSlug()) {
            $sourceField = $collection->getSlugSourceField();
            $sourceValue = $data[$sourceField] ?? $data['name'] ?? $data['title'] ?? '';
            $data['slug'] = $this->generateUniqueSlug($tableName, $sourceValue);
        }

        // Set status for publishable collections
        if ($collection->isPublishable()) {
            $data['status'] = 'draft'; // Public submissions default to draft
        }

        // Insert entry
        $entryId = $this->schema->insertEntry($tableName, $data, $collection->isUuid());

        // Invalidate collection cache
        cms_invalidate_cache($collection->getSlug());

        // Record rate limit hit
        if ($rateLimit > 0) {
            $this->recordRateLimit($collection->getSlug(), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }

        // Email notification (legacy)
        if (!empty($settings['email_notify']) && !empty($settings['email_to'])) {
            $this->sendNotification($collection, $data, $settings['email_to']);
        }

        // In-app + email notification via NotificationService
        $entryLabel = $data['title'] ?? $data['name'] ?? "#{$entryId}";
        NotificationService::notifyAdmins(
            'form_submitted',
            "New submission: {$collection->getName()}",
            "A new entry \"{$entryLabel}\" was submitted to {$collection->getName()}.",
            "/cms/collections/{$collection->getSlug()}/entries/{$entryId}",
            ['collection' => $collection->getSlug(), 'entry_id' => $entryId],
            [
                'entry_title' => $entryLabel,
                'collection_name' => $collection->getName(),
                'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . "/cms/collections/{$collection->getSlug()}/entries/{$entryId}",
            ]
        );

        $this->handleSuccess($collection, $settings);
    }

    private function buildSubmitData(array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            // Skip relation fields, file/image fields, and JSON fields for public submissions
            if (in_array($field->getType(), ['relation', 'file', 'image', 'json', 'richtext'])) {
                continue;
            }

            $value = $this->input($field->getSlug());
            $data[$field->getSlug()] = match ($field->getType()) {
                'boolean' => $this->boolean($field->getSlug()) ? 1 : 0,
                'number' => $value !== null && $value !== '' ? (int) $value : null,
                'decimal' => $value !== null && $value !== '' ? (float) $value : null,
                default => $value !== '' ? $value : null,
            };
        }
        return $data;
    }

    private function validateSubmitData(array $fields, array $data): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (in_array($field->getType(), ['relation', 'file', 'image', 'json', 'richtext'])) {
                continue;
            }

            $value = $data[$field->getSlug()] ?? null;

            if ($field->isRequired() && ($value === null || $value === '')) {
                $errors[$field->getSlug()] = "{$field->getName()} is required.";
                continue;
            }

            if ($value !== null && $value !== '') {
                switch ($field->getType()) {
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field->getSlug()] = "{$field->getName()} must be a valid email address.";
                        }
                        break;
                    case 'url':
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field->getSlug()] = "{$field->getName()} must be a valid URL.";
                        }
                        break;
                    case 'number':
                        if (!is_numeric($value)) {
                            $errors[$field->getSlug()] = "{$field->getName()} must be a number.";
                        }
                        break;
                }
            }
        }
        return $errors;
    }

    private function sanitizeData(array $fields, array $data): array
    {
        foreach ($fields as $field) {
            $slug = $field->getSlug();
            if (!isset($data[$slug]) || $data[$slug] === null) {
                continue;
            }

            // Strip HTML from all text inputs (public submissions should never contain HTML)
            if (in_array($field->getType(), ['text', 'textarea', 'email', 'url'])) {
                $data[$slug] = strip_tags((string) $data[$slug]);
            }

            // Enforce max length for text fields
            if (in_array($field->getType(), ['text', 'email', 'url']) && is_string($data[$slug])) {
                $data[$slug] = mb_substr($data[$slug], 0, 500);
            }
            if ($field->getType() === 'textarea' && is_string($data[$slug])) {
                $data[$slug] = mb_substr($data[$slug], 0, 5000);
            }
        }
        return $data;
    }

    private function handleSuccess(Collection $collection, array $settings): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isAjax || $this->wantsJson()) {
            $this->json([
                'success' => true,
                'message' => $settings['success_message'] ?? 'Thank you for your submission.',
            ]);
            return;
        }

        if (!empty($settings['redirect_url'])) {
            $this->redirect($settings['redirect_url']);
        }

        $this->flash('success', $settings['success_message'] ?? 'Thank you for your submission.');
        $this->back();
    }

    private function handleError(Collection $collection, array $errors, array $oldInput = []): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isAjax || $this->wantsJson()) {
            $this->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $this->flash('errors', $errors);
        if (!empty($oldInput)) {
            $this->flash('_old_input', $oldInput);
        }
        $this->back();
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    private function checkRateLimit(string $slug, string $ip, int $limit): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $key = "rate_limit_{$slug}_{$ip}";
        $data = $_SESSION[$key] ?? null;

        if ($data) {
            $windowStart = $data['window_start'] ?? 0;
            $count = $data['count'] ?? 0;

            // Reset if window expired (1 hour)
            if (time() - $windowStart > 3600) {
                unset($_SESSION[$key]);
                return null;
            }

            if ($count >= $limit) {
                return 'Too many submissions. Please try again later.';
            }
        }

        return null;
    }

    private function recordRateLimit(string $slug, string $ip): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $key = "rate_limit_{$slug}_{$ip}";
        $data = $_SESSION[$key] ?? null;

        if ($data && (time() - ($data['window_start'] ?? 0)) <= 3600) {
            $_SESSION[$key]['count'] = ($data['count'] ?? 0) + 1;
        } else {
            $_SESSION[$key] = ['window_start' => time(), 'count' => 1];
        }
    }

    private function sendNotification(Collection $collection, array $data, string $to): void
    {
        try {
            $subject = "New submission: {$collection->getName()}";
            $body = "A new entry was submitted to \"{$collection->getName()}\".\n\n";

            foreach ($data as $key => $value) {
                if ($value !== null && $value !== '') {
                    $body .= ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                }
            }

            $body .= "\n---\nSubmitted at: " . date('Y-m-d H:i:s');

            $headers = "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            mail($to, $subject, $body, $headers);
        } catch (\Exception $e) {
            // Email failure should not break submission
        }
    }

    private function generateUniqueSlug(string $tableName, string $source): string
    {
        $base = strtolower(trim($source));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');
        if (empty($base)) {
            $base = 'entry';
        }

        $slug = $base;
        $counter = 2;
        $conn = $this->schema->getConnection();

        while (true) {
            $count = (int) $conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($tableName)
                ->where('slug = :slug')
                ->setParameter('slug', $slug)
                ->executeQuery()
                ->fetchOne();

            if ($count === 0) {
                break;
            }

            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
