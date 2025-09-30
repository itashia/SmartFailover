<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\HealthCheckManager;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class HealthCheckManagerTest extends TestCase
{
    private HealthCheckManager $manager;
    private Config $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->manager = new HealthCheckManager($this->config, $this->logger);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(HealthCheckManager::class, $this->manager);
    }

    public function testCheckAllReturnsProperStructure(): void
    {
        $this->config->method('get')
            ->willReturn([]);

        $result = $this->manager->checkAll();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total', $result['summary']);
        $this->assertArrayHasKey('healthy', $result['summary']);
        $this->assertArrayHasKey('unhealthy', $result['summary']);
    }

    public function testGetHealthResponseReturnsCompleteData(): void
    {
        $this->config->method('get')
            ->willReturn([]);

        $response = $this->manager->getHealthResponse();

        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('timestamp', $response);
    }
}
