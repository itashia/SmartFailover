<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use DateTimeInterface;
use DateInterval;

final class QueueFailoverManager
{
    private const DEFAULT_RETRY_ATTEMPTS = 3;
    private const DEFAULT_RETRY_DELAY_MS = 2000;
    private const HEALTH_STATUS_HEALTHY = 'healthy';
    private const HEALTH_STATUS_UNHEALTHY = 'unhealthy';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Set queue connections for failover.
     */
    public function setConnections(array $connections): void
    {
        $this->connections = $connections;
    }

    /**
     * Execute queue operation with failover.
     */
    public function execute(Closure $callback, ?array $connections = null): mixed
    {
        $connections ??= $this->connections;
        $this->validateConnections($connections);

        [$primary, $fallback] = $this->extractConnections($connections);

        try {
            return $this->executeWithRetry($callback, $primary);
        } catch (Throwable $primaryException) {
            $this->logConnectionFailure('Primary queue connection failed', $primary, $primaryException);
            
            return $this->handlePrimaryFailure($callback, $fallback, $primaryException);
        }
    }

    /**
     * Validate connection configuration.
     */
    private function validateConnections(array $connections): void
    {
        if (empty($connections['primary'])) {
            throw new InvalidArgumentException('Primary queue connection must be specified');
        }
    }

    /**
     * Extract primary and fallback connections.
     */
    private function extractConnections(array $connections): array
    {
        return [
            $connections['primary'],
            $connections['fallback'] ?? null
        ];
    }

    /**
     * Handle primary connection failure.
     */
    private function handlePrimaryFailure(Closure $callback, ?string $fallback, Throwable $primaryException): mixed
    {
        if ($fallback === null) {
            throw $primaryException;
        }

        try {
            $this->logger->info('Switching to fallback queue connection', [
                'fallback_connection' => $fallback,
            ]);

            return $this->executeWithRetry($callback, $fallback);
        } catch (Throwable $fallbackException) {
            $this->logConnectionFailure('Fallback queue connection also failed', $fallback, $fallbackException);
            throw $fallbackException;
        }
    }

    /**
     * Execute callback with retry logic on specific connection.
     */
    private function executeWithRetry(Closure $callback, string $connection): mixed
    {
        $attempts = 0;
        $maxAttempts = $this->getRetryAttempts();
        $baseDelay = $this->getRetryDelay();
        $useExponentialBackoff = $this->useExponentialBackoff();

        do {
            try {
                $queueConnection = Queue::connection($connection);
                return $callback($queueConnection);
            } catch (Throwable $e) {
                $attempts++;
                
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }

                $currentDelay = $this->calculateRetryDelay($baseDelay, $attempts, $useExponentialBackoff);
                $this->logRetryAttempt($connection, $attempts, $maxAttempts, $currentDelay);
                $this->waitBeforeRetry($currentDelay);
            }
        } while ($attempts < $maxAttempts);

