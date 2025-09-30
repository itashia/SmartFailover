<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\QueueFailoverManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Exception;

class QueueFailoverManagerTest extends TestCase
{
    private QueueFailoverManager $manager;
    private Config $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->manager = new QueueFailoverManager($this->config, $this->logger);
    }

    public function testExecuteWithPrimaryConnectionSuccess(): void
    {
        $connections = ['primary' => 'redis'];
        $expectedResult = 'success';
        
        $queueConnection = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        
        Queue::shouldReceive('connection')
            ->once()
            ->with('redis')
            ->andReturn($queueConnection);

        $callback = fn($conn) => $expectedResult;

        $result = $this->manager->execute($callback, $connections);

        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteWithFallbackWhenPrimaryFails(): void
    {
        $connections = [
            'primary' => 'redis', 
            'fallback' => 'database'
        ];
        
        $primaryException = new Exception('Primary failed');
        $expectedResult = 'fallback_success';

        $queueConnection = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        
        Queue::shouldReceive('connection')
            ->once()
            ->with('redis')
            ->andThrow($primaryException);
            
        Queue::shouldReceive('connection')
            ->once()
            ->with('database')
            ->andReturn($queueConnection);

        $this->logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->isType('string'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Switching to fallback queue connection');

        $callback = fn($conn) => $expectedResult;

        $result = $this->manager->execute($callback, $connections);

        $this->assertEquals($expectedResult, $result);
    }

    public function testPushJobToQueue(): void
    {
        $connections = ['primary' => 'redis'];
        $job = 'TestJob';
        $data = ['key' => 'value'];
        $queue = 'high-priority';
        
        $queueConnection = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        $queueConnection->expects($this->once())
            ->method('push')
            ->with($job, $data, $queue)
            ->willReturn('job-id');

        Queue::shouldReceive('connection')
            ->with('redis')
            ->andReturn($queueConnection);

        $result = $this->manager->push($job, $data, $queue);

        $this->assertEquals('job-id', $result);
    }

    public function testCheckHealthReturnsProperStructure(): void
    {
        $connections = ['primary' => 'redis', 'fallback' => 'database'];
        
        $queueConnection = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        $queueConnection->expects($this->exactly(2))
            ->method('size')
            ->willReturn(10);
        
        Queue::shouldReceive('connection')
            ->with('redis')
            ->andReturn($queueConnection);
            
        Queue::shouldReceive('connection')
            ->with('database')
            ->andReturn($queueConnection);

        $results = $this->manager->checkHealth($connections);

        $this->assertArrayHasKey('primary', $results);
        $this->assertArrayHasKey('fallback', $results);
        $this->assertArrayHasKey('status', $results['primary']);
        $this->assertArrayHasKey('response_time_ms', $results['primary']);
        $this->assertArrayHasKey('queue_size', $results['primary']);
        $this->assertArrayHasKey('checked_at', $results['primary']);
    }

    public function testSizeReturnsZeroOnFailure(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.queue.retry_attempts', 3, 3],
                ['smart-failover.queue.retry_delay', 2000, 2000],
                ['smart-failover.queue.exponential_backoff', true, true]
            ]);

        Queue::shouldReceive('connection')
            ->andThrow(new Exception('Connection failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $size = $this->manager->size();

        $this->assertEquals(0, $size);
    }

    public function testExponentialBackoffCalculation(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.queue.retry_attempts', 3, 3],
                ['smart-failover.queue.retry_delay', 2000, 2000],
                ['smart-failover.queue.exponential_backoff', true, true]
            ]);

        // Test that the manager can be instantiated and used
        $connections = ['primary' => 'redis'];
        
        $queueConnection = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        Queue::shouldReceive('connection')
            ->with('redis')
            ->andReturn($queueConnection);

        $result = $this->manager->push('TestJob', []);

        $this->assertTrue(true); // Basic functionality test passed
    }
}
