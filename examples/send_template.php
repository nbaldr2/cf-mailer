<?php

/**
 * Example: Send a template-based email.
 *
 * Usage: php examples/send_template.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Template;

$mailer = CloudflareMailer::create(__DIR__ . '/..');

try {
    // ── Method 1: Load template from file ──
    $result = $mailer->sendTemplateEmail(
        template: __DIR__ . '/../templates/welcome.html',
        variables: [
            'subject' => 'Welcome to Our Platform!',
            'company_name' => 'Acme Corp',
            'recipient_name' => 'Soufiane',
            'message_body' => '<p>Welcome aboard! We\'re thrilled to have you join our community.</p><p>Here\'s what you can do next:</p><ul><li>Complete your profile</li><li>Explore our features</li><li>Connect with other users</li></ul>',
            'cta_url' => 'https://app.example.com/onboarding',
            'cta_text' => 'Get Started',
            'year' => date('Y'),
            'company_address' => '123 Main Street, Suite 100, San Francisco, CA 94105',
            'unsubscribe_url' => 'https://app.example.com/unsubscribe',
            'privacy_url' => 'https://example.com/privacy',
        ],
        to: 'soufiane@example.com',
        subject: 'Welcome to Our Platform!',
        textFallback: 'Welcome, Soufiane! We are thrilled to have you. Visit https://app.example.com/onboarding to get started.'
    );

    echo "✅ Template email sent!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";

    // ── Method 2: Inline template string ──
    $inlineTemplate = Template::fromString(<<<HTML
        <div style="font-family: Arial; max-width: 500px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #764ba2;">Password Reset</h2>
            <p>Hi {{ name }},</p>
            <p>Click the link below to reset your password:</p>
            <p><a href="{{ reset_url }}" style="display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px;">Reset Password</a></p>
            <p style="color: #999; font-size: 12px;">This link expires in {{ expiry_hours }} hours.</p>
        </div>
    HTML);

    $result = $mailer->sendTemplateEmail(
        template: $inlineTemplate,
        variables: [
            'name' => 'Alice',
            'reset_url' => 'https://app.example.com/reset?token=abc123',
            'expiry_hours' => '24',
        ],
        to: 'alice@example.com',
        subject: 'Reset Your Password',
        textFallback: 'Hi Alice, visit https://app.example.com/reset?token=abc123 to reset your password. Link expires in 24 hours.'
    );

    echo "✅ Inline template email sent!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";

    // ── Method 3: Check for missing variables ──
    $template = Template::fromFile(__DIR__ . '/../templates/welcome.html');
    $missing = $template->getMissingVariables();
    echo "\n📋 Template requires these variables: " . implode(', ', $template->getRequiredVariables()) . "\n";
} catch (\Throwable $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
    exit(1);
}
