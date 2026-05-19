<?php

declare(strict_types=1);

namespace CfMailer\Queue;

use CfMailer\Email\Message;
use Psr\Log\LoggerInterface;

/**
 * Queue-ready architecture for email sending.
 *
 * Provides an in-memory queue and a serialization interface
 * that can be adapted to any queue backend (Redis, RabbitMQ,
 * database, SQS, etc.).
 *
 * Usage:
 *   1. Enqueue messages via push()
 *   2. Process them via process() with a callback
 *   3. Or serialize via toArray() for external queue systems
 */
final class EmailQueue
{
    /**
     * @var array<int, array{message: Message, priority: int, created_at: float, id: string}>
     */
    private array $queue = [];

    private readonly LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Add a message to the queue.
     *
     * @param int $priority Higher = processed first (default: 0)
     * @return string Unique queue item ID
     */
    public function push(Message $message, int $priority = 0): string
    {
        $id = bin2hex(random_bytes(16));

        $this->queue[] = [
            'message' => $message,
            'priority' => $priority,
            'created_at' => microtime(true),
            'id' => $id,
        ];

        // Sort by priority (descending), then by created_at (ascending)
        usort($this->queue, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            return $a['created_at'] <=> $b['created_at'];
        });

        $this->logger->debug("Queued email", [
            'id' => $id,
            'priority' => $priority,
            'queue_size' => count($this->queue),
        ]);

        return $id;
    }

    /**
     * Pop the next message from the queue.
     *
     * @return array{message: Message, priority: int, created_at: float, id: string}|null
     */
    public function pop(): ?array
    {
        if (empty($this->queue)) {
            return null;
        }

        return array_shift($this->queue);
    }

    /**
     * Process all queued messages with a callback.
     *
     * @param callable(Message, string): array<string, mixed> $sender Callback that sends the message
     * @return array<string, array<string, mixed>> Results keyed by queue item ID
     */
    public function process(callable $sender): array
    {
        $results = [];

        while ($item = $this->pop()) {
            $id = $item['id'];

            try {
                $this->logger->info("Processing queued email", ['id' => $id]);
                $result = $sender($item['message'], $id);
                $results[$id] = ['success' => true, 'result' => $result];
            } catch (\Throwable $e) {
                $this->logger->error("Failed to process queued email", [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $results[$id] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Get the current queue size.
     */
    public function size(): int
    {
        return count($this->queue);
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /**
     * Flush (clear) all queued messages.
     *
     * @return int Number of messages removed
     */
    public function flush(): int
    {
        $count = count($this->queue);
        $this->queue = [];
        $this->logger->info("Queue flushed", ['removed' => $count]);
        return $count;
    }

    /**
     * Serialize all queued items to arrays for external storage/transport.
     *
     * @return array<int, array{id: string, priority: int, created_at: float, payload: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return array_map(function (array $item): array {
            return [
                'id' => $item['id'],
                'priority' => $item['priority'],
                'created_at' => $item['created_at'],
                'payload' => $item['message']->toApiPayload(),
            ];
        }, $this->queue);
    }
}
