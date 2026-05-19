<?php

declare(strict_types=1);

namespace CfMailer\Email;

use CfMailer\Exception\ValidationException;

/**
 * Value object representing an email attachment.
 *
 * Supports both file-path and raw-content attachments.
 * Content is base64-encoded for the Cloudflare API payload.
 */
final class Attachment
{
    private readonly string $filename;
    private readonly string $content;
    private readonly string $mimeType;
    private readonly ?string $contentId;

    /**
     * Maximum attachment size in bytes (25 MB).
     */
    private const MAX_SIZE = 25 * 1024 * 1024;

    /**
     * Allowed MIME type prefixes for security.
     */
    private const ALLOWED_MIME_PREFIXES = [
        'text/',
        'image/',
        'application/pdf',
        'application/msword',
        'application/vnd.',
        'application/zip',
        'application/x-zip',
        'application/gzip',
        'application/json',
        'application/xml',
        'audio/',
        'video/',
    ];

    private function __construct(
        string $filename,
        string $content,
        string $mimeType,
        ?string $contentId = null
    ) {
        $this->validateFilename($filename);
        $this->validateMimeType($mimeType);

        if (strlen($content) > self::MAX_SIZE) {
            throw new ValidationException(
                sprintf('Attachment "%s" exceeds maximum size of %d MB.', $filename, self::MAX_SIZE / 1024 / 1024),
                ['attachment_too_large']
            );
        }

        $this->filename = $filename;
        $this->content = $content;
        $this->mimeType = $mimeType;
        $this->contentId = $contentId;
    }

    /**
     * Create an attachment from a file on disk.
     */
    public static function fromFile(
        string $filePath,
        ?string $filename = null,
        ?string $mimeType = null,
        ?string $contentId = null
    ): self {
        if (!file_exists($filePath)) {
            throw new ValidationException("Attachment file not found: {$filePath}", ['file_not_found']);
        }

        if (!is_readable($filePath)) {
            throw new ValidationException("Attachment file is not readable: {$filePath}", ['file_not_readable']);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ValidationException("Failed to read attachment file: {$filePath}", ['file_read_error']);
        }

        $filename = $filename ?? basename($filePath);
        $mimeType = $mimeType ?? (mime_content_type($filePath) ?: 'application/octet-stream');

        return new self($filename, $content, $mimeType, $contentId);
    }

    /**
     * Create an attachment from raw content string.
     */
    public static function fromContent(
        string $content,
        string $filename,
        string $mimeType = 'application/octet-stream',
        ?string $contentId = null
    ): self {
        return new self($filename, $content, $mimeType, $contentId);
    }

    /**
     * Create an inline image attachment (for use in HTML body with cid:).
     */
    public static function inline(
        string $filePath,
        string $contentId,
        ?string $filename = null,
        ?string $mimeType = null
    ): self {
        return self::fromFile($filePath, $filename, $mimeType, $contentId);
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentBase64(): string
    {
        return base64_encode($this->content);
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    /**
     * Serialize to the Cloudflare API payload format.
     *
     * @return array<string, string>
     */
    public function toApiPayload(): array
    {
        $payload = [
            'filename' => $this->filename,
            'content' => $this->getContentBase64(),
            'type' => $this->mimeType,
        ];

        if ($this->contentId !== null) {
            $payload['content_id'] = $this->contentId;
            $payload['disposition'] = 'inline';
        } else {
            $payload['disposition'] = 'attachment';
        }

        return $payload;
    }

    /**
     * Validate filename to prevent path traversal and injection.
     */
    private function validateFilename(string $filename): void
    {
        if ($filename === '') {
            throw new ValidationException('Attachment filename cannot be empty.', ['filename_empty']);
        }

        // Prevent path traversal
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new ValidationException(
                'Attachment filename contains forbidden characters.',
                ['filename_injection']
            );
        }

        // Prevent null bytes
        if (str_contains($filename, "\0")) {
            throw new ValidationException(
                'Attachment filename contains null bytes.',
                ['filename_null_byte']
            );
        }

        if (mb_strlen($filename) > 255) {
            throw new ValidationException(
                'Attachment filename exceeds 255 characters.',
                ['filename_too_long']
            );
        }
    }

    /**
     * Validate MIME type against allowed prefixes.
     */
    private function validateMimeType(string $mimeType): void
    {
        $allowed = false;
        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed && $mimeType !== 'application/octet-stream') {
            throw new ValidationException(
                "MIME type '{$mimeType}' is not allowed for security reasons.",
                ['mime_type_blocked']
            );
        }
    }
}
