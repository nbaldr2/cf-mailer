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

## Deployment Guide

### VPS Deployment (Ubuntu/Debian)

1. **Connect to your VPS:**
```bash
ssh root@your-server-ip
```

2. **Install required packages:**
```bash
apt update
apt install -y php8.2 php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml php8.2-fpm php8.2-zip nginx git composer
```

3. **Clone the repository:**
```bash
mkdir -p /var/www
cd /var/www
git clone https://github.com/nbaldr2/cf-mailer.git
cd cf-mailer
composer install --no-dev --optimize-autoloader
```

4. **Set up .env file:**
```bash
cp .env.example .env
chown www-data:www-data .env
chmod 660 .env
# Edit .env with your actual credentials
nano .env
```

**⚠️ IMPORTANT: Quote values containing spaces in .env:**
```env
# ❌ WRONG - Will cause HTTP 500 error
CLOUDFLARE_EMAIL_FROM_NAME=Your Name

# ✅ CORRECT
CLOUDFLARE_EMAIL_FROM_NAME="Your Name"
```

5. **Set permissions:**
```bash
chown -R www-data:www-data /var/www/cf-mailer/logs
chmod -R 755 /var/www/cf-mailer/logs
```

6. **Configure Nginx:**
```bash
cat > /etc/nginx/sites-available/cf-mailer << 'EOF'
server {
    listen 80;
    server_name _;
    root /var/www/cf-mailer;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

ln -sf /etc/nginx/sites-available/cf-mailer /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl restart nginx
systemctl restart php8.2-fpm
```

7. **Access your app:**
Visit: `http://your-server-ip/`

### Troubleshooting

**HTTP ERROR 500:**
- Check `.env` file has proper quoting for values with spaces
- Check file permissions: `chown -R www-data:www-data /var/www/cf-mailer`
- Check logs: `tail -f /var/log/nginx/error.log`

**Permission denied on logs:**
```bash
chown -R www-data:www-data /var/www/cf-mailer/logs
chmod -R 755 /var/www/cf-mailer/logs
```

**Permission denied on .env:**
```bash
chown www-data:www-data /var/www/cf-mailer/.env
chmod 660 /var/www/cf-mailer/.env
```

## License

MIT
