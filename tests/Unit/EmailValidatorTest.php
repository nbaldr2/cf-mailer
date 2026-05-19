<?php

declare(strict_types=1);

namespace CfMailer\Tests\Unit;

use CfMailer\Email\Address;
use CfMailer\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class EmailValidatorTest extends TestCase
{
    public function testValidEmails(): void
    {
        $valid = [
            'user@example.com',
            'user+tag@example.com',
            'user.name@sub.domain.com',
            'a@b.co',
        ];

        foreach ($valid as $email) {
            $address = new Address($email);
            $this->assertSame(strtolower($email), $address->getEmail());
        }
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(ValidationException::class);
        new Address('not-an-email');
    }

    public function testEmptyEmailThrows(): void
    {
        $this->expectException(ValidationException::class);
        new Address('');
    }

    public function testHeaderInjectionInEmail(): void
    {
        $this->expectException(ValidationException::class);
        new Address("user@example.com\r\nBCC: evil@hacker.com");
    }

    public function testNullByteInEmail(): void
    {
        $this->expectException(ValidationException::class);
        new Address("user\x00@example.com");
    }

    public function testTooLongEmail(): void
    {
        $this->expectException(ValidationException::class);
        $long = str_repeat('a', 250) . '@b.com';
        new Address($long);
    }

    public function testNameSanitization(): void
    {
        $address = new Address('user@example.com', "John\r\n<script>alert(1)</script> Doe");
        // strip_tags removes tags but keeps inner text; CR/LF stripped
        $this->assertSame('Johnalert(1) Doe', $address->getName());
    }

    public function testEmailNormalizesToLowercase(): void
    {
        $address = new Address('User@Example.COM');
        $this->assertSame('user@example.com', $address->getEmail());
    }

    public function testToString(): void
    {
        $withName = new Address('user@example.com', 'John Doe');
        $this->assertSame('"John Doe" <user@example.com>', $withName->toString());

        $withoutName = new Address('user@example.com');
        $this->assertSame('user@example.com', $withoutName->toString());
    }

    public function testApiPayload(): void
    {
        $address = new Address('user@example.com', 'John');
        $payload = $address->toApiPayload();

        $this->assertSame('user@example.com', $payload['email']);
        $this->assertSame('John', $payload['name']);

        $addressNoName = new Address('user@example.com');
        $payloadNoName = $addressNoName->toApiPayload();

        $this->assertSame('user@example.com', $payloadNoName['email']);
        $this->assertArrayNotHasKey('name', $payloadNoName);
    }
}
