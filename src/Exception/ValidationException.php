<?php

declare(strict_types=1);

namespace CfMailer\Exception;

/**
 * Thrown when email validation fails (invalid addresses, header injection, etc.).
 */
class ValidationException extends MailerException
{
    /** @var string[] */
    private array $errors;

    /**
     * @param string[] $errors
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        int $code = 422,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->errors = $errors;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
