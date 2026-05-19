<?php

declare(strict_types=1);

namespace CfMailer\Tests\Unit;

use CfMailer\Email\Message;
use CfMailer\Email\Attachment;
use CfMailer\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testBasicMessageConstruction(): void
    {
        $message = (new Message())
            ->from('sender@example.com', 'Sender')
            ->to('recipient@example.com', 'Recipient')
            ->subject('Test Subject')
            ->text('Hello, World!');

        $payload = $message->toApiPayload();

        $this->assertSame('"Sender" <sender@example.com>', $payload['from']);
        $this->assertSame('"Recipient" <recipient@example.com>', $payload['to'][0]);
        $this->assertSame('Test Subject', $payload['subject']);
        $this->assertSame('Hello, World!', $payload['text']);
    }

    public function testHtmlAndTextBody(): void
    {
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->subject('Dual Body')
            ->html('<h1>Hello</h1>')
            ->text('Hello');

        $payload = $message->toApiPayload();

        $this->assertSame('<h1>Hello</h1>', $payload['html']);
        $this->assertSame('Hello', $payload['text']);
    }

    public function testMultipleRecipients(): void
    {
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com', 'Bob')
            ->to('c@example.com', 'Charlie')
            ->cc('d@example.com')
            ->bcc('e@example.com')
            ->subject('Multi')
            ->text('Hi all');

        $payload = $message->toApiPayload();

        $this->assertCount(2, $payload['to']);
        $this->assertCount(1, $payload['cc']);
        $this->assertCount(1, $payload['bcc']);
    }

    public function testReplyTo(): void
    {
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->replyTo('support@example.com', 'Support')
            ->subject('Reply Test')
            ->text('Test');

        $payload = $message->toApiPayload();

        $this->assertSame('"Support" <support@example.com>', $payload['reply_to']);
    }

    public function testCustomHeaders(): void
    {
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->subject('Headers')
            ->text('Test')
            ->header('X-Campaign', 'test-123')
            ->header('X-Priority', '1');

        $payload = $message->toApiPayload();

        $this->assertSame('test-123', $payload['headers']['X-Campaign']);
        $this->assertSame('1', $payload['headers']['X-Priority']);
    }

    public function testReservedHeaderThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Message())->header('From', 'spoofed@evil.com');
    }

    public function testHeaderInjectionInSubject(): void
    {
        $this->expectException(ValidationException::class);

        (new Message())->subject("Test\r\nBCC: evil@example.com");
    }

    public function testValidationRequiresRecipient(): void
    {
        $this->expectException(ValidationException::class);

        $message = (new Message())
            ->from('a@example.com')
            ->subject('No recipients')
            ->text('Test');

        $message->validate();
    }

    public function testValidationRequiresBody(): void
    {
        $this->expectException(ValidationException::class);

        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->subject('No body');

        $message->validate();
    }

    public function testContentAttachment(): void
    {
        $attachment = Attachment::fromContent('CSV data', 'report.csv', 'text/csv');
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->subject('Attach')
            ->text('See attached')
            ->addAttachment($attachment);

        $payload = $message->toApiPayload();

        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('report.csv', $payload['attachments'][0]['filename']);
        $this->assertSame('text/csv', $payload['attachments'][0]['type']);
        $this->assertSame(base64_encode('CSV data'), $payload['attachments'][0]['content']);
    }

    public function testMetadata(): void
    {
        $message = (new Message())
            ->from('a@example.com')
            ->to('b@example.com')
            ->subject('Meta')
            ->text('Test')
            ->meta('campaign_id', 'camp-123')
            ->meta('user_id', 'usr-456');

        $this->assertSame('camp-123', $message->getMetadata()['campaign_id']);
        $this->assertSame('usr-456', $message->getMetadata()['user_id']);
    }
}
