<?php

declare(strict_types=1);

namespace CfMailer\Config;

/**
 * Immutable configuration container for the Cloudflare Email Service.
 *
 * Loads configuration from environment variables with sensible defaults.
 * All values are validated on construction to fail early.
 */
final class MailerConfig
{
    public const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    private readonly string $accountId;
    private readonly string $apiToken;
    private readonly string $fromEmail;
    private readonly string $fromName;
    private readonly int $timeout;
    private readonly int $retryAttempts;
    private readonly int $retryDelay;
    private readonly string $logLevel;
    private readonly string $logPath;
    private readonly int $rateLimitPerSecond;
    private readonly int $rateLimitPerMinute;
    private readonly bool $queueEnabled;
    private readonly string $queueDriver;
    private readonly string $queueTable;

    /**
     * @param array<string, mixed> $overrides Optional config overrides (useful for testing)
     */
    public function __construct(array $overrides = [])
    {
        $this->accountId = $this->resolveRequired('CLOUDFLARE_ACCOUNT_ID', $overrides);
        $this->apiToken = $this->resolveRequired('CLOUDFLARE_API_TOKEN', $overrides);
        $this->fromEmail = $this->resolveRequired('CLOUDFLARE_FROM_EMAIL', $overrides);
        $this->fromName = $this->resolveOptional('CLOUDFLARE_FROM_NAME', 'Mailer', $overrides);

        $this->timeout = (int) $this->resolveOptional('MAILER_TIMEOUT', '30', $overrides);
        $this->retryAttempts = (int) $this->resolveOptional('MAILER_RETRY_ATTEMPTS', '3', $overrides);
        $this->retryDelay = (int) $this->resolveOptional('MAILER_RETRY_DELAY', '1000', $overrides);
        $this->logLevel = $this->resolveOptional('MAILER_LOG_LEVEL', 'debug', $overrides);
        $this->logPath = $this->resolveOptional('MAILER_LOG_PATH', './logs/mailer.log', $overrides);

        $this->rateLimitPerSecond = (int) $this->resolveOptional('MAILER_RATE_LIMIT_PER_SECOND', '10', $overrides);
        $this->rateLimitPerMinute = (int) $this->resolveOptional('MAILER_RATE_LIMIT_PER_MINUTE', '100', $overrides);

        $this->queueEnabled = filter_var(
            $this->resolveOptional('MAILER_QUEUE_ENABLED', 'false', $overrides),
            FILTER_VALIDATE_BOOLEAN
        );
        $this->queueDriver = $this->resolveOptional('MAILER_QUEUE_DRIVER', 'database', $overrides);
        $this->queueTable = $this->resolveOptional('MAILER_QUEUE_TABLE', 'email_queue', $overrides);

        $this->validate();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    /**
     * Retry delay in milliseconds.
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function getLogLevel(): string
    {
        return $this->logLevel;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    public function getRateLimitPerSecond(): int
    {
        return $this->rateLimitPerSecond;
    }

    public function getRateLimitPerMinute(): int
    {
        return $this->rateLimitPerMinute;
    }

    public function isQueueEnabled(): bool
    {
        return $this->queueEnabled;
    }

    public function getQueueDriver(): string
    {
        return $this->queueDriver;
    }

    public function getQueueTable(): string
    {
        return $this->queueTable;
    }

    /**
     * Build the full API endpoint URL for email sending.
     */
    public function getSendEndpoint(): string
    {
        return sprintf(
            '%s/accounts/%s/email/sending/send',
            self::API_BASE_URL,
            $this->accountId
        );
    }

    /**
     * Returns a redacted representation for debugging (never exposes the token).
     *
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            'account_id' => substr($this->accountId, 0, 6) . '***',
            'api_token' => '***REDACTED***',
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'timeout' => $this->timeout,
            'retry_attempts' => $this->retryAttempts,
            'retry_delay' => $this->retryDelay,
            'rate_limit_per_second' => $this->rateLimitPerSecond,
            'rate_limit_per_minute' => $this->rateLimitPerMinute,
        ];
    }

    /**
     * Resolve a required environment variable.
     *
     * @param array<string, mixed> $overrides
     */
    private function resolveRequired(string $key, array $overrides): string
    {
        $value = $overrides[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === '' || $value === null) {
            throw new \InvalidArgumentException(
                "Required configuration '{$key}' is missing. Set it in your .env file or pass it as an override."
            );
        }

        return (string) $value;
    }

    /**
     * Resolve an optional environment variable with a default.
     *
     * @param array<string, mixed> $overrides
     */
    private function resolveOptional(string $key, string $default, array $overrides): string
    {
        $value = $overrides[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === '' || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Validate all configuration values.
     */
    private function validate(): void
    {
        if ($this->timeout < 1 || $this->timeout > 120) {
            throw new \InvalidArgumentException('Timeout must be between 1 and 120 seconds.');
        }

        if ($this->retryAttempts < 0 || $this->retryAttempts > 10) {
            throw new \InvalidArgumentException('Retry attempts must be between 0 and 10.');
        }

        if ($this->retryDelay < 100 || $this->retryDelay > 30000) {
            throw new \InvalidArgumentException('Retry delay must be between 100 and 30000 milliseconds.');
        }

        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid from email address: {$this->fromEmail}");
        }
    }
}
