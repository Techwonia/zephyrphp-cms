<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormSubmission;

interface PaymentGateway
{
    /**
     * Create a payment session and return a redirect URL.
     *
     * @return array{redirect_url: string, session_id: string}
     */
    public function createSession(Form $form, FormSubmission $submission, int $amountCents, string $currency): array;

    /**
     * Handle the payment callback/webhook.
     *
     * @return array{submission_id: int, status: string, transaction_id: string}
     */
    public function handleCallback(array $payload): array;

    public function getName(): string;
}
