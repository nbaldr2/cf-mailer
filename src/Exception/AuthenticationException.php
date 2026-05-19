<?php

declare(strict_types=1);

namespace CfMailer\Exception;

/**
 * Thrown when API authentication fails (invalid or expired token).
 */
class AuthenticationException extends MailerException
{
    public function __construct(
        string $message = 'Authentication failed. Check your CLOUDFLARE_API_TOKEN.',
        int $code = 401,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
