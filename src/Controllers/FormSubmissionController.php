<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Services\SubmissionService;

class FormSubmissionController extends Controller
{
    private SubmissionService $submissionService;

    public function __construct()
    {
        parent::__construct();
        $this->submissionService = new SubmissionService();
    }

    /**
     * Handle a public form submission (POST).
     */
    public function submit(string $slug): string|never
    {
        // Validate CSRF token
        if (!$this->request->validateCSRFToken()) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid CSRF token. Please refresh the page and try again.',
                ], 403);
            }
            $this->flash('errors', ['_form' => 'Invalid CSRF token. Please refresh the page and try again.']);
            $this->back();
        }

        $inputData = $this->all();
        $result = $this->submissionService->process($slug, $inputData, $this->request);

        // AJAX response
        if ($this->request->isAjax()) {
            if ($result['success']) {
                $response = [
                    'success' => true,
                    'message' => $result['message'] ?? 'Submission received.',
                ];
                if (!empty($result['redirect_url'])) {
                    $response['redirect_url'] = $result['redirect_url'];
                }
                return $this->json($response);
            }

            return $this->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        // Standard form response
        if ($result['success']) {
            // Payment redirect takes precedence
            if (!empty($result['redirect_url'])) {
                $this->redirect($result['redirect_url']);
            }

            // Custom success redirect
            $this->flash('success', $result['message'] ?? 'Thank you for your submission!');
            $this->redirect("/forms/{$slug}/success");
        }

        // Validation errors — redirect back with errors and old input
        $this->flash('errors', $result['errors'] ?? []);
        $this->flash('_old_input', $inputData);
        $this->back();
    }

    /**
     * Show the success page after a successful submission (GET).
     */
    public function success(string $slug): string
    {
        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['_form' => 'Form not found.']);
            $this->redirect('/');
            return '';
        }

        $successMessage = $form->getSetting('success_message', 'Thank you for your submission!');

        return $this->render('cms::forms/success', [
            'form' => $form,
            'message' => $successMessage,
        ]);
    }
}
