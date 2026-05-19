<?php

declare(strict_types=1);

namespace CfMailer\Http;

use CfMailer\Config\MailerConfig;
use CfMailer\Exception\AuthenticationException;
use CfMailer\Exception\MailerException;
use CfMailer\Exception\RateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Low-level HTTP client for the Cloudflare Email Sending API.
 *
 * Handles authentication, request formatting, error mapping,
 * retry logic with exponential backoff, and rate-limit awareness.
 */
class CloudflareClient
{
    private readonly Client $httpClient;
    private readonly MailerConfig $config;
    private readonly LoggerInterface $logger;

    /**
     * Track requests for local rate limiting.
     *
     * @var float[] Timestamps of recent requests
     */
    private array $requestTimestamps = [];

    public function __construct(
        MailerConfig $config,
        LoggerInterface $logger,
        ?Client $httpClient = null
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => MailerConfig::API_BASE_URL,
            'timeout' => $config->getTimeout(),
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $config->getApiToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'CfMailer-PHP/1.0',
            ],
            'http_errors' => true,
            RequestOptions::VERIFY => true,
        ]);
    }

    /**
     * Send an email via the Cloudflare API with retry logic.
     *
     * @param array<string, mixed> $payload The email payload
     * @return array<string, mixed> The API response data
     *
     * @throws MailerException
     * @throws RateLimitException
     * @throws AuthenticationException
     */
    public function sendEmail(array $payload): array
    {
        $this->enforceLocalRateLimit();

        $endpoint = $this->config->getSendEndpoint();
        $maxRetries = $this->config->getRetryAttempts();
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($attempt > 0) {
                    $delay = $this->calculateBackoff($attempt);
                    $this->logger->info("Retry attempt {$attempt}/{$maxRetries}, waiting {$delay}ms");
                    usleep($delay * 1000);
                }

                $this->logger->debug('Sending email request', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt + 1,
                    'to_count' => count($payload['to'] ?? []),
                ]);

                $this->recordRequest();

                $response = $this->httpClient->post($endpoint, [
                    RequestOptions::JSON => $payload,
                ]);

                $body = $response->getBody()->getContents();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                $this->logger->info('Email sent successfully', [
                    'status' => $response->getStatusCode(),
                    'message_id' => $data['result']['id'] ?? 'unknown',
                ]);

                return [
                    'success' => true,
                    'status_code' => $response->getStatusCode(),
                    'data' => $data,
                    'message_id' => $data['result']['id'] ?? null,
                    'attempts' => $attempt + 1,
                ];
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($responseBody, true) ?? [];
                $errorMessage = $errorData['errors'][0]['message'] ?? $responseBody;

                if ($statusCode === 401) {
                    throw new AuthenticationException(
                        'Cloudflare API authentication failed. Verify your API token.',
                        $statusCode,
                        $e
                    );
                }

                if ($statusCode === 403) {
                    if (str_contains($errorMessage, 'sending_disabled')) {
                        throw new MailerException(
                            'Email sending is currently disabled for this domain in your Cloudflare dashboard settings.',
                            $statusCode,
                            $e,
                            ['response' => $errorData]
                        );
                    }
                    throw new AuthenticationException(
                        'Cloudflare API authentication failed. Permission denied or insufficient token scopes.',
                        $statusCode,
                        $e
                    );
                }

                return match ($statusCode) {
                    429 => $this->handleRateLimit($e, $errorData),
                    400, 422 => throw new MailerException(
                        'Invalid request: ' . $errorMessage,
                        $statusCode,
                        $e,
                        ['response' => $errorData]
                    ),
                    default => throw new MailerException(
                        "API client error ({$statusCode}): " . $errorMessage,
                        $statusCode,
                        $e,
                        ['response' => $errorData]
                    ),
                };
            } catch (ServerException $e) {
                $lastException = $e;
                $this->logger->warning("Server error on attempt {$attempt}", [
                    'status' => $e->getResponse()->getStatusCode(),
                    'message' => $e->getMessage(),
                ]);
                // Server errors are retryable – continue the loop
            } catch (ConnectException $e) {
                $lastException = $e;
                $this->logger->warning("Connection error on attempt {$attempt}", [
                    'message' => $e->getMessage(),
                ]);
                // Connection errors are retryable – continue the loop
            } catch (\JsonException $e) {
                throw new MailerException(
                    'Failed to parse API response: ' . $e->getMessage(),
                    500,
                    $e
                );
            }
        }

        throw new MailerException(
            "Failed to send email after {$maxRetries} retries: " . ($lastException?->getMessage() ?? 'Unknown error'),
            503,
            $lastException,
            ['retries_exhausted' => true]
        );
    }

    /**
     * Handle 429 rate-limit responses.
     *
     * @param array<string, mixed> $errorData
     * @throws RateLimitException Always
     * @return never
     */
    private function handleRateLimit(ClientException $e, array $errorData): never
    {
        $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 60);

        $this->logger->warning('Rate limit exceeded', [
            'retry_after' => $retryAfter,
            'response' => $errorData,
        ]);

        throw new RateLimitException(
            $retryAfter,
            'Cloudflare API rate limit exceeded. Retry after ' . $retryAfter . ' seconds.',
            429,
            $e,
            ['response' => $errorData]
        );
    }

    /**
     * Calculate exponential backoff delay in milliseconds.
     *
     * Uses jitter to prevent thundering herd on retries.
     */
    private function calculateBackoff(int $attempt): int
    {
        $baseDelay = $this->config->getRetryDelay();
        $maxDelay = 30000; // 30 seconds max

        // Exponential backoff: base * 2^attempt
        $delay = (int) min($baseDelay * pow(2, $attempt - 1), $maxDelay);

        // Add ±25% jitter
        $jitter = (int) ($delay * 0.25);
        $delay += random_int(-$jitter, $jitter);

        return max($delay, 100);
    }

    /**
     * Enforce local rate limiting before sending a request.
     *
     * Prevents exceeding the configured requests-per-second limit.
     */
    private function enforceLocalRateLimit(): void
    {
        $now = microtime(true);
        $windowSeconds = 1.0;

        // Prune timestamps older than the window
        $this->requestTimestamps = array_filter(
            $this->requestTimestamps,
            fn(float $ts) => ($now - $ts) < $windowSeconds
        );

        if (count($this->requestTimestamps) >= $this->config->getRateLimitPerSecond()) {
            $oldestInWindow = min($this->requestTimestamps);
            $sleepMs = (int) ceil(($oldestInWindow + $windowSeconds - $now) * 1000);

            if ($sleepMs > 0) {
                $this->logger->debug("Local rate limit: sleeping {$sleepMs}ms");
                usleep($sleepMs * 1000);
            }
        }
    }

    /**
     * Record a request timestamp for rate limiting.
     */
    private function recordRequest(): void
    {
        $this->requestTimestamps[] = microtime(true);
    }
}
