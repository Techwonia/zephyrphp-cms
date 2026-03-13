<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormSubmission;

class PayPalGateway implements PaymentGateway
{
    public function getName(): string
    {
        return 'paypal';
    }

    public function createSession(Form $form, FormSubmission $submission, int $amountCents, string $currency): array
    {
        $clientId = env('PAYPAL_CLIENT_ID', '');
        $secret = env('PAYPAL_SECRET', '');
        $sandbox = env('PAYPAL_SANDBOX', true);

        if (!$clientId || !$secret) {
            throw new \RuntimeException('PayPal credentials not configured.');
        }

        $apiBase = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $accessToken = $this->getAccessToken($apiBase, $clientId, $secret);
        $amount = number_format($amountCents / 100, 2, '.', '');

        $baseUrl = $this->getBaseUrl();
        $returnUrl = $baseUrl . '/forms/payment/callback/paypal?submission_id=' . $submission->getId();
        $cancelUrl = $baseUrl . '/forms/' . $form->getSlug() . '/submit?cancelled=1';

        $orderData = json_encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'submission_' . $submission->getId(),
                'description' => $form->getName(),
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => $amount,
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ]);

        $ch = curl_init($apiBase . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $orderData,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 || !$response) {
            throw new \RuntimeException('PayPal order creation failed.');
        }

        $data = json_decode($response, true);
        $approveUrl = '';
        foreach (($data['links'] ?? []) as $link) {
            if ($link['rel'] === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }

        if (!$approveUrl) {
            throw new \RuntimeException('PayPal approval URL not found.');
        }

        return [
            'redirect_url' => $approveUrl,
            'session_id' => $data['id'] ?? '',
        ];
    }

    public function handleCallback(array $payload): array
    {
        $orderId = $payload['token'] ?? '';
        $submissionId = (int)($payload['submission_id'] ?? 0);

        if (!$orderId || !$submissionId) {
            throw new \RuntimeException('Missing order token or submission_id.');
        }

        $clientId = env('PAYPAL_CLIENT_ID', '');
        $secret = env('PAYPAL_SECRET', '');
        $sandbox = env('PAYPAL_SANDBOX', true);
        $apiBase = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        $accessToken = $this->getAccessToken($apiBase, $clientId, $secret);

        // Capture the order
        $ch = curl_init($apiBase . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        $status = ($data['status'] ?? '') === 'COMPLETED' ? 'paid' : 'failed';
        $captureId = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;

        return [
            'submission_id' => $submissionId,
            'status' => $status,
            'transaction_id' => $captureId,
        ];
    }

    private function getAccessToken(string $apiBase, string $clientId, string $secret): string
    {
        $ch = curl_init($apiBase . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => $clientId . ':' . $secret,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('PayPal authentication failed.');
        }

        return $data['access_token'];
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
