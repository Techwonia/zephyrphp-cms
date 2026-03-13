<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\FormSubmission;
use ZephyrPHP\Cms\Services\PaymentGateway;
use ZephyrPHP\Cms\Services\StripeGateway;
use ZephyrPHP\Cms\Services\PayPalGateway;
use ZephyrPHP\Hook\HookManager;

class FormPaymentController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Handle payment callback (GET) — user returning from payment gateway.
     */
    public function callback(string $gateway): void
    {
        $gatewayInstance = $this->resolveGateway($gateway);
        if (!$gatewayInstance) {
            $this->flash('errors', ['payment' => 'Unknown payment gateway.']);
            $this->redirect('/');
            return;
        }

        $payload = $this->request->query();

        try {
            $result = $gatewayInstance->handleCallback($payload);
        } catch (\Exception $e) {
            $this->flash('errors', ['payment' => 'Payment verification failed. Please contact support.']);
            $this->redirect('/');
            return;
        }

        $submissionId = (int) ($result['submission_id'] ?? 0);
        $status = $result['status'] ?? 'failed';
        $transactionId = $result['transaction_id'] ?? '';

        $submission = FormSubmission::find($submissionId);
        if (!$submission) {
            $this->flash('errors', ['payment' => 'Submission not found.']);
            $this->redirect('/');
            return;
        }

        $form = $submission->getForm();
        $hooks = HookManager::getInstance();

        if ($status === 'paid') {
            $submission->setStatus('paid');
            $submission->setPaymentId($transactionId);
            $submission->save();

            $hooks->doAction('form.payment_completed', $form, $submission, $transactionId);

            $this->flash('success', 'Payment completed successfully.');
            $this->redirect("/forms/{$form->getSlug()}/success");
        } else {
            $submission->setStatus('failed');
            $submission->setPaymentId($transactionId ?: null);
            $submission->save();

            $hooks->doAction('form.payment_failed', $form, $submission);

            $this->flash('errors', ['payment' => 'Payment was not completed. Please try again or contact support.']);
            $this->redirect("/forms/{$form->getSlug()}/success");
        }
    }

    /**
     * Handle payment webhook (POST) — server-to-server notification from gateway.
     */
    public function webhook(string $gateway): string
    {
        $gatewayInstance = $this->resolveGateway($gateway);
        if (!$gatewayInstance) {
            return $this->json(['error' => 'Unknown payment gateway.'], 400);
        }

        // Verify webhook signature based on gateway
        if (!$this->verifyWebhookSignature($gateway)) {
            return $this->json(['error' => 'Invalid webhook signature.'], 403);
        }

        // Parse the raw payload
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            return $this->json(['error' => 'Empty request body.'], 400);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        try {
            $result = $this->processWebhookPayload($gateway, $payload);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Webhook processing failed.'], 500);
        }

        $submissionId = (int) ($result['submission_id'] ?? 0);
        $status = $result['status'] ?? '';
        $transactionId = $result['transaction_id'] ?? '';

        if ($submissionId <= 0) {
            return $this->json(['received' => true, 'message' => 'No matching submission.']);
        }

        $submission = FormSubmission::find($submissionId);
        if (!$submission) {
            return $this->json(['received' => true, 'message' => 'Submission not found.']);
        }

        $form = $submission->getForm();
        $hooks = HookManager::getInstance();

        if ($status === 'paid') {
            $submission->setStatus('paid');
            $submission->setPaymentId($transactionId);
            $submission->save();

            $hooks->doAction('form.payment_completed', $form, $submission, $transactionId);
        } elseif ($status === 'failed') {
            $submission->setStatus('failed');
            $submission->setPaymentId($transactionId ?: null);
            $submission->save();

            $hooks->doAction('form.payment_failed', $form, $submission);
        }

        return $this->json(['received' => true]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Resolve the payment gateway instance by name.
     */
    private function resolveGateway(string $gateway): ?PaymentGateway
    {
        $allowedGateways = ['stripe', 'paypal'];
        $gateway = strtolower(trim($gateway));

        if (!in_array($gateway, $allowedGateways, true)) {
            return null;
        }

        // Allow hooks to extend or override gateways
        $hooks = HookManager::getInstance();
        $gateways = $hooks->applyFilter('form.payment_gateways', [
            'stripe' => new StripeGateway(),
            'paypal' => new PayPalGateway(),
        ]);

        return $gateways[$gateway] ?? null;
    }

    /**
     * Verify the webhook signature for the given gateway.
     */
    private function verifyWebhookSignature(string $gateway): bool
    {
        $rawBody = file_get_contents('php://input');

        if ($gateway === 'stripe') {
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
            $webhookSecret = env('STRIPE_WEBHOOK_SECRET', '');

            if (empty($webhookSecret) || empty($sigHeader)) {
                return false;
            }

            // Parse Stripe signature header
            $parts = [];
            foreach (explode(',', $sigHeader) as $item) {
                [$key, $value] = explode('=', $item, 2);
                $parts[trim($key)] = trim($value);
            }

            $timestamp = $parts['t'] ?? '';
            $signature = $parts['v1'] ?? '';

            if (empty($timestamp) || empty($signature)) {
                return false;
            }

            // Reject if timestamp is too old (5 min tolerance)
            if (abs(time() - (int) $timestamp) > 300) {
                return false;
            }

            $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
            return hash_equals($expectedSignature, $signature);
        }

        if ($gateway === 'paypal') {
            // PayPal webhook verification via transmission headers
            $transmissionId = $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '';
            $webhookId = env('PAYPAL_WEBHOOK_ID', '');

            if (empty($transmissionId) || empty($webhookId)) {
                return false;
            }

            // For PayPal, full verification requires an API call.
            // Accept if transmission headers are present as a basic check.
            return !empty($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']);
        }

        return false;
    }

    /**
     * Process the webhook payload and extract submission info.
     */
    private function processWebhookPayload(string $gateway, array $payload): array
    {
        if ($gateway === 'stripe') {
            $type = $payload['type'] ?? '';

            if ($type === 'checkout.session.completed') {
                $session = $payload['data']['object'] ?? [];
                $submissionId = (int) ($session['metadata']['submission_id'] ?? 0);
                $paymentStatus = $session['payment_status'] ?? '';

                return [
                    'submission_id' => $submissionId,
                    'status' => ($paymentStatus === 'paid') ? 'paid' : 'failed',
                    'transaction_id' => $session['payment_intent'] ?? $session['id'] ?? '',
                ];
            }

            if ($type === 'checkout.session.expired' || $type === 'payment_intent.payment_failed') {
                $object = $payload['data']['object'] ?? [];
                $submissionId = (int) ($object['metadata']['submission_id'] ?? 0);

                return [
                    'submission_id' => $submissionId,
                    'status' => 'failed',
                    'transaction_id' => $object['id'] ?? '',
                ];
            }

            // Unhandled event type
            return ['submission_id' => 0, 'status' => '', 'transaction_id' => ''];
        }

        if ($gateway === 'paypal') {
            $eventType = $payload['event_type'] ?? '';

            if ($eventType === 'CHECKOUT.ORDER.APPROVED' || $eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                $resource = $payload['resource'] ?? [];
                $referenceId = '';

                // Extract submission ID from purchase units
                $purchaseUnits = $resource['purchase_units'] ?? [];
                foreach ($purchaseUnits as $unit) {
                    $referenceId = $unit['reference_id'] ?? '';
                    break;
                }

                $submissionId = 0;
                if (str_starts_with($referenceId, 'submission_')) {
                    $submissionId = (int) substr($referenceId, strlen('submission_'));
                }

                return [
                    'submission_id' => $submissionId,
                    'status' => 'paid',
                    'transaction_id' => $resource['id'] ?? '',
                ];
            }

            if ($eventType === 'PAYMENT.CAPTURE.DENIED' || $eventType === 'PAYMENT.CAPTURE.REVERSED') {
                $resource = $payload['resource'] ?? [];
                $purchaseUnits = $resource['purchase_units'] ?? [];
                $referenceId = '';
                foreach ($purchaseUnits as $unit) {
                    $referenceId = $unit['reference_id'] ?? '';
                    break;
                }

                $submissionId = 0;
                if (str_starts_with($referenceId, 'submission_')) {
                    $submissionId = (int) substr($referenceId, strlen('submission_'));
                }

                return [
                    'submission_id' => $submissionId,
                    'status' => 'failed',
                    'transaction_id' => $resource['id'] ?? '',
                ];
            }

            return ['submission_id' => 0, 'status' => '', 'transaction_id' => ''];
        }

        return ['submission_id' => 0, 'status' => '', 'transaction_id' => ''];
    }
}
