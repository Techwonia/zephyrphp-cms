<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class MailSettingsController extends Controller
{
    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $settings = [
            'MAIL_DRIVER' => env('MAIL_DRIVER', 'mail'),
            'MAIL_HOST' => env('MAIL_HOST', ''),
            'MAIL_PORT' => env('MAIL_PORT', '587'),
            'MAIL_USERNAME' => env('MAIL_USERNAME', ''),
            'MAIL_PASSWORD' => env('MAIL_PASSWORD', '') ? '••••••••' : '',
            'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION', 'tls'),
            'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS', ''),
            'MAIL_FROM_NAME' => env('MAIL_FROM_NAME', env('APP_NAME', 'ZephyrPHP')),
        ];

        return $this->render('cms::settings/mail', [
            'settings' => $settings,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [
            'MAIL_DRIVER' => trim($this->input('MAIL_DRIVER', 'mail')),
            'MAIL_HOST' => trim($this->input('MAIL_HOST', '')),
            'MAIL_PORT' => trim($this->input('MAIL_PORT', '587')),
            'MAIL_USERNAME' => trim($this->input('MAIL_USERNAME', '')),
            'MAIL_ENCRYPTION' => trim($this->input('MAIL_ENCRYPTION', 'tls')),
            'MAIL_FROM_ADDRESS' => trim($this->input('MAIL_FROM_ADDRESS', '')),
            'MAIL_FROM_NAME' => trim($this->input('MAIL_FROM_NAME', '')),
        ];

        // Only update password if provided (not the masked value)
        $password = $this->input('MAIL_PASSWORD', '');
        if ($password !== '' && $password !== '••••••••') {
            $settings['MAIL_PASSWORD'] = $password;
        }

        $envPath = $this->getEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            $this->flash('errors', ['.env file not found or not writable.']);
            $this->back();
            return;
        }

        $this->updateEnvFile($envPath, $settings);

        foreach ($settings as $key => $value) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        $this->flash('success', 'Mail settings updated successfully.');
        $this->redirect('/cms/settings/mail');
    }

    public function testSend(): void
    {
        $this->requirePermission('settings.edit');

        $to = trim($this->input('test_email', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash('errors', ['Please provide a valid email address.']);
            $this->redirect('/cms/settings/mail');
            return;
        }

        try {
            $driver = env('MAIL_DRIVER', 'mail');
            $fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@example.com');
            $fromName = env('MAIL_FROM_NAME', 'ZephyrPHP');
            $subject = 'ZephyrPHP Test Email';
            $body = '<h2>Test Email</h2><p>This is a test email from your ZephyrPHP application.</p><p>If you received this, your mail configuration is working correctly.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>';

            if ($driver === 'smtp') {
                $this->sendSmtp($to, $fromAddress, $fromName, $subject, $body);
            } else {
                $headers = "From: {$fromName} <{$fromAddress}>\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "MIME-Version: 1.0\r\n";

                $result = @mail($to, $subject, $body, $headers);
                if (!$result) {
                    throw new \RuntimeException('PHP mail() returned false. Check server mail configuration.');
                }
            }

            $this->flash('success', "Test email sent to {$to}.");
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to send test email: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/settings/mail');
    }

    private function sendSmtp(string $to, string $from, string $fromName, string $subject, string $body): void
    {
        $host = env('MAIL_HOST', '');
        $port = (int) env('MAIL_PORT', 587);
        $username = env('MAIL_USERNAME', '');
        $password = env('MAIL_PASSWORD', '');
        $encryption = env('MAIL_ENCRYPTION', 'tls');

        if ($host === '') {
            throw new \RuntimeException('SMTP host is not configured.');
        }

        $socket = $encryption === 'ssl'
            ? @fsockopen("ssl://{$host}", $port, $errno, $errstr, 10)
            : @fsockopen($host, $port, $errno, $errstr, 10);

        if (!$socket) {
            throw new \RuntimeException("Could not connect to SMTP server: {$errstr} ({$errno})");
        }

        $this->smtpRead($socket);
        $this->smtpCommand($socket, "EHLO " . gethostname());

        if ($encryption === 'tls') {
            $this->smtpCommand($socket, "STARTTLS");
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
            $this->smtpCommand($socket, "EHLO " . gethostname());
        }

        if ($username !== '') {
            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($username));
            $this->smtpCommand($socket, base64_encode($password));
        }

        $this->smtpCommand($socket, "MAIL FROM:<{$from}>");
        $this->smtpCommand($socket, "RCPT TO:<{$to}>");
        $this->smtpCommand($socket, "DATA");

        $message = "From: {$fromName} <{$from}>\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: {$subject}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "\r\n{$body}\r\n.";

        $this->smtpCommand($socket, $message);
        $this->smtpCommand($socket, "QUIT");

        fclose($socket);
    }

    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    private function getEnvPath(): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $envPath = $basePath . '/.env';
        if (file_exists($envPath)) {
            return $envPath;
        }
        $parentEnv = dirname($basePath) . '/.env';
        return file_exists($parentEnv) ? $parentEnv : null;
    }

    private function updateEnvFile(string $envPath, array $settings): void
    {
        $content = file_get_contents($envPath);
        foreach ($settings as $key => $value) {
            $escaped = $this->escapeEnvValue($value);
            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $content);
            } else {
                $content = rtrim($content) . "\n{$key}={$escaped}\n";
            }
        }
        file_put_contents($envPath, $content, LOCK_EX);
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
