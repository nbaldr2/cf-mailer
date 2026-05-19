<?php

/**
 * Example: Send a rich HTML email with attachments.
 *
 * Usage: php examples/send_html.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Attachment;
use CfMailer\Email\Message;

$mailer = CloudflareMailer::create(__DIR__ . '/..');

try {
    $html = <<<HTML
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.1);">
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); padding: 30px; text-align: center;">
                <h1 style="color: #fff; margin: 0;">Monthly Report</h1>
            </div>
            <div style="padding: 30px;">
                <p>Hi <strong>John</strong>,</p>
                <p>Please find attached your monthly analytics report for <strong>May 2025</strong>.</p>
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                    <tr style="background: #667eea; color: white;">
                        <th style="padding: 12px; text-align: left;">Metric</th>
                        <th style="padding: 12px; text-align: right;">Value</th>
                    </tr>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">Emails Sent</td>
                        <td style="padding: 12px; text-align: right; font-weight: bold;">12,450</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">Open Rate</td>
                        <td style="padding: 12px; text-align: right; font-weight: bold; color: #27ae60;">68.3%</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px;">Click Rate</td>
                        <td style="padding: 12px; text-align: right; font-weight: bold; color: #2980b9;">24.1%</td>
                    </tr>
                </table>
                <p style="color: #888; font-size: 13px;">This is an automated report. Do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    HTML;

    // Create a sample CSV attachment from content
    $csvData = "Metric,Value\nEmails Sent,12450\nOpen Rate,68.3%\nClick Rate,24.1%\n";
    $csvAttachment = Attachment::fromContent($csvData, 'report-may-2025.csv', 'text/csv');

    $message = (new Message())
        ->to('john@example.com', 'John Doe')
        ->cc('analytics@example.com', 'Analytics Team')
        ->subject('Monthly Report — May 2025')
        ->html($html)
        ->text('Hi John, your monthly report is attached. Emails Sent: 12,450 | Open Rate: 68.3% | Click Rate: 24.1%')
        ->addAttachment($csvAttachment)
        ->replyTo('reports@example.com', 'Reports')
        ->header('X-Report-ID', 'RPT-2025-05')
        ->header('X-Priority', '3');

    // Attach a file from disk (uncomment if file exists):
    // $message->attachFile('/path/to/report.pdf', 'full-report.pdf', 'application/pdf');

    $result = $mailer->sendEmail($message);

    echo "✅ HTML email with attachment sent!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
} catch (\Throwable $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
    exit(1);
}
