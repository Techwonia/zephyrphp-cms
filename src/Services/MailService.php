<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\EmailTemplate;

class MailService
{
    private string $transport;
    private string $fromEmail;
    private string $fromName;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $smtpEncryption;

    private static ?self $instance = null;

    public function __construct()
    {
        $this->transport = $_ENV['MAIL_TRANSPORT'] ?? 'php_mail';
        $this->fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? $_ENV['APP_NAME'] ?? 'CMS';
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? 'localhost';
        $this->smtpPort = (int) ($_ENV['SMTP_PORT'] ?? 587);
        $this->smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
        $this->smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
        $this->smtpEncryption = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send an email using a stored template.
     */
    public function sendTemplate(string $templateSlug, string $toEmail, array $variables = [], ?string $toName = null): bool
    {
        $template = EmailTemplate::findOneBy(['slug' => $templateSlug]);
        if (!$template || !$template->isActive()) {
            return false;
        }

        $subject = $this->renderTwigString($template->getSubject(), $variables);
        $body = $this->renderTwigString($template->getBodyTwig(), $variables);

        return $this->send($toEmail, $subject, $body, $toName);
    }

    /**
     * Send a raw email.
     */
    public function send(string $toEmail, string $subject, string $htmlBody, ?string $toName = null): bool
    {
        // Validate email
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Sanitize subject to prevent header injection
        $subject = str_replace(["\r", "\n"], '', $subject);

        if ($this->transport === 'smtp') {
            return $this->sendViaSMTP($toEmail, $subject, $htmlBody, $toName);
        }

        return $this->sendViaPhpMail($toEmail, $subject, $htmlBody, $toName);
    }

    /**
     * Send via PHP's built-in mail() function.
     */
    private function sendViaPhpMail(string $toEmail, string $subject, string $htmlBody, ?string $toName): bool
    {
        $boundary = bin2hex(random_bytes(16));
        $to = $toName ? $this->encodeHeader($toName) . " <{$toEmail}>" : $toEmail;

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';
        $headers[] = 'Reply-To: ' . $this->fromEmail;
        $headers[] = 'X-Mailer: ZephyrPHP-CMS';
        $headers[] = 'Date: ' . date('r');

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($plainText) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * Send via raw SMTP using fsockopen with STARTTLS support.
     */
    private function sendViaSMTP(string $toEmail, string $subject, string $htmlBody, ?string $toName): bool
    {
        $socket = null;

        try {
            $prefix = $this->smtpEncryption === 'ssl' ? 'ssl://' : '';
            $socket = @fsockopen(
                $prefix . $this->smtpHost,
                $this->smtpPort,
                $errno,
                $errstr,
                10
            );

            if (!$socket) {
                return false;
            }

            stream_set_timeout($socket, 30);

            // Read greeting
            if (!$this->smtpReadOk($socket, 220)) {
                fclose($socket);
                return false;
            }

            $hostname = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';

            // EHLO
            $this->smtpWrite($socket, "EHLO {$hostname}");
            if (!$this->smtpReadOk($socket, 250)) {
                fclose($socket);
                return false;
            }

            // STARTTLS if needed
            if ($this->smtpEncryption === 'tls') {
                $this->smtpWrite($socket, "STARTTLS");
                if (!$this->smtpReadOk($socket, 220)) {
                    fclose($socket);
                    return false;
                }

                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }

                if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                    fclose($socket);
                    return false;
                }

                // Re-EHLO after STARTTLS
                $this->smtpWrite($socket, "EHLO {$hostname}");
                if (!$this->smtpReadOk($socket, 250)) {
                    fclose($socket);
                    return false;
                }
            }

            // AUTH LOGIN
            if (!empty($this->smtpUsername)) {
                $this->smtpWrite($socket, "AUTH LOGIN");
                if (!$this->smtpReadOk($socket, 334)) {
                    fclose($socket);
                    return false;
                }

                $this->smtpWrite($socket, base64_encode($this->smtpUsername));
                if (!$this->smtpReadOk($socket, 334)) {
                    fclose($socket);
                    return false;
                }

                $this->smtpWrite($socket, base64_encode($this->smtpPassword));
                if (!$this->smtpReadOk($socket, 235)) {
                    fclose($socket);
                    return false;
                }
            }

            // MAIL FROM
            $this->smtpWrite($socket, "MAIL FROM:<{$this->fromEmail}>");
            if (!$this->smtpReadOk($socket, 250)) {
                fclose($socket);
                return false;
            }

            // RCPT TO
            $this->smtpWrite($socket, "RCPT TO:<{$toEmail}>");
            if (!$this->smtpReadOk($socket, 250)) {
                fclose($socket);
                return false;
            }

            // DATA
            $this->smtpWrite($socket, "DATA");
            if (!$this->smtpReadOk($socket, 354)) {
                fclose($socket);
                return false;
            }

            // Build email message
            $message = $this->buildSmtpMessage($toEmail, $subject, $htmlBody, $toName);
            fwrite($socket, $message);

            // End data
            $this->smtpWrite($socket, "\r\n.");
            if (!$this->smtpReadOk($socket, 250)) {
                fclose($socket);
                return false;
            }

            // QUIT
            $this->smtpWrite($socket, "QUIT");
            fclose($socket);

            return true;
        } catch (\Exception $e) {
            if ($socket && is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    /**
     * Build the full email message for SMTP DATA.
     */
    private function buildSmtpMessage(string $toEmail, string $subject, string $htmlBody, ?string $toName): string
    {
        $boundary = bin2hex(random_bytes(16));
        $to = $toName ? $this->encodeHeader($toName) . " <{$toEmail}>" : $toEmail;

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));

        $msg = "From: " . $this->encodeHeader($this->fromName) . " <{$this->fromEmail}>\r\n";
        $msg .= "To: {$to}\r\n";
        $msg .= "Subject: {$subject}\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $msg .= "X-Mailer: ZephyrPHP-CMS\r\n";
        $msg .= "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($plainText) . "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($htmlBody) . "\r\n";
        $msg .= "--{$boundary}--\r\n";

        return $msg;
    }

    private function smtpWrite($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    private function smtpReadOk($socket, int $expectedCode): bool
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // A line with space after the code (e.g., "250 OK") marks the end of multi-line response
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            // Single-line response
            if (strlen($line) < 4) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        return $code === $expectedCode;
    }

    /**
     * Render a Twig template string with variables.
     */
    private function renderTwigString(string $template, array $variables): string
    {
        // Add common variables
        $variables['app_name'] = $_ENV['APP_NAME'] ?? 'CMS';
        $variables['admin_url'] = rtrim($_ENV['APP_URL'] ?? '', '/') . '/cms';

        try {
            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
                'template' => $template,
            ]), ['autoescape' => 'html']);

            return $twig->render('template', $variables);
        } catch (\Exception $e) {
            // Fallback: simple variable replacement
            $result = $template;
            foreach ($variables as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $result = str_replace('{{ ' . $key . ' }}', htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $result);
                }
            }
            return $result;
        }
    }

    /**
     * Encode a header value to prevent injection and handle UTF-8.
     */
    private function encodeHeader(string $value): string
    {
        // Strip any CR/LF to prevent header injection
        $value = str_replace(["\r", "\n"], '', $value);

        // Encode if contains non-ASCII
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
