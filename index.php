<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CfMailer\CloudflareMailer;
use CfMailer\Email\Message;
use CfMailer\Email\Attachment;
use CfMailer\Email\Template;
use CfMailer\Exception\MailerException;

// Initialize session for flash messages and token persistence
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$envPath = __DIR__;
$envFile = $envPath . '/.env';

// Securely load environment
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable($envPath);
    $dotenv->safeLoad();
}

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $mailer = CloudflareMailer::create($envPath);
        
        if ($_GET['action'] === 'get_logs') {
            $logsDir = __DIR__ . '/logs';
            $logPath = null;
            
            // Find the most recent log file (rotating logs have dates in name: mailer-2025-01-15.log)
            if (is_dir($logsDir)) {
                $logFiles = glob($logsDir . '/mailer*.log');
                if (!empty($logFiles)) {
                    // Sort by modification time, newest first
                    usort($logFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
                    $logPath = $logFiles[0];
                }
            }
            
            // Fallback to default log path
            if ($logPath === null) {
                $logPath = $logsDir . '/mailer.log';
            }
            
            if (!file_exists($logPath)) {
                echo json_encode(['success' => true, 'logs' => 'No logs found. Send an email to initialize logging.']);
                exit;
            }
            $lines = file($logPath);
            $lastLines = array_slice($lines, -100);
            echo json_encode(['success' => true, 'logs' => implode('', $lastLines)]);
            exit;
        }

        if ($_GET['action'] === 'process_queue') {
            // Add some dummy queued emails for demonstration if empty
            if ($mailer->getQueueSize() === 0) {
                $mailer->enqueue(
                    (new Message())
                        ->to('demo-queue-1@example.com', 'Demo User 1')
                        ->subject('Queued Message #1')
                        ->text('This was sent from the background queue.')
                );
                $mailer->enqueue(
                    (new Message())
                        ->to('demo-queue-2@example.com', 'Demo User 2')
                        ->subject('Queued Message #2')
                        ->text('This was sent from the background queue.')
                );
            }
            
            $results = $mailer->processQueue();
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        }

        if ($_GET['action'] === 'send_bulk') {
            // Parse JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($input === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
                exit;
            }
            
            // Required fields
            if (empty($input['recipients']) || !is_array($input['recipients'])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Recipients array is required']);
                exit;
            }
            
            if (empty($input['subject'])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Subject is required']);
                exit;
            }
            
            if (empty($input['html'])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'HTML body is required']);
                exit;
            }
            
            $recipients = $input['recipients'];
            $subject = $input['subject'];
            $html = $input['html'];
            $text = $input['text'] ?? null;
            $delay = (int)($input['delay'] ?? 200);
            
            // Build messages for each recipient
            $messages = [];
            foreach ($recipients as $recipient) {
                $email = $recipient['email'] ?? null;
                $name = $recipient['name'] ?? null;
                
                if (empty($email)) {
                    continue;
                }
                
                // Replace template variables
                $personalizedHtml = $html;
                $personalizedText = $text;
                
                // Simple variable substitution: {{ name }}, {{ email }}, etc.
                foreach ($recipient as $key => $value) {
                    if (is_string($value)) {
                        $personalizedHtml = str_replace('{{ ' . $key . ' }}', htmlspecialchars($value), $personalizedHtml);
                        if ($personalizedText) {
                            $personalizedText = str_replace('{{ ' . $key . ' }}', $value, $personalizedText);
                        }
                    }
                }
                
                $message = (new Message())
                    ->to($email, $name)
                    ->subject($subject)
                    ->html($personalizedHtml);
                
                if ($personalizedText) {
                    $message->text($personalizedText);
                }
                
                $messages[] = $message;
            }
            
            if (empty($messages)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'No valid recipients found']);
                exit;
            }
            
            // Send bulk emails
            $results = $mailer->sendBulkEmails($messages, $delay);
            
            // Calculate success/failure
            $successCount = count(array_filter($results, fn($r) => $r['success'] ?? false));
            $failureCount = count($results) - $successCount;
            
            echo json_encode([
                'success' => true,
                'total' => count($results),
                'successful' => $successCount,
                'failed' => $failureCount,
                'results' => $results,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle Configuration Form Save
$saveSuccess = false;
$saveError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    try {
        $fields = [
            'CLOUDFLARE_ACCOUNT_ID' => trim($_POST['cf_account_id'] ?? ''),
            'CLOUDFLARE_API_TOKEN' => trim($_POST['cf_api_token'] ?? ''),
            'CLOUDFLARE_FROM_EMAIL' => trim($_POST['cf_from_email'] ?? ''),
            'CLOUDFLARE_FROM_NAME' => trim($_POST['cf_from_name'] ?? ''),
            'MAILER_TIMEOUT' => (int)($_POST['mailer_timeout'] ?? 30),
            'MAILER_RETRY_ATTEMPTS' => (int)($_POST['mailer_retries'] ?? 3),
            'MAILER_LOG_LEVEL' => trim($_POST['mailer_log_level'] ?? 'debug'),
        ];

        // Format .env contents
        $envContent = "# Cloudflare Email Service Configuration\n";
        foreach ($fields as $key => $val) {
            if (is_int($val)) {
                $envContent .= "{$key}={$val}\n";
            } else {
                $envContent .= "{$key}=\"{$val}\"\n";
            }
        }
        $envContent .= "\n# Mailer Settings\n";
        $envContent .= "MAILER_RETRY_DELAY=1000\n";
        $envContent .= "MAILER_LOG_PATH=\"./logs/mailer.log\"\n";
        $envContent .= "MAILER_RATE_LIMIT_PER_SECOND=10\n";
        $envContent .= "MAILER_RATE_LIMIT_PER_MINUTE=100\n";
        $envContent .= "MAILER_QUEUE_ENABLED=false\n";

        if (file_put_contents($envFile, $envContent) !== false) {
            $saveSuccess = true;
            // Reload environment
            $_ENV = array_merge($_ENV, $fields);
            foreach ($fields as $k => $v) {
                putenv("{$k}={$v}");
            }
        } else {
            throw new \RuntimeException('Failed to write to .env file. Please check folder permissions.');
        }
    } catch (\Throwable $e) {
        $saveError = $e->getMessage();
    }
}

// Handle Sending Email Form
$sendResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    try {
        $mailer = CloudflareMailer::create($envPath);
        
        $message = new Message();
        $message->subject(trim($_POST['subject'] ?? ''));
        
        // Add multiple recipients (supports comma-separated)
        $toRaw = trim($_POST['to'] ?? '');
        $recipients = array_filter(array_map('trim', explode(',', $toRaw)));
        foreach ($recipients as $recipient) {
            if (preg_match('/^([^<]+)<([^>]+)>$/', $recipient, $matches)) {
                $message->to(trim($matches[2]), trim($matches[1]));
            } else {
                $message->to($recipient);
            }
        }

        // CC & BCC
        if (!empty($_POST['cc'])) {
            $ccList = array_filter(array_map('trim', explode(',', $_POST['cc'])));
            foreach ($ccList as $cc) {
                $message->cc($cc);
            }
        }
        if (!empty($_POST['bcc'])) {
            $bccList = array_filter(array_map('trim', explode(',', $_POST['bcc'])));
            foreach ($bccList as $bcc) {
                $message->bcc($bcc);
            }
        }

        // Reply-To
        if (!empty($_POST['reply_to'])) {
            $message->replyTo(trim($_POST['reply_to']));
        }

        // Headers
        if (!empty($_POST['custom_header_name']) && !empty($_POST['custom_header_val'])) {
            $message->header(trim($_POST['custom_header_name']), trim($_POST['custom_header_val']));
        }

        // Content body
        $bodyType = $_POST['body_type'] ?? 'html';
        if ($bodyType === 'html') {
            $message->html($_POST['html_body'] ?? '');
            if (!empty($_POST['text_body'])) {
                $message->text($_POST['text_body']);
            }
        } else {
            $message->text($_POST['text_body'] ?? '');
        }

        // Attachments
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpPath = $_FILES['attachments']['tmp_name'][$i];
                    $name = $_FILES['attachments']['name'][$i];
                    $type = $_FILES['attachments']['type'][$i];
                    $message->addAttachment(Attachment::fromFile($tmpPath, $name, $type));
                }
            }
        }

        // Check if queue or immediate send
        if (isset($_POST['queue_email']) && $_POST['queue_email'] === '1') {
            $queueId = $mailer->enqueue($message);
            $sendResult = [
                'success' => true,
                'message' => "Email successfully queued with Item ID: <strong>{$queueId}</strong>. You can trigger processing under the Queue tab."
            ];
        } else {
            $res = $mailer->sendEmail($message);
            $sendResult = [
                'success' => true,
                'message' => 'Email sent successfully!',
                'details' => $res
            ];
        }
    } catch (\Throwable $e) {
        $sendResult = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

// Get current credentials
$cfAccountId = $_ENV['CLOUDFLARE_ACCOUNT_ID'] ?? getenv('CLOUDFLARE_ACCOUNT_ID') ?: '';
$cfApiToken = $_ENV['CLOUDFLARE_API_TOKEN'] ?? getenv('CLOUDFLARE_API_TOKEN') ?: '';
$cfFromEmail = $_ENV['CLOUDFLARE_FROM_EMAIL'] ?? getenv('CLOUDFLARE_FROM_EMAIL') ?: '';
$cfFromName = $_ENV['CLOUDFLARE_FROM_NAME'] ?? getenv('CLOUDFLARE_FROM_NAME') ?: '';
$mailerTimeout = $_ENV['MAILER_TIMEOUT'] ?? getenv('MAILER_TIMEOUT') ?: 30;
$mailerRetries = $_ENV['MAILER_RETRY_ATTEMPTS'] ?? getenv('MAILER_RETRY_ATTEMPTS') ?: 3;
$mailerLogLevel = $_ENV['MAILER_LOG_LEVEL'] ?? getenv('MAILER_LOG_LEVEL') ?: 'debug';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloudflare Email Mailer Console</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>✉️</text></svg>">
    <!-- Modern Premium Font & CSS Reset -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-base: #0b0f19;
            --bg-surface: rgba(17, 24, 39, 0.7);
            --bg-surface-solid: #111827;
            --border-glow: rgba(99, 102, 241, 0.15);
            --accent-primary: #6366f1;
            --accent-primary-hover: #4f46e5;
            --accent-secondary: #ec4899;
            --accent-success: #10b981;
            --accent-warning: #f59e0b;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --font-display: 'Outfit', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 0%, rgba(236, 72, 153, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.05) 0px, transparent 50%);
            background-attachment: fixed;
            font-family: var(--font-body);
            color: var(--text-main);
            min-height: 100vh;
            line-height: 1.6;
        }

        header {
            background: rgba(17, 24, 39, 0.4);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1.25rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
        }

        .logo i {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2rem;
        }

        .logo span {
            font-family: var(--font-display);
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            background: linear-gradient(to right, #ffffff, #d1d5db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .badge {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: var(--accent-primary);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        main {
            max-width: 1400px;
            margin: 2.5rem auto;
            padding: 0 2rem;
        }

        /* Tabs Navigation */
        .tabs {
            display: flex;
            gap: 0.75rem;
            background: rgba(17, 24, 39, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 0.5rem;
            border-radius: 14px;
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .tab-btn {
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-muted);
            font-family: var(--font-display);
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.03);
        }

        .tab-btn.active {
            color: #ffffff;
            background: var(--accent-primary);
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
        }

        /* Glass Cards */
        .card {
            background: var(--bg-surface);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
            display: none;
        }

        .card.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }

        .card-header {
            margin-bottom: 2rem;
        }

        .card-title {
            font-family: var(--font-display);
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Forms styling */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-grid.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.col-span-2 {
            grid-column: span 2;
        }

        label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        select,
        textarea {
            background: rgba(10, 15, 30, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            color: #ffffff;
            font-family: var(--font-body);
            font-size: 0.95rem;
            transition: all 0.25s ease;
            outline: none;
            width: 100%;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            background: rgba(10, 15, 30, 0.9);
        }

        textarea {
            min-height: 150px;
            resize: vertical;
        }

        /* File Upload */
        .file-upload {
            position: relative;
            border: 2px dashed rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: rgba(10, 15, 30, 0.3);
            cursor: pointer;
            transition: border-color 0.25s ease;
        }

        .file-upload:hover {
            border-color: var(--accent-primary);
        }

        .file-upload input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-upload i {
            font-size: 2.5rem;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        /* Buttons styling */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            background: var(--accent-primary);
            color: #ffffff;
            border: none;
            outline: none;
            border-radius: 10px;
            padding: 0.85rem 1.75rem;
            font-family: var(--font-display);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
        }

        .btn:hover {
            background: var(--accent-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            box-shadow: none;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            box-shadow: none;
        }

        .btn-success {
            background: var(--accent-success);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: #059669;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        /* Alerts */
        .alert {
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            font-size: 0.95rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #34d399;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }

        .alert i {
            font-size: 1.25rem;
            margin-top: 0.1rem;
        }

        /* Logs Console */
        .console {
            background: #05070f;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            font-family: 'Courier New', Courier, monospace;
            padding: 1.5rem;
            min-height: 400px;
            max-height: 600px;
            overflow-y: auto;
            color: #34d399;
            white-space: pre-wrap;
            font-size: 0.9rem;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
            font-size: 0.85rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            margin-top: 5rem;
        }

        footer a {
            color: var(--accent-primary);
            text-decoration: none;
        }

        /* Responsive styling */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.col-span-2 {
                grid-column: span 1;
            }
            main {
                padding: 0 1rem;
            }
            .card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>

    <header>
        <div class="header-container">
            <a href="#" class="logo">
                <i class="fa-solid fa-paper-plane"></i>
                <span>CfMailer Console</span>
            </a>
            <div class="badge">Cloudflare API Active</div>
        </div>
    </header>

    <main>
        
        <!-- Tab Navigation Links -->
        <nav class="tabs">
            <button class="tab-btn active" onclick="switchTab(event, 'tab-send')">
                <i class="fa-solid fa-envelope"></i> Send Email
            </button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-bulk')">
                <i class="fa-solid fa-mail-bulk"></i> Bulk Send
            </button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-queue')">
                <i class="fa-solid fa-list-check"></i> Queue Manager
            </button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-logs')" id="logs-tab-btn">
                <i class="fa-solid fa-terminal"></i> Activity Logs
            </button>
            <button class="tab-btn" onclick="switchTab(event, 'tab-config')">
                <i class="fa-solid fa-sliders"></i> Configuration
            </button>
        </nav>

        <!-- Configuration Notice if empty -->
        <?php if (empty($cfAccountId) || empty($cfApiToken)): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    <strong>Configuration Required:</strong> Please verify your Cloudflare Account ID and API Token in the <strong>Configuration</strong> tab before sending emails.
                </div>
            </div>
        <?php endif; ?>

        <!-- Send Email Card -->
        <div id="tab-send" class="card active">
            <div class="card-header">
                <h2 class="card-title">Compose Email</h2>
                <p class="card-subtitle">Dispatch beautiful plain text or HTML emails through Cloudflare REST API securely.</p>
            </div>

            <?php if ($sendResult !== null): ?>
                <div class="alert <?= $sendResult['success'] ? 'alert-success' : 'alert-danger' ?>">
                    <i class="fa-solid <?= $sendResult['success'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                    <div>
                        <?= $sendResult['message'] ?>
                        <?php if (isset($sendResult['details']['message_id'])): ?>
                            <br><small>Message ID: <?= htmlspecialchars($sendResult['details']['message_id']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group col-span-2">
                        <label for="to">Recipient(s) <span style="color:var(--accent-secondary)">*</span></label>
                        <input type="text" name="to" id="to" placeholder="john@example.com or John Doe <john@example.com> (comma-separate multiple)" required>
                    </div>

                    <div class="form-group">
                        <label for="cc">CC Recipients</label>
                        <input type="text" name="cc" id="cc" placeholder="cc@example.com (comma-separated)">
                    </div>

                    <div class="form-group">
                        <label for="bcc">BCC Recipients</label>
                        <input type="text" name="bcc" id="bcc" placeholder="bcc@example.com (comma-separated)">
                    </div>

                    <div class="form-group">
                        <label for="reply_to">Reply-To Address</label>
                        <input type="email" name="reply_to" id="reply_to" placeholder="support@yourdomain.com">
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject Line <span style="color:var(--accent-secondary)">*</span></label>
                        <input type="text" name="subject" id="subject" placeholder="Welcome aboard!" required>
                    </div>

                    <div class="form-group">
                        <label for="custom_header_name">Custom Header Name</label>
                        <input type="text" name="custom_header_name" id="custom_header_name" placeholder="X-Campaign-ID">
                    </div>

                    <div class="form-group">
                        <label for="custom_header_val">Custom Header Value</label>
                        <input type="text" name="custom_header_val" id="custom_header_val" placeholder="signup-flow-2025">
                    </div>
                </div>

                <div class="form-grid full">
                    <div class="form-group">
                        <label for="body_type">Email Body Format</label>
                        <select name="body_type" id="body_type" onchange="toggleBodyFormat()">
                            <option value="html" selected>HTML Body (Highly Recommended)</option>
                            <option value="text">Plain Text Only</option>
                        </select>
                    </div>

                    <div class="form-group" id="html-editor-group">
                        <label for="html_body">HTML Content</label>
                        <textarea name="html_body" id="html_body" placeholder="<div style='font-family: Arial;'><h2>Welcome!</h2><p>Glad you signed up.</p></div>"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="text_body">Plain Text Body (Fallback or Text Body)</label>
                        <textarea name="text_body" id="text_body" placeholder="Hello there! Glad you signed up."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Attachments</label>
                        <div class="file-upload">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Drag & drop or click to upload files (PDFs, Images, CSVs, etc.)</p>
                            <input type="file" name="attachments[]" multiple id="attachment-input" onchange="updateFileNames()">
                            <div id="file-list" style="margin-top: 1rem; font-weight: 600; color: var(--accent-success);"></div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" name="send_email" class="btn">
                        <i class="fa-solid fa-paper-plane"></i> Send Immediately
                    </button>
                    <button type="submit" name="send_email" class="btn btn-secondary" onclick="document.getElementById('queue_email_flag').value = '1';">
                        <i class="fa-solid fa-clock"></i> Queue Email
                    </button>
                    <input type="hidden" name="queue_email" id="queue_email_flag" value="0">
                </div>
            </form>
        </div>

        <!-- Bulk Send Card -->
        <div id="tab-bulk" class="card">
            <div class="card-header">
                <h2 class="card-title">Bulk Email Dispatcher</h2>
                <p class="card-subtitle">Dispatch structured bulk email marketing or notification campaigns safely with anti-rate-limit delay gating.</p>
            </div>

            <div class="alert alert-success" style="display:none;" id="bulk-alert">
                <i class="fa-solid fa-circle-info"></i>
                <div id="bulk-alert-text">Bulk send in progress...</div>
            </div>

            <form id="bulk-form" onsubmit="runBulkSend(event)">
                <div class="form-grid full">
                    <div class="form-group">
                        <label for="bulk_to">Recipients List (one per line, format: email or Name &lt;email&gt;)</label>
                        <textarea name="bulk_to" id="bulk_to" placeholder="alice@example.com&#10;Bob <bob@example.com>&#10;charlie@example.com" required style="min-height: 120px;"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="bulk_subject">Campaign Subject Line</label>
                        <input type="text" name="bulk_subject" id="bulk_subject" placeholder="Exclusive Member Updates" required>
                    </div>

                    <div class="form-group">
                        <label for="bulk_template">HTML Template Body</label>
                        <textarea name="bulk_template" id="bulk_template" placeholder="<div style='font-family: Arial;'><h2>Hello {{ name }},</h2><p>Welcome to our exclusive list!</p></div>" required style="min-height: 200px;"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="bulk_delay">Gated Delay Between Sends (Milliseconds)</label>
                        <input type="number" name="bulk_delay" id="bulk_delay" value="200" min="50" max="5000">
                    </div>
                </div>

                <button type="submit" class="btn btn-success" style="margin-top: 1.5rem;" id="bulk-submit-btn">
                    <i class="fa-solid fa-bolt"></i> Dispatch Campaign
                </button>
            </form>
        </div>

        <!-- Queue Manager Card -->
        <div id="tab-queue" class="card">
            <div class="card-header">
                <h2 class="card-title">Queue Manager</h2>
                <p class="card-subtitle">View and execute queued background email workflows securely.</p>
            </div>

            <div class="alert alert-success" style="display:none;" id="queue-alert">
                <i class="fa-solid fa-circle-check"></i>
                <div id="queue-alert-text"></div>
            </div>

            <div class="form-grid full">
                <div style="background: rgba(10, 15, 30, 0.4); border-radius: 12px; padding: 2rem; border: 1px solid rgba(255, 255, 255, 0.05); margin-bottom: 2rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem;">
                        <div>
                            <div style="font-size: 1.25rem; font-weight:700;">Local Sandbox Queue</div>
                            <div style="color:var(--text-muted); font-size:0.9rem;">Runs inside local memory. Process queue below or bind with your own Redis/Database driver.</div>
                        </div>
                        <div style="font-size: 2rem; font-weight:800; color:var(--accent-primary);" id="queue-count">0</div>
                    </div>
                    
                    <div style="display:flex; gap: 1rem;">
                        <button type="button" class="btn" onclick="processQueue()">
                            <i class="fa-solid fa-play"></i> Process Queue Now
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="addMockQueue()">
                            <i class="fa-solid fa-plus"></i> Load Demo Queue Items
                        </button>
                    </div>
                </div>

                <div>
                    <h3 style="font-family: var(--font-display); font-size: 1.25rem; margin-bottom: 1rem;">Queue Execution Output</h3>
                    <div class="console" id="queue-console">Queue is currently idle. Click "Process Queue Now" or queue an email.</div>
                </div>
            </div>
        </div>

        <!-- Activity Logs Card -->
        <div id="tab-logs" class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h2 class="card-title">Activity Logs</h2>
                    <p class="card-subtitle">Real-time status updates from the Monolog rotating log channel.</p>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="fetchLogs()">
                    <i class="fa-solid fa-rotate"></i> Refresh Logs
                </button>
            </div>

            <div class="console" id="log-console">Loading latest log records...</div>
        </div>

        <!-- Configuration Card -->
        <div id="tab-config" class="card">
            <div class="card-header">
                <h2 class="card-title">Cloudflare Service Configuration</h2>
                <p class="card-subtitle">Securely verify your Account ID, verified Sender email, and API authentication tokens.</p>
            </div>

            <?php if ($saveSuccess): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <div>
                        Settings saved successfully to `.env` file!
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($saveError !== null): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <div>
                        <?= htmlspecialchars($saveError) ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-grid">
                    <div class="form-group col-span-2">
                        <label for="cf_account_id">Cloudflare Account ID <span style="color:var(--accent-secondary)">*</span></label>
                        <input type="text" name="cf_account_id" id="cf_account_id" value="<?= htmlspecialchars($cfAccountId) ?>" required placeholder="e.g. 1a2b3c4d5e6f7g8h9i0j...">
                    </div>

                    <div class="form-group col-span-2">
                        <label for="cf_api_token">Cloudflare API Token <span style="color:var(--accent-secondary)">*</span></label>
                        <input type="text" name="cf_api_token" id="cf_api_token" value="<?= htmlspecialchars($cfApiToken) ?>" required placeholder="e.g. cfat_...">
                    </div>

                    <div class="form-group">
                        <label for="cf_from_email">Verified From Email Address <span style="color:var(--accent-secondary)">*</span></label>
                        <input type="email" name="cf_from_email" id="cf_from_email" value="<?= htmlspecialchars($cfFromEmail) ?>" required placeholder="noreply@yourdomain.com">
                    </div>

                    <div class="form-group">
                        <label for="cf_from_name">Verified From Name</label>
                        <input type="text" name="cf_from_name" id="cf_from_name" value="<?= htmlspecialchars($cfFromName) ?>" placeholder="Acme Inc">
                    </div>

                    <div class="form-group">
                        <label for="mailer_timeout">API Call Timeout (Seconds)</label>
                        <input type="number" name="mailer_timeout" id="mailer_timeout" value="<?= (int)$mailerTimeout ?>" min="1" max="120">
                    </div>

                    <div class="form-group">
                        <label for="mailer_retries">API Max Retry Attempts</label>
                        <input type="number" name="mailer_retries" id="mailer_retries" value="<?= (int)$mailerRetries ?>" min="0" max="10">
                    </div>

                    <div class="form-group">
                        <label for="mailer_log_level">Monolog Level</label>
                        <select name="mailer_log_level" id="mailer_log_level">
                            <option value="debug" <?= $mailerLogLevel === 'debug' ? 'selected' : '' ?>>Debug (Recommended)</option>
                            <option value="info" <?= $mailerLogLevel === 'info' ? 'selected' : '' ?>>Info</option>
                            <option value="warning" <?= $mailerLogLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                            <option value="error" <?= $mailerLogLevel === 'error' ? 'selected' : '' ?>>Error</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="save_config" class="btn" style="margin-top: 1rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Settings
                </button>
            </form>
        </div>

    </main>

    <footer>
        <p>CfMailer Console &copy; 2026. Built on PHP 8.4 & Cloudflare REST API infrastructure.</p>
    </footer>

    <script>
        function switchTab(evt, tabId) {
            // Hide all cards
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => card.classList.remove('active'));

            // Deactivate all tab buttons
            const tabButtons = document.querySelectorAll('.tab-btn');
            tabButtons.forEach(btn => btn.classList.remove('active'));

            // Show active card and button
            document.getElementById(tabId).classList.add('active');
            evt.currentTarget.classList.add('active');

            // Hook for loading logs when clicking logs tab
            if (tabId === 'tab-logs') {
                fetchLogs();
            }
        }

        function toggleBodyFormat() {
            const format = document.getElementById('body_type').value;
            const htmlGroup = document.getElementById('html-editor-group');
            if (format === 'html') {
                htmlGroup.style.display = 'flex';
            } else {
                htmlGroup.style.display = 'none';
            }
        }

        function updateFileNames() {
            const input = document.getElementById('attachment-input');
            const fileList = document.getElementById('file-list');
            fileList.innerHTML = '';
            
            if (input.files.length > 0) {
                const names = Array.from(input.files).map(file => `<i class="fa-solid fa-paperclip"></i> ${file.name} (${(file.size / 1024).toFixed(1)} KB)`);
                fileList.innerHTML = names.join('<br>');
            }
        }

        function fetchLogs() {
            const consoleEl = document.getElementById('log-console');
            consoleEl.textContent = 'Updating live log output stream...';
            
            fetch('?action=get_logs')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        consoleEl.textContent = data.logs;
                        consoleEl.scrollTop = consoleEl.scrollHeight;
                    } else {
                        consoleEl.textContent = 'Error fetching logs: ' + data.error;
                    }
                })
                .catch(err => {
                    consoleEl.textContent = 'Error contacting log endpoint: ' + err.message;
                });
        }

        let mockQueueCount = 0;
        function addMockQueue() {
            mockQueueCount += 2;
            document.getElementById('queue-count').textContent = mockQueueCount;
            
            const consoleEl = document.getElementById('queue-console');
            consoleEl.innerHTML += `\n[DEMO] Added 2 demo items to sandbox queue. Ready to process.`;
            consoleEl.scrollTop = consoleEl.scrollHeight;
        }

        function processQueue() {
            const consoleEl = document.getElementById('queue-console');
            const alertEl = document.getElementById('queue-alert');
            const alertText = document.getElementById('queue-alert-text');
            
            consoleEl.textContent = 'Triggering background queue execution pipeline...\n';
            
            fetch('?action=process_queue')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        mockQueueCount = 0;
                        document.getElementById('queue-count').textContent = '0';
                        
                        consoleEl.textContent += JSON.stringify(data.results, null, 2);
                        alertText.innerHTML = 'Queue processed successfully! All emails dispatched.';
                        alertEl.style.display = 'flex';
                        
                        setTimeout(() => {
                            alertEl.style.display = 'none';
                        }, 5000);
                    } else {
                        consoleEl.textContent += '\nQueue processing failure: ' + data.error;
                    }
                })
                .catch(err => {
                    consoleEl.textContent += '\nError contacting queue endpoint: ' + err.message;
                });
        }

        function runBulkSend(event) {
            event.preventDefault();
            const btn = document.getElementById('bulk-submit-btn');
            const alertEl = document.getElementById('bulk-alert');
            const alertText = document.getElementById('bulk-alert-text');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending Campaign...';
            
            alertText.textContent = 'Preparing campaign emails...';
            alertEl.style.display = 'flex';
            alertEl.className = 'alert alert-success';

            // Parse recipients from textarea
            const rawRecipients = document.getElementById('bulk_to').value.trim().split('\n');
            const recipients = [];
            
            rawRecipients.forEach(line => {
                line = line.trim();
                if (!line) return;
                
                // Check for "Name <email>" format
                const match = line.match(/^([^<]+)\s*<([^>]+)>$/);
                if (match) {
                    recipients.push({
                        name: match[1].trim(),
                        email: match[2].trim()
                    });
                } else {
                    recipients.push({
                        email: line
                    });
                }
            });

            const payload = {
                recipients: recipients,
                subject: document.getElementById('bulk_subject').value,
                html: document.getElementById('bulk_template').value,
                delay: parseInt(document.getElementById('bulk_delay').value) || 200
            };

            // Call the backend API
            fetch('?action=send_bulk', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alertText.innerHTML = `<strong>Campaign Complete!</strong><br>Sent: ${data.successful}, Failed: ${data.failed}`;
                    alertEl.className = 'alert alert-success';
                } else {
                    alertText.innerHTML = `<strong>Failed:</strong> ${data.error || 'Unknown error'}`;
                    alertEl.className = 'alert alert-danger';
                }
            })
            .catch(err => {
                alertText.innerHTML = `<strong>Error:</strong> ${err.message}`;
                alertEl.className = 'alert alert-danger';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Dispatch Campaign';
            });
        }

        // Initialize state
        toggleBodyFormat();
    </script>
</body>
</html>
