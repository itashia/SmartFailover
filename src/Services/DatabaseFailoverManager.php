<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DatabaseFailoverManager
{
    private const DEFAULT_RETRY_ATTEMPTS = 3;
    private const DEFAULT_RETRY_DELAY_MS = 1000;
    private const HEALTH_STATUS_HEALTHY = 'healthy';
    private const HEALTH_STATUS_UNHEALTHY = 'unhealthy';
    private const CONNECTION_TEST_QUERY = 'SELECT 1';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Set database connections for failover.
     */
    public function setConnections(array $connections): void
    {
        $this->connections = $connections;
    }

    /**
     * Execute database operation with failover.
     */
    public function execute(Closure $callback, ?array $connections = null): mixed
    {
        $connections ??= $this->connections;
        $this->validateConnections($connections);

        [$primary, $fallback] = $this->extractConnections($connections);

        try {
            return $this->executeWithRetry($callback, $primary);
        } catch (Throwable $primaryException) {
            $this->logConnectionFailure('Primary database connection failed', $primary, $primaryException);
            
            return $this->handlePrimaryFailure($callback, $fallback, $primaryException);
        }
    }

    /**
     * Validate connection configuration.
     */
    private function validateConnections(array $connections): void
    {
        if (empty($connections['primary'])) {
            throw new InvalidArgumentException('Primary database connection must be specified');
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
            $this->logger->info('Switching to fallback database connection', [
                'fallback_connection' => $fallback,
            ]);

            return $this->executeWithRetry($callback, $fallback);
        } catch (Throwable $fallbackException) {
            $this->logConnectionFailure('Fallback database connection also failed', $fallback, $fallbackException);
            
            return $this->handleCompleteFailure($primaryException, $fallbackException);
        }
    }

    /**
     * Handle complete connection failure with graceful degradation.
     */
    private function handleCompleteFailure(Throwable $primaryException, Throwable $fallbackException): mixed
    {
        $isGracefulDegradationEnabled = $this->config->get(
            'smart-failover.database.graceful_degradation', 
            true
        );

        if (!$isGracefulDegradationEnabled) {
            throw $fallbackException;
        }

        $this->logger->critical('All database connections failed, entering graceful degradation mode', [
            'primary_error' => $primaryException->getMessage(),
            'fallback_error' => $fallbackException->getMessage(),
        ]);

        // Return null for graceful degradation - application continues with limited functionality
        return null;
    }

    /**
     * Execute callback with retry logic on specific connection.
     */
    private function executeWithRetry(Closure $callback, string $connection): mixed
    {
        $attempts = 0;
        $maxAttempts = $this->getRetryAttempts();
        $retryDelay = $this->getRetryDelay();

        do {
            try {
                DB::setDefaultConnection($connection);
                return $callback();
            } catch (QueryException $e) {
                $attempts++;
                
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }

                $this->logRetryAttempt($connection, $attempts, $maxAttempts);
                $this->waitBeforeRetry($retryDelay);
            }
        } while ($attempts < $maxAttempts);

        throw new RuntimeException('Maximum retry attempts exceeded');
    }

    /**
     * Get retry attempts from config.
     */
    private function getRetryAttempts(): int
    {
        return $this->config->get('smart-failover.database.retry_attempts', self::DEFAULT_RETRY_ATTEMPTS);
    }

    /**
     * Get retry delay from config.
     */
    private function getRetryDelay(): int
    {
        return $this->config->get('smart-failover.database.retry_delay', self::DEFAULT_RETRY_DELAY_MS);
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
    private function logRetryAttempt(string $connection, int $attempt, int $maxAttempts): void
    {
        $this->logger->debug('Retrying database operation', [
            'connection' => $connection,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
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
     * Check health of database connections.
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
     * Check health of a single database connection.
     */
    private function checkConnectionHealth(string $connection): array
    {
        $startTime = microtime(true);

        try {
            $this->testConnection($connection);
            $responseTime = $this->calculateResponseTime($startTime);

            return $this->createHealthResult(
                $connection,
                self::HEALTH_STATUS_HEALTHY,
                $responseTime
            );
        } catch (Throwable $e) {
            return $this->createHealthResult(
                $connection,
                self::HEALTH_STATUS_UNHEALTHY,
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * Test database connection with simple query.
     */
    private function testConnection(string $connection): void
    {
        DB::connection($connection)->select(self::CONNECTION_TEST_QUERY);
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
}
