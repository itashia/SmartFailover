<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Exception;
use Throwable;

final class HealthCheckManager
{
    private const PACKAGE_VERSION = '1.0.0';
    private const DEFAULT_TIMEOUT = 5;
    private const HEALTH_STATUS_HEALTHY = 'healthy';
    private const HEALTH_STATUS_UNHEALTHY = 'unhealthy';
    private const HEALTH_STATUS_DEGRADED = 'degraded';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check health of all configured services.
     */
    public function checkAll(array $serviceConfigs = []): array
    {
        $results = $this->initializeResults();
        $enabledServices = $this->getEnabledServices();

        foreach ($enabledServices as $service => $isEnabled) {
            if ($isEnabled) {
                $results['services'][$service] = $this->checkService($service, $serviceConfigs[$service] ?? []);
            }
        }

        $this->calculateSummary($results);
        $this->logHealthCheck($results);

        return $results;
    }

    /**
     * Check if a specific service is healthy.
     */
    public function isServiceHealthy(string $service): bool
    {
        try {
            return match ($service) {
                'database', 'cache', 'queue' => $this->checkManagerHealth($service),
                default => false,
            };
        } catch (Throwable $e) {
            $this->logger->error('Failed to check service health', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get health check route response.
     */
    public function getHealthResponse(): array
    {
        $healthData = $this->checkAll();

        return [
            'status' => $healthData['status'],
            'timestamp' => $healthData['timestamp'],
            'services' => $healthData['services'],
            'summary' => $healthData['summary'],
            'version' => $this->getPackageVersion(),
        ];
    }

    /**
     * Initialize results array with default structure.
     */
    private function initializeResults(): array
    {
        return [
            'status' => self::HEALTH_STATUS_HEALTHY,
            'timestamp' => now()->toISOString(),
            'services' => [],
            'summary' => [
                'total' => 0,
                'healthy' => 0,
                'unhealthy' => 0,
            ],
        ];
    }

    /**
     * Get enabled services from configuration.
     */
    private function getEnabledServices(): array
    {
        return $this->config->get('smart-failover.health_check.services', []);
    }

    /**
     * Check specific service health.
     */
    private function checkService(string $service, array $config): array
    {
        return match ($service) {
            'database' => app(DatabaseFailoverManager::class)->checkHealth($config),
            'cache' => app(CacheFailoverManager::class)->checkHealth($config),
            'queue' => app(QueueFailoverManager::class)->checkHealth($config),
            'storage' => $this->checkStorageHealth(),
            'mail' => $this->checkMailHealth(),
            default => [],
        };
    }

    /**
     * Check storage health.
     */
    private function checkStorageHealth(): array
    {
        $results = [];
        $disks = $this->getStorageDisks();

        foreach ($disks as $disk) {
            $results[$disk] = $this->testDiskHealth($disk);
        }

        return $results;
    }

    /**
     * Get storage disks to check.
     */
    private function getStorageDisks(): array
    {
        $defaultDisks = ['local', 'public'];
        $configuredDisks = array_keys($this->config->get('filesystems.disks', []));
        
        return array_unique([...$defaultDisks, ...$configuredDisks]);
    }

    /**
     * Test individual disk health.
     */
    private function testDiskHealth(string $disk): array
    {
        $startTime = microtime(true);
        
        try {
            $isHealthy = $this->performDiskTest($disk);
            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'disk' => $disk,
                'status' => $isHealthy ? self::HEALTH_STATUS_HEALTHY : self::HEALTH_STATUS_UNHEALTHY,
                'response_time_ms' => round($responseTime, 2),
                'checked_at' => now()->toISOString(),
            ];
        } catch (Exception $e) {
            return [
                'disk' => $disk,
                'status' => self::HEALTH_STATUS_UNHEALTHY,
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString(),
            ];
        }
    }

    /**
     * Perform actual disk health test.
     */
    private function performDiskTest(string $disk): bool
    {
        $testFile = 'smart_failover_health_check_' . time() . '.txt';
        $testContent = 'health check test';

        Storage::disk($disk)->put($testFile, $testContent);
        $retrieved = Storage::disk($disk)->get($testFile);
        Storage::disk($disk)->delete($testFile);

        return $retrieved === $testContent;
    }

    /**
     * Check mail health.
     */
    private function checkMailHealth(): array
    {
        $results = [];
        $mailers = $this->getMailers();

        foreach ($mailers as $mailer) {
            $results[$mailer] = $this->testMailerHealth($mailer);
        }

        return $results;
    }

    /**
     * Get mailers to check.
     */
    private function getMailers(): array
    {
        $defaultMailers = ['smtp', 'log'];
        $configuredMailers = array_keys($this->config->get('mail.mailers', []));
        
        return array_unique([...$defaultMailers, ...$configuredMailers]);
    }

    /**
     * Test individual mailer health.
     */
    private function testMailerHealth(string $mailer): array
    {
        $startTime = microtime(true);
        
        try {
            $mailerConfig = $this->config->get("mail.mailers.{$mailer}");
            
            if (!$mailerConfig) {
                throw new Exception('Mailer configuration not found');
            }

            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'mailer' => $mailer,
                'status' => self::HEALTH_STATUS_HEALTHY,
                'response_time_ms' => round($responseTime, 2),
                'checked_at' => now()->toISOString(),
            ];
        } catch (Exception $e) {
            return [
                'mailer' => $mailer,
                'status' => self::HEALTH_STATUS_UNHEALTHY,
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString(),
            ];
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
     * Check manager service health.
     */
    private function checkManagerHealth(string $service): bool
    {
        $manager = app(match ($service) {
            'database' => DatabaseFailoverManager::class,
            'cache' => CacheFailoverManager::class,
            'queue' => QueueFailoverManager::class,
        });

        $status = $manager->getHealthStatus();
        
        return !empty($status) && in_array(true, $status, true);
    }

    /**
     * Calculate health check summary.
     */
    private function calculateSummary(array &$results): void
    {
        $total = 0;
        $healthy = 0;

        foreach ($results['services'] as $services) {
            foreach ($services as $service) {
                $total++;
                if ($service['status'] === self::HEALTH_STATUS_HEALTHY) {
                    $healthy++;
                }
            }
        }

        $unhealthy = $total - $healthy;
        
        $results['summary'] = compact('total', 'healthy', 'unhealthy');
        $results['status'] = $this->determineOverallStatus($total, $healthy, $unhealthy);
    }

    /**
     * Determine overall health status.
     */
    private function determineOverallStatus(int $total, int $healthy, int $unhealthy): string
    {
        if ($unhealthy === 0) {
            return self::HEALTH_STATUS_HEALTHY;
        }
        
        if ($healthy === 0 && $total > 0) {
            return self::HEALTH_STATUS_UNHEALTHY;
        }
        
        return self::HEALTH_STATUS_DEGRADED;
    }

    /**
     * Log health check results.
     */
    private function logHealthCheck(array $results): void
    {
        $this->logger->info('Health check completed', [
            'status' => $results['status'],
            'summary' => $results['summary'],
        ]);
    }

    /**
     * Get package version.
     */
    private function getPackageVersion(): string
    {
        try {
            $composerPath = __DIR__ . '/../../composer.json';
            
            if (file_exists($composerPath)) {
                $composer = json_decode(file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
                return $composer['version'] ?? self::PACKAGE_VERSION;
            }
        } catch (Throwable $e) {
            // Ignore errors and return default version
        }

        return self::PACKAGE_VERSION;
    }
}
