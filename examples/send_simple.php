<?php

/**
 * Example: Send a simple plain-text email.
 *
 * Usage: php examples/send_simple.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;

// Create mailer from .env
$mailer = CloudflareMailer::create(__DIR__ . '/..');

try {
    // ── Method 1: Quick-send shortcut ──
    $result = $mailer->sendText(
        to: 'contact@debbugproduction.com',
        subject: 'Hello from CfMailer!',
        body: "Hi there,\n\nThis is a plain-text email sent via the Cloudflare Email Service API.\n\nBest regards,\nYour App"
    );

    echo "✅ Email sent successfully!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
    echo "   Attempts: " . $result['attempts'] . "\n";

    // ── Method 2: Full message builder ──
    $message = (new Message())
        ->to('user1@example.com', 'Alice')
        ->to('user2@example.com', 'Bob')
        ->cc('manager@example.com', 'Manager')
        ->bcc('audit@example.com')
        ->replyTo('support@example.com', 'Support Team')
        ->subject('Team Update')
        ->text("Hi team,\n\nHere's the weekly update.\n\nCheers!")
        ->header('X-Campaign-ID', 'weekly-update-2025')
        ->meta('campaign', 'weekly-update');

    $result = $mailer->sendEmail($message);

    echo "✅ Team email sent!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
} catch (\Throwable $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
    exit(1);
}
