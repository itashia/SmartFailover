<?php

namespace Tests\Unit\Http\Controllers;

use Mirzaaghazadeh\SmartFailover\Http\Controllers\HealthController;
use Mirzaaghazadeh\SmartFailover\Services\HealthCheckManager;
use Mirzaaghazadeh\SmartFailover\Services\DatabaseFailoverManager;
use Mirzaaghazadeh\SmartFailover\Services\CacheFailoverManager;
use Mirzaaghazadeh\SmartFailover\Services\QueueFailoverManager;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\TestCase;
use Exception;

class HealthControllerTest extends TestCase
{
    private HealthController $controller;
    private HealthCheckManager $healthManager;

    protected function setUp(): void
    {
        $this->healthManager = $this->createMock(HealthCheckManager::class);
        $this->controller = new HealthController($this->healthManager);
    }

    public function testIndexReturnsHealthyStatus(): void
    {
        $healthData = [
            'status' => 'healthy',
            'timestamp' => '2023-01-01T00:00:00Z',
            'services' => [],
            'summary' => ['total' => 3, 'healthy' => 3, 'unhealthy' => 0],
            'version' => '1.0.0'
        ];

        $this->healthManager->expects($this->once())
            ->method('getHealthResponse')
            ->willReturn($healthData);

        $response = $this->controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($healthData, $response->getData(true));
    }

    public function testIndexReturnsDegradedStatus(): void
    {
        $healthData = [
            'status' => 'degraded',
            'timestamp' => '2023-01-01T00:00:00Z',
            'services' => [],
            'summary' => ['total' => 3, 'healthy' => 2, 'unhealthy' => 1],
            'version' => '1.0.0'
        ];

        $this->healthManager->expects($this->once())
            ->method('getHealthResponse')
            ->willReturn($healthData);

        $response = $this->controller->index();

        $this->assertEquals(206, $response->getStatusCode());
        $this->assertEquals($healthData, $response->getData(true));
    }

    public function testIndexReturnsUnhealthyStatus(): void
    {
        $healthData = [
            'status' => 'unhealthy',
            'timestamp' => '2023-01-01T00:00:00Z',
            'services' => [],
            'summary' => ['total' => 3, 'healthy' => 0, 'unhealthy' => 3],
            'version' => '1.0.0'
        ];

        $this->healthManager->expects($this->once())
            ->method('getHealthResponse')
            ->willReturn($healthData);

        $response = $this->controller->index();

        $this->assertEquals(503, $response->getStatusCode());
        $this->assertEquals($healthData, $response->getData(true));
    }

    public function testIndexHandlesException(): void
    {
        $this->healthManager->expects($this->once())
            ->method('getHealthResponse')
            ->willThrowException(new Exception('Health check failed'));

        $response = $this->controller->index();

        $this->assertEquals(500, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Health check failed', $responseData['message']);
        $this->assertEquals('Health check failed', $responseData['error']);
    }

    public function testDatabaseHealthCheckSuccess(): void
    {
        $databaseManager = $this->createMock(DatabaseFailoverManager::class);
        $databaseManager->expects($this->once())
            ->method('checkHealth')
            ->willReturn([
                'primary' => ['status' => 'healthy', 'connection' => 'mysql'],
                'replica' => ['status' => 'healthy', 'connection' => 'mysql_replica']
            ]);

        app()->instance(DatabaseFailoverManager::class, $databaseManager);

        $response = $this->controller->database();

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('database', $responseData['service']);
        $this->assertEquals('healthy', $responseData['status']);
        $this->assertArrayHasKey('connections', $responseData);
    }

    public function testDatabaseHealthCheckWithUnhealthyService(): void
    {
        $databaseManager = $this->createMock(DatabaseFailoverManager::class);
        $databaseManager->expects($this->once())
            ->method('checkHealth')
            ->willReturn([
                'primary' => ['status' => 'unhealthy', 'connection' => 'mysql', 'error' => 'Connection failed'],
                'replica' => ['status' => 'healthy', 'connection' => 'mysql_replica']
            ]);

        app()->instance(DatabaseFailoverManager::class, $databaseManager);

        $response = $this->controller->database();

        $this->assertEquals(503, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('database', $responseData['service']);
        $this->assertEquals('unhealthy', $responseData['status']);
    }

    public function testCacheHealthCheckSuccess(): void
    {
        $cacheManager = $this->createMock(CacheFailoverManager::class);
        $cacheManager->expects($this->once())
            ->method('checkHealth')
            ->willReturn([
                'redis' => ['status' => 'healthy', 'store' => 'redis'],
                'file' => ['status' => 'healthy', 'store' => 'file']
            ]);

        app()->instance(CacheFailoverManager::class, $cacheManager);

        $response = $this->controller->cache();

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('cache', $responseData['service']);
        $this->assertEquals('healthy', $responseData['status']);
        $this->assertArrayHasKey('stores', $responseData);
    }

    public function testStorageHealthCheck(): void
    {
        $storageHealthData = [
            'local' => ['status' => 'healthy', 'disk' => 'local'],
            's3' => ['status' => 'healthy', 'disk' => 's3']
        ];

        $allHealthData = [
            'status' => 'healthy',
            'services' => [
                'storage' => $storageHealthData
            ]
        ];

        $this->healthManager->expects($this->once())
            ->method('checkAll')
            ->willReturn($allHealthData);

        $response = $this->controller->storage();

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('storage', $responseData['service']);
        $this->assertEquals('healthy', $responseData['status']);
        $this->assertEquals($storageHealthData, $responseData['disks']);
    }

    public function testMailHealthCheck(): void
    {
        $mailHealthData = [
            'smtp' => ['status' => 'healthy', 'mailer' => 'smtp'],
            'ses' => ['status' => 'healthy', 'mailer' => 'ses']
        ];

        $allHealthData = [
            'status' => 'healthy',
            'services' => [
                'mail' => $mailHealthData
            ]
        ];

        $this->healthManager->expects($this->once())
            ->method('checkAll')
            ->willReturn($allHealthData);

        $response = $this->controller->mail();

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertEquals('mail', $responseData['service']);
        $this->assertEquals('healthy', $responseData['status']);
        $this->assertEquals($mailHealthData, $responseData['mailers']);
    }
}
