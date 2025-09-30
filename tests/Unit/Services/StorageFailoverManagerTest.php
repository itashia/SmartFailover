<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\StorageFailoverManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\FilesystemManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Exception;

class StorageFailoverManagerTest extends TestCase
{
    private StorageFailoverManager $manager;
    private Config $config;
    private LoggerInterface $logger;
    private FilesystemManager $storageManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storageManager = $this->createMock(FilesystemManager::class);
        
        $this->manager = new StorageFailoverManager(
            $this->config, 
            $this->logger, 
            $this->storageManager
        );
    }

    public function testPutFileSuccess(): void
    {
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())
            ->method('put')
            ->with('test.txt', 'content', [])
            ->willReturn(true);

        $this->storageManager->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($disk);

        $this->manager->setDisks('local');

        $result = $this->manager->put('test.txt', 'content');

        $this->assertTrue($result);
    }

    public function testPutFileWithFailover(): void
    {
        $primaryDisk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $primaryDisk->expects($this->once())
            ->method('put')
            ->willThrowException(new Exception('Primary failed'));

        $fallbackDisk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $fallbackDisk->expects($this->once())
            ->method('put')
            ->willReturn(true);

        $this->storageManager->expects($this->exactly(2))
            ->method('disk')
            ->willReturnMap([
                ['s3', $primaryDisk],
                ['local', $fallbackDisk]
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('error');

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->manager->setDisks('s3', ['local']);

        $result = $this->manager->put('test.txt', 'content');

        $this->assertTrue($result);
    }

    public function testGetFileReturnsContent(): void
    {
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())
            ->method('exists')
            ->with('test.txt')
            ->willReturn(true);
        $disk->expects($this->once())
            ->method('get')
            ->with('test.txt')
            ->willReturn('file content');

        $this->storageManager->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($disk);

        $this->manager->setDisks('local');

        $result = $this->manager->get('test.txt');

        $this->assertEquals('file content', $result);
    }

    public function testGetFileReturnsNullWhenNotFound(): void
    {
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())
            ->method('exists')
            ->with('test.txt')
            ->willReturn(false);

        $this->storageManager->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($disk);

        $this->manager->setDisks('local');

        $result = $this->manager->get('test.txt');

        $this->assertNull($result);
    }

    public function testDeleteFileSuccess(): void
    {
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())
            ->method('exists')
            ->with('test.txt')
            ->willReturn(true);
        $disk->expects($this->once())
            ->method('delete')
            ->with('test.txt')
            ->willReturn(true);

        $this->storageManager->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($disk);

        $this->manager->setDisks('local');

        $result = $this->manager->delete('test.txt');

        $this->assertTrue($result);
    }

    public function testCheckHealthReturnsProperStructure(): void
    {
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())
            ->method('put')
            ->willReturn(true);
        $disk->expects($this->once())
            ->method('get')
            ->willReturn('SmartFailover health check');
        $disk->expects($this->once())
            ->method('delete')
            ->willReturn(true);

        $this->storageManager->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($disk);

        $this->config->expects($this->once())
            ->method('get')
            ->with('filesystems.disks.local.driver', 'unknown')
            ->willReturn('local');

        $this->manager->setDisks('local');

        $health = $this->manager->checkHealth();

        $this->assertArrayHasKey('local', $health);
        $this->assertArrayHasKey('status', $health['local']);
        $this->assertArrayHasKey('response_time', $health['local']);
        $this->assertArrayHasKey('driver', $health['local']);
        $this->assertArrayHasKey('last_checked', $health['local']);
    }

    public function testSyncFilesAcrossDisks(): void
    {
        $primaryDisk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $fallbackDisk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        
        $primaryDisk->expects($this->once())
            ->method('put')
            ->with('test.txt', 'content')
            ->willReturn(true);
            
        $fallbackDisk->expects($this->once())
            ->method('put')
            ->with('test.txt', 'content')
            ->willReturn(true);

        $this->storageManager->expects($this->exactly(2))
            ->method('disk')
            ->willReturnMap([
                ['s3', $primaryDisk],
                ['local', $fallbackDisk]
            ]);

        $this->manager->setDisks('s3', ['local']);

        $results = $this->manager->sync('test.txt', 'content');

        $this->assertArrayHasKey('s3', $results);
        $this->assertArrayHasKey('local', $results);
        $this->assertEquals('success', $results['s3']);
        $this->assertEquals('success', $results['local']);
    }
}