        throw new RuntimeException('Maximum retry attempts exceeded');
    }

    /**
     * Calculate retry delay with exponential backoff if enabled.
     */
    private function calculateRetryDelay(int $baseDelay, int $attempt, bool $useExponentialBackoff): int
    {
        return $useExponentialBackoff 
            ? $baseDelay * (2 ** ($attempt - 1))
            : $baseDelay;
    }

    /**
     * Get retry attempts from config.
     */
    private function getRetryAttempts(): int
    {
        return $this->config->get('smart-failover.queue.retry_attempts', self::DEFAULT_RETRY_ATTEMPTS);
    }

    /**
     * Get retry delay from config.
     */
    private function getRetryDelay(): int
    {
        return $this->config->get('smart-failover.queue.retry_delay', self::DEFAULT_RETRY_DELAY_MS);
    }

    /**
     * Check if exponential backoff is enabled.
     */
    private function useExponentialBackoff(): bool
    {
        return $this->config->get('smart-failover.queue.exponential_backoff', true);
    }

    /**
     * Log connection failure.
     */
    private function logConnectionFailure(string $message, string $connection, Throwable $exception): void
    {
        $this->logger->warning($message, [
            'connection' => $connection,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Log retry attempt.
     */
    private function logRetryAttempt(string $connection, int $attempt, int $maxAttempts, int $delayMs): void
    {
        $this->logger->debug('Retrying queue operation', [
            'connection' => $connection,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'delay_ms' => $delayMs,
        ]);
    }

    /**
     * Wait before retry attempt.
     */
    private function waitBeforeRetry(int $delayMs): void
    {
        usleep($delayMs * 1000);
    }

    /**
     * Push job to queue with failover.
     */
    public function push(string $job, array $data = [], ?string $queue = null): mixed
    {
        return $this->execute(
            fn($queueConnection) => $queueConnection->push($job, $data, $queue)
        );
    }

    /**
     * Push job to queue later with failover.
     */
    public function later(DateTimeInterface|DateInterval|int $delay, string $job, array $data = [], ?string $queue = null): mixed
    {
        return $this->execute(
            fn($queueConnection) => $queueConnection->later($delay, $job, $data, $queue)
        );
    }

    /**
     * Bulk push jobs to queue with failover.
     */
    public function bulk(array $jobs, array $data = [], ?string $queue = null): mixed
    {
        return $this->execute(
            fn($queueConnection) => $queueConnection->bulk($jobs, $data, $queue)
        );
    }

    /**
     * Get queue size with failover.
     */
    public function size(?string $queue = null): int
    {
        try {
            return $this->execute(
                fn($queueConnection) => $queueConnection->size($queue)
            ) ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get queue size', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }
    }

    /**
     * Check health of queue connections.
     */
    public function checkHealth(?array $connections = null): array
    {
        $connections ??= $this->connections;
        $results = [];

        foreach ($connections as $name => $connection) {
            if (empty($connection)) {
                continue;
            }

            $results[$name] = $this->checkConnectionHealth($connection);
        }

        return $results;
    }

    /**
     * Check health of a single queue connection.
     */
    private function checkConnectionHealth(string $connection): array
    {
        $startTime = microtime(true);

        try {
            $queueConnection = Queue::connection($connection);
            $size = $queueConnection->size();
            $responseTime = $this->calculateResponseTime($startTime);

            return $this->createHealthResult(
                $connection,
                self::HEALTH_STATUS_HEALTHY,
                $responseTime,
                $size
            );
        } catch (Throwable $e) {
            return $this->createHealthResult(
                $connection,
                self::HEALTH_STATUS_UNHEALTHY,
                null,
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * Calculate response time in milliseconds.
     */
    private function calculateResponseTime(float $startTime): float
    {
        return (microtime(true) - $startTime) * 1000.0;
    }

    /**
     * Create standardized health result array.
     */
    private function createHealthResult(
        string $connection, 
        string $status, 
        ?float $responseTime = null, 
        ?int $queueSize = null,
        ?string $error = null
    ): array {
        $result = [
            'connection' => $connection,
            'status' => $status,
            'checked_at' => Carbon::now()->toISOString(),
        ];

        if ($responseTime !== null) {
            $result['response_time_ms'] = round($responseTime, 2);
        }

        if ($queueSize !== null) {
            $result['queue_size'] = $queueSize;
        }

        if ($error !== null) {
            $result['error'] = $error;
            $this->healthStatus[$connection] = false;
        } else {
            $this->healthStatus[$connection] = true;
        }

        return $result;
    }

    /**
     * Check if a specific connection is healthy.
     */
    public function isConnectionHealthy(string $connection): bool
    {
        return $this->healthStatus[$connection] ?? false;
    }

    /**
     * Get current health status.
     */
    public function getHealthStatus(): array
    {
        return $this->healthStatus;
    }

    /**
     * Get failed jobs count.
     */
    public function getFailedJobsCount(): int
    {
        try {
            return $this->execute(
                fn($queueConnection) => 0 // Placeholder for actual implementation
            ) ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get failed jobs count', [
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }
    }

    /**
     * Retry failed jobs.
     */
    public function retryFailedJobs(array $jobIds = []): bool
    {
        try {
            return $this->execute(
                fn($queueConnection) => true // Placeholder for actual retry logic
            ) ?? false;
        } catch (Throwable $e) {
            $this->logger->error('Failed to retry jobs', [
                'job_ids' => $jobIds,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
}
