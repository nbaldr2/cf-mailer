<?php

declare(strict_types=1);

namespace CfMailer\Email;

use CfMailer\Exception\ValidationException;

/**
 * Fluent builder for constructing an email message.
 *
 * Encapsulates all message components (recipients, content, attachments, headers)
 * and serializes them to the Cloudflare API payload format.
 */
final class Message
{
    private ?Address $from = null;
    private ?Address $replyTo = null;
    private string $subject = '';
    private ?string $html = null;
    private ?string $text = null;

    /** @var Address[] */
    private array $to = [];

    /** @var Address[] */
    private array $cc = [];

    /** @var Address[] */
    private array $bcc = [];

    /** @var Attachment[] */
    private array $attachments = [];

    /** @var array<string, string> */
    private array $customHeaders = [];

    /** @var array<string, string> */
    private array $metadata = [];

    /**
     * Set the sender address.
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->from = new Address($email, $name);
        return $this;
    }

    /**
     * Set the sender from an Address object.
     */
    public function fromAddress(Address $address): self
    {
        $this->from = $address;
        return $this;
    }

    /**
     * Add a primary recipient.
     */
    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = new Address($email, $name);
        return $this;
    }

    /**
     * Add a CC recipient.
     */
    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = new Address($email, $name);
        return $this;
    }

    /**
     * Add a BCC recipient.
     */
    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = new Address($email, $name);
        return $this;
    }

    /**
     * Set the Reply-To address.
     */
    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = new Address($email, $name);
        return $this;
    }

    /**
     * Set the email subject.
     */
    public function subject(string $subject): self
    {
        $subject = trim($subject);
        $this->validateSubject($subject);
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the HTML body content.
     */
    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    /**
     * Set the plain-text body content.
     */
    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Add an attachment from a file path.
     */
    public function attachFile(
        string $filePath,
        ?string $filename = null,
        ?string $mimeType = null
    ): self {
        $this->attachments[] = Attachment::fromFile($filePath, $filename, $mimeType);
        return $this;
    }

    /**
     * Add an attachment from raw content.
     */
    public function attachContent(
        string $content,
        string $filename,
        string $mimeType = 'application/octet-stream'
    ): self {
        $this->attachments[] = Attachment::fromContent($content, $filename, $mimeType);
        return $this;
    }

    /**
     * Add an inline image (referenced in HTML via cid:).
     */
    public function embedImage(string $filePath, string $contentId, ?string $filename = null): self
    {
        $this->attachments[] = Attachment::inline($filePath, $contentId, $filename);
        return $this;
    }

    /**
     * Add a pre-built Attachment object.
     */
    public function addAttachment(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;
        return $this;
    }

    /**
     * Set a custom header. Prevents overwriting critical headers.
     */
    public function header(string $name, string $value): self
    {
        $name = trim($name);
        $value = trim($value);

        $this->validateHeader($name, $value);
        $this->customHeaders[$name] = $value;
        return $this;
    }

    /**
     * Add metadata key-value pair (passed through to API for tracking).
     */
    public function meta(string $key, string $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get the sender address.
     */
    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /**
     * @return Address[]
     */
    public function getTo(): array
    {
        return $this->to;
    }

    /**
     * @return Address[]
     */
    public function getCc(): array
    {
        return $this->cc;
    }

    /**
     * @return Address[]
     */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getReplyTo(): ?Address
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * @return Attachment[]
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, string>
     */
    public function getCustomHeaders(): array
    {
        return $this->customHeaders;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Validate the message is complete and well-formed before sending.
     *
     * @throws ValidationException
     */
    public function validate(): void
    {
        $errors = [];

        if ($this->from === null) {
            $errors[] = 'Sender (from) is required.';
        }

        if (empty($this->to)) {
            $errors[] = 'At least one recipient (to) is required.';
        }

        if ($this->subject === '') {
            $errors[] = 'Subject is required.';
        }

        if ($this->html === null && $this->text === null) {
            $errors[] = 'At least one content body (html or text) is required.';
        }

        $totalRecipients = count($this->to) + count($this->cc) + count($this->bcc);
        if ($totalRecipients > 50) {
            $errors[] = 'Total recipients (to + cc + bcc) cannot exceed 50.';
        }

        $totalAttachmentSize = array_sum(array_map(fn(Attachment $a) => $a->getSize(), $this->attachments));
        if ($totalAttachmentSize > 25 * 1024 * 1024) {
            $errors[] = 'Total attachment size cannot exceed 25 MB.';
        }

        if (!empty($errors)) {
            throw new ValidationException('Message validation failed.', $errors);
        }
    }

    /**
     * Serialize the message to the Cloudflare API payload format.
     *
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        $this->validate();

        $payload = [
            'from' => $this->from->toString(),
            'to' => array_map(fn(Address $a) => $a->toString(), $this->to),
            'subject' => $this->subject,
        ];

        if (!empty($this->cc)) {
            $payload['cc'] = array_map(fn(Address $a) => $a->toString(), $this->cc);
        }

        if (!empty($this->bcc)) {
            $payload['bcc'] = array_map(fn(Address $a) => $a->toString(), $this->bcc);
        }

        if ($this->replyTo !== null) {
            $payload['reply_to'] = $this->replyTo->toString();
        }

        if ($this->html !== null) {
            $payload['html'] = $this->html;
        }

        if ($this->text !== null) {
            $payload['text'] = $this->text;
        }

        if (!empty($this->attachments)) {
            $payload['attachments'] = array_map(fn(Attachment $a) => $a->toApiPayload(), $this->attachments);
        }

        if (!empty($this->customHeaders)) {
            $payload['headers'] = $this->customHeaders;
        }



        return $payload;
    }

    /**
     * Create a deep clone of this message (useful for bulk operations).
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Validate subject line against header injection.
     */
    private function validateSubject(string $subject): void
    {
        if (preg_match('/[\r\n\x00]/', $subject)) {
            throw new ValidationException(
                'Subject contains forbidden characters (possible header injection).',
                ['header_injection']
            );
        }

        if (mb_strlen($subject) > 998) {
            throw new ValidationException(
                'Subject exceeds maximum length of 998 characters.',
                ['subject_too_long']
            );
        }
    }

    /**
     * Validate a custom header name/value pair.
     */
    private function validateHeader(string $name, string $value): void
    {
        // Prevent overwriting critical headers
        $reserved = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type', 'content-transfer-encoding', 'mime-version'];
        if (in_array(strtolower($name), $reserved, true)) {
            throw new ValidationException(
                "Cannot set reserved header '{$name}'.",
                ['reserved_header']
            );
        }

        // Prevent header injection
        if (preg_match('/[\r\n\x00]/', $name . $value)) {
            throw new ValidationException(
                "Header '{$name}' contains forbidden characters (possible header injection).",
                ['header_injection']
            );
        }

        if (mb_strlen($name) > 128 || mb_strlen($value) > 8192) {
            throw new ValidationException(
                "Header '{$name}' exceeds maximum allowed length.",
                ['header_too_long']
            );
        }
    }
}
