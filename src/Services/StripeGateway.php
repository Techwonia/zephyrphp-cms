<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormSubmission;

class StripeGateway implements PaymentGateway
{
    public function getName(): string
    {
        return 'stripe';
    }

    public function createSession(Form $form, FormSubmission $submission, int $amountCents, string $currency): array
    {
        $secretKey = $this->getSecretKey();
        if (!$secretKey) {
            throw new \RuntimeException('Stripe secret key not configured.');
        }

        $baseUrl = $this->getBaseUrl();
        $successUrl = $baseUrl . '/forms/payment/callback/stripe?session_id={CHECKOUT_SESSION_ID}&submission_id=' . $submission->getId();
        $cancelUrl = $baseUrl . '/forms/' . $form->getSlug() . '/submit?cancelled=1';

        $params = http_build_query([
            'payment_method_types[]' => 'card',
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][product_data][name]' => $form->getName(),
            'line_items[0][price_data][unit_amount]' => $amountCents,
            'line_items[0][quantity]' => 1,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[submission_id]' => (string)$submission->getId(),
            'metadata[form_slug]' => $form->getSlug(),
        ]);

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('Stripe API error: ' . ($response ?: 'No response'));
        }

        $data = json_decode($response, true);
        if (empty($data['url']) || empty($data['id'])) {
            throw new \RuntimeException('Invalid Stripe response.');
        }

        return [
            'redirect_url' => $data['url'],
            'session_id' => $data['id'],
        ];
    }

    public function handleCallback(array $payload): array
    {
        $sessionId = $payload['session_id'] ?? '';
        $submissionId = (int)($payload['submission_id'] ?? 0);

        if (!$sessionId || !$submissionId) {
            throw new \RuntimeException('Missing session_id or submission_id.');
        }

        // Verify session with Stripe
        $secretKey = $this->getSecretKey();
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        $paymentStatus = $data['payment_status'] ?? '';

        return [
            'submission_id' => $submissionId,
            'status' => ($paymentStatus === 'paid') ? 'paid' : 'failed',
            'transaction_id' => $data['payment_intent'] ?? $sessionId,
        ];
    }

    private function getSecretKey(): string
    {
        return env('STRIPE_SECRET_KEY', '');
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
