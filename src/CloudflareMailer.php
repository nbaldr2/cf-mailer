<?php

declare(strict_types=1);

namespace CfMailer;

use CfMailer\Config\MailerConfig;
use CfMailer\Email\Address;
use CfMailer\Email\Attachment;
use CfMailer\Email\Message;
use CfMailer\Email\Template;
use CfMailer\Exception\MailerException;
use CfMailer\Exception\RateLimitException;
use CfMailer\Http\CloudflareClient;
use CfMailer\Logger\MailerLogger;
use CfMailer\Queue\EmailQueue;
use CfMailer\Validator\EmailValidator;
use Psr\Log\LoggerInterface;

/**
 * Production-ready Cloudflare Email Service API client.
 *
 * Provides a fluent, type-safe interface for sending transactional emails
 * through the Cloudflare Email Sending REST API.
 *
 * Features:
 *  - HTML and plain-text emails
 *  - File and inline attachments
 *  - CC, BCC, Reply-To, custom headers
 *  - Template rendering with variable substitution
 *  - Bulk sending with rate-limit awareness
 *  - Retry logic with exponential backoff
 *  - Queue-ready architecture
 *  - Comprehensive input validation and sanitization
 *  - Structured logging via PSR-3
 *
 * @example
 *   $mailer = CloudflareMailer::create();
 *   $result = $mailer->sendEmail(
 *       (new Message())
 *           ->from('hello@example.com', 'My App')
 *           ->to('user@example.com', 'John Doe')
 *           ->subject('Welcome!')
 *           ->html('<h1>Hello, John!</h1>')
 *           ->text('Hello, John!')
 *   );
 */
final class CloudflareMailer
{
    private readonly MailerConfig $config;
    private readonly CloudflareClient $client;
    private readonly LoggerInterface $logger;
    private readonly EmailQueue $queue;

    public function __construct(
        MailerConfig $config,
        ?LoggerInterface $logger = null,
        ?CloudflareClient $client = null,
    ) {
        $this->config = $config;
        $this->logger = $logger ?? MailerLogger::getInstance(
            $config->getLogPath(),
            $config->getLogLevel()
        );
        $this->client = $client ?? new CloudflareClient($config, $this->logger);
        $this->queue = new EmailQueue($this->logger);
    }

