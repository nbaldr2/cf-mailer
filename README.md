# CfMailer — Cloudflare Email Service PHP Client

Production-ready PHP 8.2+ email mailer built on the **Cloudflare Email Sending REST API**.

## Features

- 📧 HTML & plain-text emails
- 📎 File & inline attachments
- 👥 Multiple recipients, CC, BCC
- ↩️ Reply-To & custom headers
- 📄 Template engine with variable substitution
- 🔄 Retry logic with exponential backoff + jitter
- 🚦 Rate-limit handling (local + API-level)
- 📋 Queue-ready architecture
- 🔒 Input validation, header-injection prevention, sanitization
- 📊 Structured logging via Monolog
- 🧪 Full unit test suite

## Requirements

- PHP 8.2+
- Composer
- Extensions: `json`, `mbstring`, `fileinfo`, `curl`

## Installation

```bash
cd /Applications/MAMP/htdocs/cf-mailer
composer install
cp .env.example .env
# Edit .env with your Cloudflare credentials
```

## Configuration (.env)

```env
CLOUDFLARE_ACCOUNT_ID="your_account_id"
CLOUDFLARE_API_TOKEN="your_api_token"
CLOUDFLARE_FROM_EMAIL="noreply@yourdomain.com"
CLOUDFLARE_FROM_NAME="Your App"
MAILER_TIMEOUT=30
MAILER_RETRY_ATTEMPTS=3
MAILER_RETRY_DELAY=1000
MAILER_LOG_LEVEL="debug"
MAILER_LOG_PATH="./logs/mailer.log"
MAILER_RATE_LIMIT_PER_SECOND=10
MAILER_RATE_LIMIT_PER_MINUTE=100
```

## Quick Start

```php
<?php
require_once 'vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;

$mailer = CloudflareMailer::create(__DIR__);

// Simple text email
$mailer->sendText('user@example.com', 'Hello!', 'Welcome to our app.');

// HTML email with attachments
$message = (new Message())
    ->to('alice@example.com', 'Alice')
    ->cc('bob@example.com')
    ->replyTo('support@example.com')
    ->subject('Monthly Report')
    ->html('<h1>Report</h1><p>See attached.</p>')
    ->text('Report — see attached.')
    ->attachFile('/path/to/report.pdf')
    ->header('X-Campaign', 'report-2025');

$result = $mailer->sendEmail($message);
```

## Project Structure

```
cf-mailer/
├── composer.json
├── .env / .env.example
├── phpunit.xml
├── src/
│   ├── CloudflareMailer.php      # Main facade
│   ├── Config/MailerConfig.php   # Configuration
│   ├── Email/
│   │   ├── Address.php           # Validated email address
│   │   ├── Attachment.php        # File attachments
│   │   ├── Message.php           # Fluent message builder
│   │   └── Template.php          # HTML template engine
│   ├── Exception/
│   │   ├── MailerException.php
│   │   ├── ValidationException.php
│   │   ├── RateLimitException.php
│   │   └── AuthenticationException.php
│   ├── Http/CloudflareClient.php # API HTTP client
│   ├── Logger/MailerLogger.php   # Monolog factory
│   ├── Queue/EmailQueue.php      # Priority queue
│   └── Validator/EmailValidator.php
├── templates/welcome.html        # Example HTML template
├── examples/
│   ├── send_simple.php
│   ├── send_html.php
│   ├── send_bulk.php
│   ├── send_template.php
│   ├── controller_example.php
│   └── api_endpoint.php
├── tests/Unit/
│   ├── MessageTest.php
│   ├── EmailValidatorTest.php
│   └── CloudflareMailerTest.php
└── logs/
```

## API Methods

| Method | Description |
|--------|-------------|
| `sendEmail(Message)` | Send a single email |
| `sendText(to, subject, body)` | Quick plain-text send |
| `sendHtml(to, subject, html)` | Quick HTML send |
| `sendBulkEmails(messages[], delay)` | Bulk send with rate limiting |
| `sendTemplateEmail(template, vars, to, subject)` | Template-based send |
| `addAttachment(path)` | Create file attachment |
| `enqueue(message, priority)` | Queue for deferred send |
| `processQueue()` | Process all queued emails |
| `validateEmail(email)` | Validate an email address |
| `isDisposable(email)` | Check disposable provider |

## Running Tests

```bash
composer test
# or
./vendor/bin/phpunit
```

## Code Quality

```bash
composer lint      # PSR-12 check
composer analyse   # PHPStan static analysis
```

## Security

- All email addresses validated against RFC 5322
- Header injection (CR/LF) blocked at every input point
- HTML content sanitized (XSS prevention)
- API token never exposed in logs or debug output
- Attachment filenames validated against path traversal
- MIME types restricted to safe list

## License

MIT
