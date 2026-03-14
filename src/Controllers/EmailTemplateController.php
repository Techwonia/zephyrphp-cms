<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\EmailTemplate;
use ZephyrPHP\Cms\Services\MailService;
use ZephyrPHP\Cms\Services\PermissionService;

class EmailTemplateController extends Controller
{
    private function requireAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.settings')) {
            $this->flash('errors', ['auth' => 'Access denied.']);
            $this->redirect('/cms');
        }
    }

    /**
     * List all email templates.
     */
    public function index(): string
    {
        $this->requireAccess();

        $templates = EmailTemplate::findAll();

        return $this->render('cms::email-templates/index', [
            'templates' => $templates,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Edit an email template.
     */
    public function edit(string $id): string
    {
        $this->requireAccess();

        $template = EmailTemplate::find((int) $id);
        if (!$template) {
            $this->flash('errors', ['template' => 'Template not found.']);
            $this->redirect('/cms/email-templates');
            return '';
        }

        return $this->render('cms::email-templates/edit', [
            'template' => $template,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Update an email template.
     */
    public function update(string $id): void
    {
        $this->requireAccess();

        $template = EmailTemplate::find((int) $id);
        if (!$template) {
            $this->flash('errors', ['template' => 'Template not found.']);
            $this->redirect('/cms/email-templates');
            return;
        }

        $name = trim($this->input('name', ''));
        $subject = trim($this->input('subject', ''));
        $bodyTwig = $this->input('body_twig', '');
        $isActive = $this->boolean('is_active');

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Name is required.';
        }
        if (empty($subject)) {
            $errors['subject'] = 'Subject is required.';
        }
        if (empty($bodyTwig)) {
            $errors['body_twig'] = 'Body is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        // Sanitize subject line (prevent header injection)
        $subject = str_replace(["\r", "\n"], '', $subject);

        $template->setName($name);
        $template->setSubject($subject);
        $template->setBodyTwig($bodyTwig);
        $template->setIsActive($isActive);
        $template->save();

        $this->flash('success', 'Email template updated.');
        $this->redirect('/cms/email-templates');
    }

    /**
     * Preview a rendered email template.
     */
    public function preview(string $id): string
    {
        $this->requireAccess();

        $template = EmailTemplate::find((int) $id);
        if (!$template) {
            $this->json(['error' => 'Template not found.'], 404);
            return '';
        }

        // Render with sample variables
        $sampleVars = [
            'app_name' => $_ENV['APP_NAME'] ?? 'CMS',
            'entry_title' => 'Sample Entry Title',
            'collection_name' => 'Blog Posts',
            'entry_url' => '/cms/collections/blog/entries/1',
            'admin_url' => '/cms',
            'user_name' => 'John Doe',
            'user_email' => 'john@example.com',
            'fields' => ['Name' => 'Jane', 'Email' => 'jane@example.com', 'Message' => 'Hello!'],
        ];

        try {
            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
                'preview' => $template->getBodyTwig(),
            ]), ['autoescape' => 'html']);

            $rendered = $twig->render('preview', $sampleVars);
        } catch (\Exception $e) {
            $rendered = '<p style="color:red;">Template error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Return as a standalone HTML page
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Preview: ' . htmlspecialchars($template->getName(), ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{font-family:Arial,sans-serif;max-width:600px;margin:40px auto;padding:20px;color:#333;}</style></head>'
            . '<body>' . $rendered . '</body></html>';
    }

    /**
     * Send a test email.
     */
    public function testSend(string $id): void
    {
        $this->requireAccess();

        $template = EmailTemplate::find((int) $id);
        if (!$template) {
            $this->json(['error' => 'Template not found.'], 404);
            return;
        }

        $toEmail = Auth::user()?->getEmail() ?? '';
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'No valid email address found for your account.'], 400);
            return;
        }

        $sampleVars = [
            'app_name' => $_ENV['APP_NAME'] ?? 'CMS',
            'entry_title' => 'Test Entry',
            'collection_name' => 'Test Collection',
            'entry_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . '/cms',
            'admin_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . '/cms',
            'user_name' => Auth::user()?->getName() ?? 'Test User',
            'user_email' => $toEmail,
        ];

        $sent = MailService::getInstance()->sendTemplate($template->getSlug(), $toEmail, $sampleVars);

        if ($sent) {
            $this->json(['success' => true, 'message' => "Test email sent to {$toEmail}."]);
        } else {
            $this->json(['error' => 'Failed to send test email. Check mail configuration.'], 500);
        }
    }
}
