<?php

/**
 * Example: REST API endpoint for sending emails.
 *
 * Standalone PHP endpoint — no framework required.
 * Can be placed behind Nginx/Apache and called via POST.
 *
 * POST /api/send-email
 * Content-Type: application/json
 * X-API-Key: your-internal-api-key
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;
use CfMailer\Exception\MailerException;
use CfMailer\Exception\RateLimitException;
use CfMailer\Exception\ValidationException;

// ── CORS & Security headers ──
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Simple API key authentication (replace with your own auth)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expectedKey = getenv('INTERNAL_API_KEY') ?: 'change-me-in-production';

if (!hash_equals($expectedKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);

if ($input === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Required fields
$required = ['to', 'subject'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(422);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

// Must have html or text
if (empty($input['html']) && empty($input['text'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Either html or text body is required']);
    exit;
}

try {
    $mailer = CloudflareMailer::create(__DIR__ . '/..');

    $message = new Message();
    $message->subject($input['subject']);

    // Recipients
    $recipients = is_array($input['to']) ? $input['to'] : [$input['to']];
    foreach ($recipients as $to) {
        if (is_array($to)) {
            $message->to($to['email'], $to['name'] ?? null);
        } else {
            $message->to($to);
        }
    }

    // CC
    if (!empty($input['cc'])) {
        $ccList = is_array($input['cc']) ? $input['cc'] : [$input['cc']];
        foreach ($ccList as $cc) {
            $message->cc(is_array($cc) ? $cc['email'] : $cc, is_array($cc) ? ($cc['name'] ?? null) : null);
        }
    }

    // BCC
    if (!empty($input['bcc'])) {
        $bccList = is_array($input['bcc']) ? $input['bcc'] : [$input['bcc']];
        foreach ($bccList as $bcc) {
            $message->bcc(is_array($bcc) ? $bcc['email'] : $bcc);
        }
    }

    // Reply-To
    if (!empty($input['reply_to'])) {
        $message->replyTo($input['reply_to']);
    }

    // Content
    if (!empty($input['html'])) {
        $message->html($input['html']);
    }
    if (!empty($input['text'])) {
        $message->text($input['text']);
    }

    // Custom headers
    if (!empty($input['headers']) && is_array($input['headers'])) {
        foreach ($input['headers'] as $name => $value) {
            $message->header($name, $value);
        }
    }

    $result = $mailer->sendEmail($message);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message_id' => $result['message_id'] ?? null,
        'attempts' => $result['attempts'],
    ]);
} catch (ValidationException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage(), 'details' => $e->getErrors()]);
} catch (RateLimitException $e) {
    http_response_code(429);
    header("Retry-After: {$e->getRetryAfter()}");
    echo json_encode(['error' => $e->getMessage(), 'retry_after' => $e->getRetryAfter()]);
} catch (MailerException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send email', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
