<?php

declare(strict_types=1);

namespace CfMailer\Exception;

/**
 * Thrown when the Cloudflare API rate limit is exceeded.
 */
class RateLimitException extends MailerException
{
    private readonly int $retryAfter;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        int $retryAfter = 60,
        string $message = 'Rate limit exceeded',
        int $code = 429,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Number of seconds to wait before retrying.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
