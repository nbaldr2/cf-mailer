<?php

declare(strict_types=1);

namespace CfMailer\Validator;

use CfMailer\Exception\ValidationException;

/**
 * Email validation utilities.
 *
 * Provides strict validation methods for email addresses, subjects,
 * and header values. Used internally by the mailer but also available
 * for external validation before building messages.
 */
final class EmailValidator
{
    /**
     * Disposable email domain blocklist (partial list – extend as needed).
     *
     * @var string[]
     */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com',
        'guerrillamail.com',
        'tempmail.com',
        'throwaway.email',
        'yopmail.com',
        'trashmail.com',
        'dispostable.com',
        'sharklasers.com',
        'grr.la',
        'guerrillamailblock.com',
        'maildrop.cc',
        'temp-mail.org',
    ];

    /**
     * Validate a single email address.
     *
     * @throws ValidationException
     */
    public static function validateEmail(string $email): bool
    {
        $email = trim($email);

        if ($email === '') {
            throw new ValidationException('Email address cannot be empty.', ['email_empty']);
        }

        // Header injection check
        if (preg_match('/[\r\n\x00]/', $email)) {
            throw new ValidationException(
                'Email contains forbidden characters (header injection attempt).',
                ['header_injection']
            );
        }

        // RFC length limits
        if (mb_strlen($email) > 254) {
            throw new ValidationException('Email exceeds 254 characters.', ['email_too_long']);
        }

        // PHP filter validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Invalid email: {$email}", ['invalid_email']);
        }

        return true;
    }

    /**
     * Validate multiple email addresses.
     *
     * @param string[] $emails
     * @return string[] List of invalid emails (empty if all valid)
     */
    public static function validateMany(array $emails): array
    {
        $invalid = [];

        foreach ($emails as $email) {
            try {
                self::validateEmail($email);
            } catch (ValidationException) {
                $invalid[] = $email;
            }
        }

        return $invalid;
    }

    /**
     * Check if an email domain is a known disposable/temporary provider.
     */
    public static function isDisposable(string $email): bool
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $domain = strtolower($parts[1]);
        return in_array($domain, self::DISPOSABLE_DOMAINS, true);
    }

    /**
     * Check if the email domain has valid MX records.
     *
     * Warning: This performs a DNS lookup and may be slow.
     * Use sparingly and consider caching results.
     */
    public static function hasMxRecord(string $email): bool
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return false;
        }

        return checkdnsrr($parts[1], 'MX');
    }

    /**
     * Sanitize a string to prevent header injection.
     *
     * Strips CR, LF, and null bytes.
     */
    public static function sanitizeHeaderValue(string $value): string
    {
        return preg_replace('/[\r\n\x00]/', '', $value) ?? $value;
    }

    /**
     * Sanitize HTML content for email body.
     *
     * Strips potentially dangerous tags (script, style, iframe, etc.)
     * while preserving safe formatting elements.
     */
    public static function sanitizeHtml(string $html): string
    {
        // Remove script, style, iframe, object, embed tags and their contents
        $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form'];
        foreach ($dangerous as $tag) {
            $html = preg_replace(
                '/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
                '',
                $html
            ) ?? $html;
        }

        // Remove event handler attributes
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;

        // Remove javascript: protocol URLs
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html) ?? $html;

        return $html;
    }

    /**
     * Normalize an email address (lowercase, trim whitespace).
     */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
