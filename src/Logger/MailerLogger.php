<?php

declare(strict_types=1);

namespace CfMailer\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

/**
 * Logger factory for the mailer service.
 *
 * Creates a configured Monolog instance with rotating file handlers
 * and a clean format optimized for email operation logging.
 */
final class MailerLogger
{
    private static ?LoggerInterface $instance = null;

    /**
     * Get or create the singleton logger instance.
     */
    public static function getInstance(
        string $logPath = './logs/mailer.log',
        string $level = 'debug'
    ): LoggerInterface {
        if (self::$instance === null) {
            self::$instance = self::create($logPath, $level);
        }

        return self::$instance;
    }

    /**
     * Create a fresh logger instance (useful for testing).
     */
    public static function create(
        string $logPath = './logs/mailer.log',
        string $level = 'debug'
    ): LoggerInterface {
        $logger = new Logger('cf-mailer');

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $monoLevel = Level::fromName($level);

        // Rotating daily log file (keeps 30 days)
        $format = "[%datetime%] %channel%.%level_name%: %message% %context%\n";
        $formatter = new LineFormatter($format, 'Y-m-d H:i:s', true, true);

        $fileHandler = new RotatingFileHandler($logPath, 30, $monoLevel);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // Also log errors to stderr in CLI mode
        if (PHP_SAPI === 'cli') {
            $stderrHandler = new StreamHandler('php://stderr', Level::Error);
            $stderrHandler->setFormatter($formatter);
            $logger->pushHandler($stderrHandler);
        }

        return $logger;
    }

    /**
     * Replace the singleton instance (useful for testing with a mock logger).
     */
    public static function setInstance(LoggerInterface $logger): void
    {
        self::$instance = $logger;
    }

    /**
     * Reset the singleton (for test teardown).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Create a null logger that discards all output (for silent tests).
     */
    public static function null(): LoggerInterface
    {
        return new \Psr\Log\NullLogger();
    }
}
