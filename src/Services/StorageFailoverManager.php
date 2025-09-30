<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Exception;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Throwable;

final class StorageFailoverManager
{
    private const DEFAULT_RETRY_ATTEMPTS = 3;
    private const DEFAULT_RETRY_DELAY_MS = 1000;
    private const HEALTH_STATUS_HEALTHY = 'healthy';
    private const HEALTH_STATUS_UNHEALTHY = 'unhealthy';
    private const HEALTH_CHECK_TTL = 300; // 5 minutes
    private const HEALTH_CHECK_FILE_PREFIX = 'smart_failover_health_check_';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly FilesystemManager $storageManager
    ) {
        $this->loadConfiguration();
    }

    /**
     * Set disks for failover.
     */
    public function setDisks(string $primary, array $fallbacks = []): self
    {
        $this->primaryDisk = $primary;
        $this->fallbackDisks = $fallbacks;
        $this->disks = [$primary, ...$fallbacks];

        return $this;
    }

    /**
     * Store file with failover protection.
     */
    public function put(string $path, mixed $contents, array $options = []): bool
    {
        foreach ($this->getAvailableDisks() as $diskName) {
            try {
                $this->logger->info("Attempting to store file on {$diskName}");

                $result = $this->storageManager->disk($diskName)
                    ->put($path, $contents, $options);

                $this->markDiskHealthy($diskName);
                $this->logger->info("File stored successfully on {$diskName}");

                return $result;

            } catch (Throwable $e) {
                $this->handleDiskError($diskName, $e, 'Storage failed');
                continue;
            }
        }

        $this->logger->error('All storage services failed');
        throw new Exception('All configured storage services are unavailable');
    }

    /**
     * Get file with failover protection.
     */
    public function get(string $path): ?string
    {
        foreach ($this->getAvailableDisks() as $diskName) {
            try {
                $this->logger->info("Attempting to get file from {$diskName}");

                $disk = $this->storageManager->disk($diskName);

                if ($disk->exists($path)) {
                    $contents = $disk->get($path);
                    $this->markDiskHealthy($diskName);
                    $this->logger->info("File retrieved successfully from {$diskName}");

                    return $contents;
                }

            } catch (Throwable $e) {
                $this->handleDiskError($diskName, $e, 'Storage retrieval failed');
                continue;
            }
        }

        $this->logger->warning("File not found on any storage service: {$path}");
        return null;
    }

    /**
     * Delete file with failover protection.
     */
    public function delete(string $path): bool
    {
        $success = false;

        foreach ($this->getAvailableDisks() as $diskName) {
            try {
                $this->logger->info("Attempting to delete file from {$diskName}");

                $disk = $this->storageManager->disk($diskName);

                if ($disk->exists($path) && $disk->delete($path)) {
                    $success = true;
                    $this->logger->info("File deleted successfully from {$diskName}");
                }

                $this->markDiskHealthy($diskName);

            } catch (Throwable $e) {
                $this->handleDiskError($diskName, $e, 'Storage deletion failed');
            }
        }

        return $success;
    }

    /**
     * Check if file exists with failover protection.
     */
    public function exists(string $path): bool
    {
        foreach ($this->getAvailableDisks() as $diskName) {
            try {
                $disk = $this->storageManager->disk($diskName);

                if ($disk->exists($path)) {
                    $this->markDiskHealthy($diskName);
                    return true;
                }

            } catch (Throwable $e) {
                $this->handleDiskError($diskName, $e, 'Storage check failed');
            }
        }

        return false;
    }

    /**
     * Get file URL with failover protection.
     */
    public function url(string $path): ?string
    {
        foreach ($this->getAvailableDisks() as $diskName) {
            try {
                $disk = $this->storageManager->disk($diskName);

                if ($disk->exists($path)) {
                    $url = $disk instanceof Cloud 
                        ? $disk->url($path)
                        : null;
                    
                    $this->markDiskHealthy($diskName);
                    return $url;
                }

            } catch (Throwable $e) {
                $this->handleDiskError($diskName, $e, 'Storage URL generation failed');
            }
        }

        return null;
    }

    /**
     * Check health of all configured disks.
     */
    public function checkHealth(): array
    {
        $health = [];

        foreach ($this->disks as $diskName) {
            $health[$diskName] = $this->checkDiskHealth($diskName);
        }

        return $health;
    }

    /**
     * Check health of a specific disk.
     */
    private function checkDiskHealth(string $diskName): array
    {
        $startTime = microtime(true);

        try {
            $disk = $this->storageManager->disk($diskName);
            $this->performHealthCheck($disk, $diskName);

            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'status' => self::HEALTH_STATUS_HEALTHY,
                'response_time' => $responseTime,
                'driver' => $this->getDiskDriver($diskName),
                'last_checked' => now()->toISOString(),
            ];

        } catch (Throwable $e) {
            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'status' => self::HEALTH_STATUS_UNHEALTHY,
                'response_time' => $responseTime,
                'error' => $e->getMessage(),
                'last_checked' => now()->toISOString(),
            ];
        }
    }

    /**
     * Perform health check operations on disk.
     */
    private function performHealthCheck($disk, string $diskName): void
    {
        $testFile = self::HEALTH_CHECK_FILE_PREFIX . time() . '.txt';
        $testContent = 'SmartFailover health check';

        // Test write, read, and delete operations
        $disk->put($testFile, $testContent);
        $retrievedContent = $disk->get($testFile);
        $disk->delete($testFile);

        if ($retrievedContent !== $testContent) {
            throw new Exception('Content verification failed');
        }
    }

    /**
     * Calculate response time in milliseconds.
     */
    private function calculateResponseTime(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000.0, 2);
    }

    /**
     * Get disk driver from configuration.
     */
    private function getDiskDriver(string $diskName): string
    {
        return $this->config->get("filesystems.disks.{$diskName}.driver", 'unknown');
    }

    /**
     * Handle disk error with consistent logging and marking.
     */
    private function handleDiskError(string $diskName, Throwable $e, string $context): void
    {
        $this->markDiskUnhealthy($diskName, $e->getMessage());
        $this->logger->error("{$context} on {$diskName}: " . $e->getMessage());
    }

    /**
     * Get available disks in priority order.
     */
    private function getAvailableDisks(): array
    {
        $available = [];

        // Add primary disk if healthy
        if ($this->primaryDisk && $this->isDiskHealthy($this->primaryDisk)) {
            $available[] = $this->primaryDisk;
        }

        // Add healthy fallback disks
        foreach ($this->fallbackDisks as $disk) {
            if ($this->isDiskHealthy($disk)) {
                $available[] = $disk;
            }
        }

        // If no healthy disks, try all configured disks as last resort
        return empty($available) ? $this->disks : $available;
    }

    /**
     * Check if disk is healthy.
     */
    private function isDiskHealthy(string $diskName): bool
    {
        if (!isset($this->healthStatus[$diskName])) {
            return true; // Assume healthy if not checked yet
        }

        $status = $this->healthStatus[$diskName];

        if ($status['status'] === self::HEALTH_STATUS_UNHEALTHY) {
            $lastFailure = $status['last_failure'] ?? 0;
            return (time() - $lastFailure) > self::HEALTH_CHECK_TTL;
        }

        return $status['status'] === self::HEALTH_STATUS_HEALTHY;
    }

    /**
     * Mark disk as healthy.
     */
    private function markDiskHealthy(string $diskName): void
    {
        $this->healthStatus[$diskName] = [
            'status' => self::HEALTH_STATUS_HEALTHY,
            'last_success' => time(),
        ];
    }

    /**
     * Mark disk as unhealthy.
     */
    private function markDiskUnhealthy(string $diskName, string $error): void
    {
        $this->healthStatus[$diskName] = [
            'status' => self::HEALTH_STATUS_UNHEALTHY,
            'last_failure' => time(),
            'error' => $error,
        ];
    }

    /**
     * Load configuration.
     */
    private function loadConfiguration(): void
    {
        $config = $this->config->get('smart-failover.storage', []);

        $this->primaryDisk = $config['primary'] ?? null;
        $this->fallbackDisks = $config['fallbacks'] ?? [];
        $this->retryAttempts = $config['retry_attempts'] ?? self::DEFAULT_RETRY_ATTEMPTS;
        $this->retryDelay = $config['retry_delay'] ?? self::DEFAULT_RETRY_DELAY_MS;

        $this->disks = array_filter([
            $this->primaryDisk,
            ...$this->fallbackDisks
        ]);
    }

    /**
     * Get current storage status.
     */
    public function getStatus(): array
    {
        return [
            'primary' => $this->primaryDisk,
            'fallbacks' => $this->fallbackDisks,
            'health' => $this->healthStatus,
            'available_disks' => $this->getAvailableDisks(),
        ];
    }

    /**
     * Sync file across all available disks.
     */
    public function sync(string $path, mixed $contents = null): array
    {
        $results = [];
        $disks = $this->getAvailableDisks();

        // If no contents provided, get from primary disk
        if ($contents === null && $this->primaryDisk) {
            try {
                $contents = $this->storageManager->disk($this->primaryDisk)->get($path);
            } catch (Throwable $e) {
                throw new Exception('Could not retrieve file for syncing: ' . $e->getMessage());
            }
        }

        if ($contents === null) {
            throw new Exception('No contents provided for syncing');
        }

        foreach ($disks as $diskName) {
            try {
                $result = $this->storageManager->disk($diskName)
                    ->put($path, $contents);
                
                $results[$diskName] = $result ? 'success' : 'failed';

                if ($result) {
                    $this->markDiskHealthy($diskName);
                }

            } catch (Throwable $e) {
                $results[$diskName] = 'error: ' . $e->getMessage();
                $this->markDiskUnhealthy($diskName, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get disk usage statistics.
     */
    public function getDiskUsage(): array
    {
        $usage = [];

        foreach ($this->disks as $diskName) {
            try {
                $disk = $this->storageManager->disk($diskName);
                
                // This is a simplified implementation - actual disk usage
                // would depend on the filesystem driver capabilities
                $usage[$diskName] = [
                    'status' => $this->isDiskHealthy($diskName) ? 'healthy' : 'unhealthy',
                    'driver' => $this->getDiskDriver($diskName),
                ];

            } catch (Throwable $e) {
                $usage[$diskName] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $usage;
    }
}
