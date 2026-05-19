<?php

/**
 * Example: Send bulk emails with rate-limit handling.
 *
 * Usage: php examples/send_bulk.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;

$mailer = CloudflareMailer::create(__DIR__ . '/..');

// ── Build a list of messages ──
$recipients = [
    ['email' => 'alice@example.com', 'name' => 'Alice'],
    ['email' => 'bob@example.com', 'name' => 'Bob'],
    ['email' => 'charlie@example.com', 'name' => 'Charlie'],
    ['email' => 'diana@example.com', 'name' => 'Diana'],
    ['email' => 'eve@example.com', 'name' => 'Eve'],
];

$messages = [];
foreach ($recipients as $recipient) {
    $messages[] = (new Message())
        ->to($recipient['email'], $recipient['name'])
        ->subject("Important Update for {$recipient['name']}")
        ->html(sprintf(
            '<h2>Hello, %s!</h2><p>This is a personalized bulk email.</p><p>— The Team</p>',
            htmlspecialchars($recipient['name'])
        ))
        ->text("Hello, {$recipient['name']}! This is a personalized bulk email. — The Team")
        ->header('X-Bulk-ID', 'BULK-2025-05-001');
}

try {
    echo "📧 Sending {$count} emails...\n";
    $count = count($messages);

    // Send with 200ms delay between each message
    $results = $mailer->sendBulkEmails($messages, delayBetweenMs: 200);

    // Report results
    $success = 0;
    $failed = 0;
    foreach ($results as $index => $result) {
        $recipient = $recipients[$index]['email'];
        if ($result['success']) {
            $success++;
            echo "  ✅ {$recipient}\n";
        } else {
            $failed++;
            echo "  ❌ {$recipient}: {$result['error']}\n";
        }
    }

    echo "\n📊 Results: {$success}/{$count} sent, {$failed} failed.\n";
} catch (\Throwable $e) {
    echo "❌ Bulk send aborted: " . $e->getMessage() . "\n";
    exit(1);
}
