<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\DatabaseFailoverManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class DatabaseFailoverManagerTest extends TestCase
{
    private DatabaseFailoverManager $manager;
    private Config $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->manager = new DatabaseFailoverManager($this->config, $this->logger);
    }

    public function testExecuteWithPrimaryConnectionSuccess(): void
    {
        $connections = ['primary' => 'mysql'];
        $expectedResult = 'success';
        
        $callback = fn() => $expectedResult;

        DB::shouldReceive('setDefaultConnection')
            ->once()
            ->with('mysql');

        $result = $this->manager->execute($callback, $connections);

        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteWithFallbackWhenPrimaryFails(): void
    {
        $connections = [
            'primary' => 'mysql', 
            'fallback' => 'pgsql'
        ];
        
        $primaryException = new QueryException('', [], new \Exception('Primary failed'));
        $expectedResult = 'fallback_success';

        DB::shouldReceive('setDefaultConnection')
            ->once()
            ->with('mysql')
            ->andThrow($primaryException);

        DB::shouldReceive('setDefaultConnection')
            ->once()
            ->with('pgsql');

        $this->logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->isType('string'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Switching to fallback database connection');

        $callback = fn() => $expectedResult;

        $result = $this->manager->execute($callback, $connections);

        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteThrowsExceptionWhenNoPrimaryConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primary database connection must be specified');

        $this->manager->execute(fn() => null, []);
    }

    public function testCheckHealthReturnsProperStructure(): void
    {
        $connections = ['primary' => 'mysql', 'fallback' => 'pgsql'];
        
        DB::shouldReceive('connection')
            ->with('mysql')
            ->andReturnSelf();
            
        DB::shouldReceive('connection')
            ->with('pgsql')
            ->andReturnSelf();
            
        DB::shouldReceive('select')
            ->with('SELECT 1')
            ->andReturn([(object) ['1' => 1]]);

        $results = $this->manager->checkHealth($connections);

        $this->assertArrayHasKey('primary', $results);
        $this->assertArrayHasKey('fallback', $results);
        $this->assertArrayHasKey('status', $results['primary']);
        $this->assertArrayHasKey('response_time_ms', $results['primary']);
        $this->assertArrayHasKey('checked_at', $results['primary']);
    }

    public function testIsConnectionHealthyReturnsCorrectStatus(): void
    {
        $connections = ['primary' => 'mysql'];
        
        DB::shouldReceive('connection')
            ->with('mysql')
            ->andReturnSelf();
            
        DB::shouldReceive('select')
            ->with('SELECT 1')
            ->andReturn([(object) ['1' => 1]]);

        $this->manager->checkHealth($connections);

        $this->assertTrue($this->manager->isConnectionHealthy('mysql'));
    }
}
