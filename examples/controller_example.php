<?php

/**
 * Example: EmailController — MVC controller integration pattern.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;
use CfMailer\Exception\MailerException;
use CfMailer\Exception\RateLimitException;
use CfMailer\Exception\ValidationException;

final class EmailController
{
    private readonly CloudflareMailer $mailer;

    public function __construct()
    {
        $this->mailer = CloudflareMailer::create(__DIR__ . '/..');
    }

    /** @param array{email: string, name: string} $user */
    public function sendWelcome(array $user): array
    {
        try {
            return $this->mailer->sendTemplateEmail(
                template: __DIR__ . '/../templates/welcome.html',
                variables: [
                    'subject' => 'Welcome!',
                    'company_name' => 'My App',
                    'recipient_name' => $user['name'],
                    'message_body' => '<p>Your account is ready.</p>',
                    'cta_url' => 'https://app.example.com/dashboard',
                    'cta_text' => 'Go to Dashboard',
                    'year' => date('Y'),
                    'company_address' => '123 Main St',
                    'unsubscribe_url' => '#',
                    'privacy_url' => '#',
                ],
                to: $user['email'],
                subject: "Welcome, {$user['name']}!",
            );
        } catch (ValidationException $e) {
            return ['success' => false, 'error' => 'Invalid input', 'details' => $e->getErrors()];
        } catch (RateLimitException $e) {
            return ['success' => false, 'error' => 'Rate limited', 'retry_after' => $e->getRetryAfter()];
        } catch (MailerException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendPasswordReset(string $email, string $token): array
    {
        try {
            $url = "https://app.example.com/reset?token={$token}";
            $message = (new Message())
                ->to($email)
                ->subject('Reset Your Password')
                ->html("<div style='font-family:Arial;max-width:500px;margin:auto;padding:30px;'><h2>Password Reset</h2><p><a href='{$url}' style='background:#667eea;color:#fff;padding:14px 32px;text-decoration:none;border-radius:8px;display:inline-block;'>Reset Password</a></p><p style='color:#888;font-size:13px;'>Expires in 1 hour.</p></div>")
                ->text("Reset your password: {$url}")
                ->header('X-Email-Type', 'password-reset');
            return $this->mailer->sendEmail($message);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendInvoice(string $email, string $name, string $invoiceId, string $pdfPath): array
    {
        try {
            $message = (new Message())
                ->to($email, $name)
                ->subject("Invoice #{$invoiceId}")
                ->html("<div style='font-family:Arial;padding:30px;'><h2>Invoice #{$invoiceId}</h2><p>Hi {$name}, your invoice is attached.</p></div>")
                ->text("Hi {$name}, invoice #{$invoiceId} is attached.")
                ->attachFile($pdfPath, "invoice-{$invoiceId}.pdf", 'application/pdf');
            return $this->mailer->sendEmail($message);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<int, array{email: string, name: string}> $users */
    public function queueNotifications(array $users, string $subject, string $body): array
    {
        foreach ($users as $user) {
            $message = (new Message())
                ->to($user['email'], $user['name'])
                ->subject($subject)
                ->html("<p>{$body}</p>")
                ->text($body);
            $this->mailer->enqueue($message);
        }
        $results = $this->mailer->processQueue();
        $ok = count(array_filter($results, fn($r) => $r['success']));
        return ['queued' => count($users), 'sent' => $ok, 'failed' => count($users) - $ok];
    }
}

// Demo
$ctrl = new EmailController();
echo json_encode($ctrl->sendWelcome(['email' => 'user@example.com', 'name' => 'User']), JSON_PRETTY_PRINT) . "\n";
