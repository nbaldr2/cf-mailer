<?php

declare(strict_types=1);

namespace CfMailer\Tests\Unit;

use CfMailer\CloudflareMailer;
use CfMailer\Config\MailerConfig;
use CfMailer\Email\Message;
use CfMailer\Http\CloudflareClient;
use CfMailer\Logger\MailerLogger;
use PHPUnit\Framework\TestCase;
use Mockery;
use Psr\Log\NullLogger;

final class CloudflareMailerTest extends TestCase
{
    private CloudflareMailer $mailer;
    private object $mockClient;

    protected function setUp(): void
    {
        $config = new MailerConfig([
            'CLOUDFLARE_ACCOUNT_ID' => 'test_account',
            'CLOUDFLARE_API_TOKEN' => 'test_token',
            'CLOUDFLARE_FROM_EMAIL' => 'test@example.com',
            'CLOUDFLARE_FROM_NAME' => 'Test',
        ]);

        $this->mockClient = Mockery::mock(CloudflareClient::class);
        $this->mailer = new CloudflareMailer($config, new NullLogger(), $this->mockClient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testSendEmailCallsClient(): void
    {
        $this->mockClient
            ->shouldReceive('sendEmail')
            ->once()
            ->andReturn([
                'success' => true,
                'status_code' => 200,
                'data' => ['result' => ['id' => 'msg-123']],
                'message_id' => 'msg-123',
                'attempts' => 1,
            ]);

        $message = (new Message())
            ->to('recipient@example.com')
            ->subject('Test')
            ->text('Hello');

        $result = $this->mailer->sendEmail($message);

        $this->assertTrue($result['success']);
        $this->assertSame('msg-123', $result['message_id']);
    }

    public function testDefaultFromIsApplied(): void
    {
        $this->mockClient
            ->shouldReceive('sendEmail')
            ->once()
            ->withArgs(function (array $payload): bool {
                return $payload['from'] === '"Test" <test@example.com>';
            })
            ->andReturn(['success' => true, 'status_code' => 200, 'data' => [], 'message_id' => null, 'attempts' => 1]);

        $message = (new Message())
            ->to('recipient@example.com')
            ->subject('Default From')
            ->text('Test');

        $this->mailer->sendEmail($message);
        // Assertion is in the Mockery withArgs callback above
        $this->assertTrue(true);
    }

    public function testBulkSendReturnsResults(): void
    {
        $this->mockClient
            ->shouldReceive('sendEmail')
            ->times(3)
            ->andReturn(['success' => true, 'status_code' => 200, 'data' => [], 'message_id' => 'bulk', 'attempts' => 1]);

        $messages = [];
        for ($i = 0; $i < 3; $i++) {
            $messages[] = (new Message())
                ->to("user{$i}@example.com")
                ->subject('Bulk')
                ->text('Test');
        }

        $results = $this->mailer->sendBulkEmails($messages, 0);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]['success']);
    }

    public function testQueueOperations(): void
    {
        $message = (new Message())
            ->to('queued@example.com')
            ->subject('Queued')
            ->text('Test');

        $id = $this->mailer->enqueue($message);
        $this->assertNotEmpty($id);
        $this->assertSame(1, $this->mailer->getQueueSize());

        $flushed = $this->mailer->flushQueue();
        $this->assertSame(1, $flushed);
        $this->assertSame(0, $this->mailer->getQueueSize());
    }

    public function testGetConfigRedactsToken(): void
    {
        $config = $this->mailer->getConfig();
        $this->assertSame('***REDACTED***', $config['api_token']);
        $this->assertStringNotContainsString('test_token', json_encode($config));
    }
}
