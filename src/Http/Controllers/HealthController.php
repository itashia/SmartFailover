<?php

namespace Mirzaaghazadeh\SmartFailover\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Mirzaaghazadeh\SmartFailover\Services\CacheFailoverManager;
use Mirzaaghazadeh\SmartFailover\Services\DatabaseFailoverManager;
use Mirzaaghazadeh\SmartFailover\Services\HealthCheckManager;
use Mirzaaghazadeh\SmartFailover\Services\QueueFailoverManager;
use Throwable;

final class HealthController extends Controller
{
    private const HTTP_OK = 200;
    private const HTTP_PARTIAL_CONTENT = 206;
    private const HTTP_SERVICE_UNAVAILABLE = 503;
    private const HTTP_INTERNAL_ERROR = 500;

    private const STATUS_HEALTHY = 'healthy';
    private const STATUS_DEGRADED = 'degraded';
    private const STATUS_UNHEALTHY = 'unhealthy';
    private const STATUS_ERROR = 'error';

    public function __construct(
        private readonly HealthCheckManager $healthManager
    ) {
    }

    /**
     * Get overall health status.
     */
    public function index(): JsonResponse
    {
        return $this->handleHealthCheck(
            fn() => $this->healthManager->getHealthResponse(),
            'Health check failed'
        );
    }

    /**
     * Get detailed health status.
     */
    public function detailed(): JsonResponse
    {
        return $this->handleHealthCheck(
            fn() => $this->healthManager->checkAll(),
            'Detailed health check failed'
        );
    }

    /**
     * Get database health status.
     */
    public function database(): JsonResponse
    {
        return $this->handleServiceHealthCheck(
            'database',
            fn() => app(DatabaseFailoverManager::class)->checkHealth(),
            'connections'
        );
    }

    /**
     * Get cache health status.
     */
    public function cache(): JsonResponse
    {
        return $this->handleServiceHealthCheck(
            'cache',
            fn() => app(CacheFailoverManager::class)->checkHealth(),
            'stores'
        );
    }

    /**
     * Get queue health status.
     */
    public function queue(): JsonResponse
    {
        return $this->handleServiceHealthCheck(
            'queue',
            fn() => app(QueueFailoverManager::class)->checkHealth(),
            'connections'
        );
    }

    /**
     * Get storage health status.
     */
    public function storage(): JsonResponse
    {
        return $this->handleServiceHealthCheck(
            'storage',
            fn() => $this->getStorageHealthData(),
            'disks'
        );
    }

    /**
     * Get mail health status.
     */
    public function mail(): JsonResponse
    {
        return $this->handleServiceHealthCheck(
            'mail',
            fn() => $this->getMailHealthData(),
            'mailers'
        );
    }

    /**
     * Handle generic health check with error handling.
     */
    private function handleHealthCheck(callable $healthCheck, string $errorMessage): JsonResponse
    {
        try {
            $health = $healthCheck();
            $statusCode = $this->determineStatusCode($health['status']);

            return Response::json($health, $statusCode);
        } catch (Throwable $e) {
            return $this->createErrorResponse($errorMessage, $e);
        }
    }

    /**
     * Handle service-specific health check.
     */
    private function handleServiceHealthCheck(string $service, callable $healthCheck, string $dataKey): JsonResponse
    {
        try {
            $health = $healthCheck();
            $allHealthy = $this->allServicesHealthy($health);
            $statusCode = $allHealthy ? self::HTTP_OK : self::HTTP_SERVICE_UNAVAILABLE;

            return response()->json([
                'service' => $service,
                'status' => $allHealthy ? self::STATUS_HEALTHY : self::STATUS_UNHEALTHY,
                $dataKey => $health,
                'timestamp' => now()->toISOString(),
            ], $statusCode);
        } catch (Throwable $e) {
            return $this->createServiceErrorResponse($service, $e);
        }
    }

    /**
     * Get storage health data from health manager.
     */
    private function getStorageHealthData(): array
    {
        $allHealth = $this->healthManager->checkAll();
        return $allHealth['services']['storage'] ?? [];
    }

    /**
     * Get mail health data from health manager.
     */
    private function getMailHealthData(): array
    {
        $allHealth = $this->healthManager->checkAll();
        return $allHealth['services']['mail'] ?? [];
    }

    /**
     * Check if all services in health data are healthy.
     */
    private function allServicesHealthy(array $health): bool
    {
        return collect($health)->every(fn($service) => ($service['status'] ?? '') === self::STATUS_HEALTHY);
    }

    /**
     * Determine HTTP status code based on health status.
     */
    private function determineStatusCode(string $healthStatus): int
    {
        return match ($healthStatus) {
            self::STATUS_HEALTHY => self::HTTP_OK,
            self::STATUS_DEGRADED => self::HTTP_PARTIAL_CONTENT,
            self::STATUS_UNHEALTHY => self::HTTP_SERVICE_UNAVAILABLE,
            default => self::HTTP_OK,
        };
    }

    /**
     * Create error response for general health checks.
     */
    private function createErrorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString(),
        ], self::HTTP_INTERNAL_ERROR);
    }

    /**
     * Create error response for service-specific health checks.
     */
    private function createServiceErrorResponse(string $service, Throwable $e): JsonResponse
    {
        return response()->json([
            'service' => $service,
            'status' => self::STATUS_ERROR,
            'message' => "{$service} health check failed",
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString(),
        ], self::HTTP_INTERNAL_ERROR);
    }
}
