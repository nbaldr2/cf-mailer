<?php

declare(strict_types=1);

namespace CfMailer\Email;

use CfMailer\Exception\ValidationException;

/**
 * Value object representing a validated email address.
 *
 * Performs RFC-compliant validation and header-injection prevention
 * on construction. Instances are guaranteed to hold a safe, valid address.
 */
final class Address
{
    private readonly string $email;
    private readonly ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        $email = trim($email);
        $this->validateEmail($email);
        $this->email = mb_strtolower($email);

        if ($name !== null) {
            $name = $this->sanitizeName(trim($name));
        }
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Returns the formatted address string: "Name <email>" or just "email".
     */
    public function toString(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return sprintf('"%s" <%s>', $this->name, $this->email);
        }

        return $this->email;
    }

    /**
     * Serialize to the Cloudflare API payload format.
     *
     * @return array{email: string, name?: string}
     */
    public function toApiPayload(): array
    {
        $payload = ['email' => $this->email];

        if ($this->name !== null && $this->name !== '') {
            $payload['name'] = $this->name;
        }

        return $payload;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Validate email address against RFC 5322 and prevent header injection.
     */
    private function validateEmail(string $email): void
    {
        if ($email === '') {
            throw new ValidationException('Email address cannot be empty.', ['email_empty']);
        }

        // Reject header injection attempts (\r, \n, null bytes)
        if (preg_match('/[\r\n\x00]/', $email)) {
            throw new ValidationException(
                'Email address contains forbidden characters (possible header injection).',
                ['header_injection']
            );
        }

        if (mb_strlen($email) > 254) {
            throw new ValidationException(
                'Email address exceeds maximum length of 254 characters.',
                ['email_too_long']
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(
                "Invalid email address: {$email}",
                ['invalid_email']
            );
        }

        // Additional RFC 5321 local-part length check
        $parts = explode('@', $email, 2);
        if (mb_strlen($parts[0]) > 64) {
            throw new ValidationException(
                'Email local part exceeds maximum length of 64 characters.',
                ['local_part_too_long']
            );
        }
    }

    /**
     * Sanitize the display name to prevent header injection.
     */
    private function sanitizeName(string $name): string
    {
        // Strip any CR/LF/null characters
        $name = preg_replace('/[\r\n\x00]/', '', $name) ?? $name;

        // Strip any HTML tags
        $name = strip_tags($name);

        // Trim and limit length
        $name = mb_substr(trim($name), 0, 128);

        return $name;
    }
}