    /**
     * Factory: create a mailer instance from environment variables.
     *
     * Loads .env automatically if a .env file exists in the given directory.
     *
     * @param string|null $envPath Directory containing the .env file
     * @param array<string, mixed> $overrides Config overrides
     */
    public static function create(?string $envPath = null, array $overrides = []): self
    {
        // Auto-load .env if dotenv is available
        if ($envPath !== null && class_exists(\Dotenv\Dotenv::class)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
            $dotenv->safeLoad();
        }

        $config = new MailerConfig($overrides);
        return new self($config);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Core Sending Methods
    // ────────────────────────────────────────────────────────────────────

    /**
     * Send a single email message.
     *
     * The message is validated, the default "from" address is applied
     * if not set, and the email is dispatched via the Cloudflare API.
     *
     * @return array<string, mixed> API response
     * @throws MailerException On API or network errors
     */
    public function sendEmail(Message $message): array
    {
        // Apply default sender if not set on the message
        if ($message->getFrom() === null) {
            $message->from($this->config->getFromEmail(), $this->config->getFromName());
        }

        // Validate before sending
        $message->validate();

        $this->logger->info('Sending email', [
            'to' => array_map(fn(Address $a) => $a->getEmail(), $message->getTo()),
            'subject' => $message->getSubject(),
        ]);

        $payload = $message->toApiPayload();
        $result = $this->client->sendEmail($payload);

        $this->logger->info('Email dispatch complete', [
            'success' => $result['success'],
            'message_id' => $result['message_id'] ?? 'N/A',
            'attempts' => $result['attempts'],
        ]);

        return $result;
    }

    /**
     * Send multiple emails in bulk with rate-limit awareness.
     *
     * Each message is sent individually (the Cloudflare API does not
     * support batch endpoints). Failures are collected and returned
     * alongside successes — one failure does not abort the batch.
     *
     * @param Message[] $messages
     * @param int $delayBetweenMs Delay between sends (milliseconds)
     * @return array<int, array{success: bool, result?: array<string, mixed>, error?: string}>
     */
    public function sendBulkEmails(array $messages, int $delayBetweenMs = 100): array
    {
        $results = [];
        $total = count($messages);

        $this->logger->info("Starting bulk send", ['total' => $total]);

        foreach ($messages as $index => $message) {
            try {
                $result = $this->sendEmail($message);
                $results[$index] = ['success' => true, 'result' => $result];

                $this->logger->debug("Bulk send progress", [
                    'completed' => $index + 1,
                    'total' => $total,
                ]);
            } catch (RateLimitException $e) {
                // On rate limit, pause and retry this message
                $waitSeconds = $e->getRetryAfter();
                $this->logger->warning("Rate limited during bulk send, pausing {$waitSeconds}s", [
                    'index' => $index,
                ]);

                sleep($waitSeconds);

                // Retry the rate-limited message
                try {
                    $result = $this->sendEmail($message);
                    $results[$index] = ['success' => true, 'result' => $result];
                } catch (\Throwable $retryError) {
                    $results[$index] = ['success' => false, 'error' => $retryError->getMessage()];
                }
            } catch (\Throwable $e) {
                $this->logger->error("Bulk send failed for message {$index}", [
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
            }

            // Rate-limiting delay between sends
            if ($index < $total - 1 && $delayBetweenMs > 0) {
                usleep($delayBetweenMs * 1000);
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $this->logger->info("Bulk send complete", [
            'total' => $total,
            'success' => $successCount,
            'failed' => $total - $successCount,
        ]);

        return $results;
    }

    /**
     * Send an email using a template with variable substitution.
     *
     * @param string|Template $template Template file path, HTML string, or Template instance
     * @param array<string, string> $variables Template variables
     * @param string|string[] $to Recipient email(s)
     * @param string $subject Email subject
     * @param string|null $textFallback Optional plain-text fallback body
     * @return array<string, mixed> API response
     */
    public function sendTemplateEmail(
        string|Template $template,
        array $variables,
        string|array $to,
        string $subject,
        ?string $textFallback = null
    ): array {
        // Resolve template
        if (is_string($template)) {
            if (file_exists($template)) {
                $tpl = Template::fromFile($template);
            } else {
                $tpl = Template::fromString($template);
            }
        } else {
            $tpl = $template;
        }

        $tpl->setMany($variables);
        $renderedHtml = $tpl->render();

        // Build message
        $message = new Message();
        $message->subject($subject)->html($renderedHtml);

        if ($textFallback !== null) {
            $message->text($textFallback);
        }

        // Add recipients
        $recipients = is_array($to) ? $to : [$to];
        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $message->to($recipient['email'], $recipient['name'] ?? null);
            } else {
                $message->to($recipient);
            }
        }

        $this->logger->info('Sending template email', [
            'template_vars' => array_keys($variables),
            'recipients' => count($recipients),
        ]);

        return $this->sendEmail($message);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Convenience Builders
    // ────────────────────────────────────────────────────────────────────

    /**
     * Quick-send a plain text email.
     *
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $subject, string $body): array
    {
        $message = (new Message())
            ->to($to)
            ->subject($subject)
            ->text($body);

        return $this->sendEmail($message);
    }

    /**
     * Quick-send an HTML email.
     *
     * @return array<string, mixed>
     */
    public function sendHtml(string $to, string $subject, string $html, ?string $textFallback = null): array
    {
        $message = (new Message())
            ->to($to)
            ->subject($subject)
            ->html($html);

        if ($textFallback !== null) {
            $message->text($textFallback);
        }

        return $this->sendEmail($message);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Attachment Helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Create an attachment from a file (convenience wrapper).
     */
    public function addAttachment(
        string $filePath,
        ?string $filename = null,
        ?string $mimeType = null
    ): Attachment {
        return Attachment::fromFile($filePath, $filename, $mimeType);
    }

    /**
     * Create an attachment from raw content.
     */
    public function addContentAttachment(
        string $content,
        string $filename,
        string $mimeType = 'application/octet-stream'
    ): Attachment {
        return Attachment::fromContent($content, $filename, $mimeType);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Queue Operations
    // ────────────────────────────────────────────────────────────────────

    /**
     * Enqueue a message for deferred sending.
     *
     * @return string Queue item ID
     */
    public function enqueue(Message $message, int $priority = 0): string
    {
        if ($message->getFrom() === null) {
            $message->from($this->config->getFromEmail(), $this->config->getFromName());
        }

        return $this->queue->push($message, $priority);
    }

    /**
     * Process all queued messages.
     *
     * @return array<string, array<string, mixed>> Results keyed by queue item ID
     */
    public function processQueue(): array
    {
        $this->logger->info("Processing email queue", ['size' => $this->queue->size()]);

        return $this->queue->process(function (Message $message, string $id): array {
            return $this->sendEmail($message);
        });
    }

    /**
     * Get the current queue size.
     */
    public function getQueueSize(): int
    {
        return $this->queue->size();
    }

    /**
     * Flush (discard) all queued messages.
     */
    public function flushQueue(): int
    {
        return $this->queue->flush();
    }

    /**
     * Export queue items as serializable arrays (for external queue backends).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportQueue(): array
    {
        return $this->queue->toArray();
    }

    // ────────────────────────────────────────────────────────────────────
    //  Validation Utilities
    // ────────────────────────────────────────────────────────────────────

    /**
     * Validate an email address.
     */
    public function validateEmail(string $email): bool
    {
        return EmailValidator::validateEmail($email);
    }

    /**
     * Check if an email is from a disposable provider.
     */
    public function isDisposable(string $email): bool
    {
        return EmailValidator::isDisposable($email);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Introspection
    // ────────────────────────────────────────────────────────────────────

    /**
     * Get the mailer configuration (redacted for safety).
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config->toDebugArray();
    }

    /**
     * Get the underlying logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
