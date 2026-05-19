<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Set test environment variables
$_ENV['CLOUDFLARE_ACCOUNT_ID'] = 'test_account_id';
$_ENV['CLOUDFLARE_API_TOKEN'] = 'test_api_token';
$_ENV['CLOUDFLARE_FROM_EMAIL'] = 'test@example.com';
$_ENV['CLOUDFLARE_FROM_NAME'] = 'Test Mailer';
$_ENV['MAILER_LOG_PATH'] = __DIR__ . '/../logs/test.log';
