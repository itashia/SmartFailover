<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\MailFailoverManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Exception;

class MailFailoverManagerTest extends TestCase
{
    private MailFailoverManager $manager;
    private Config $config;
    private LoggerInterface $logger;
    private MailManager $mailManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mailManager = $this->createMock(MailManager::class);
        
        $this->manager = new MailFailoverManager(
            $this->config, 
            $this->logger, 
            $this->mailManager
        );
    }

    public function testSendWithPrimaryMailerSuccess(): void
    {
        $mailable = new \Illuminate\Mail\Mailable();
        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.mail.primary', null, 'smtp'],
                ['smart-failover.mail.fallbacks', [], []],
                ['mail.mailers.smtp.transport', null, 'smtp'],
                ['mail.mailers.smtp.host', null, 'smtp.example.com'],
                ['mail.mailers.smtp.port', null, 587],
            ]);

        $this->mailManager->expects($this->once())
            ->method('mailer')
            ->with('smtp')
            ->willReturn($mailer);

        $this->mailManager->expects($this->once())
            ->method('purge')
            ->with('smtp');

        $this->config->expects($this->once())
            ->method('set')
            ->with('mail.default', 'smtp');

        Mail::shouldReceive('mailer')
            ->once()
            ->with('smtp')
            ->andReturn($mailer);

        $mailer->expects($this->once())
            ->method('send')
            ->with($mailable);

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                ['Attempting to send mail via smtp'],
                ['Mail sent successfully via smtp']
            );

        $result = $this->manager->send($mailable);

        $this->assertTrue($result);
    }

    public function testSendWithFallbackWhenPrimaryFails(): void
    {
        $mailable = new \Illuminate\Mail\Mailable();
        
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.mail.primary', null, 'smtp'],
                ['smart-failover.mail.fallbacks', [], ['ses']],
                ['mail.mailers.smtp.transport', null, 'smtp'],
                ['mail.mailers.ses.transport', null, 'ses'],
            ]);

        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);

        // Primary fails
        $this->mailManager->expects($this->exactly(2))
            ->method('mailer')
            ->willReturnCallback(fn($name) => 
                $name === 'smtp' ? throw new Exception('SMTP failed') : $mailer
            );

        $this->mailManager->expects($this->exactly(2))
            ->method('purge');

        $this->config->expects($this->exactly(2))
            ->method('set')
            ->withConsecutive(
                ['mail.default', 'smtp'],
                ['mail.default', 'ses']
            );

        Mail::shouldReceive('mailer')
            ->once()
            ->with('ses')
            ->andReturn($mailer);

        $mailer->expects($this->once())
            ->method('send')
            ->with($mailable);

        $this->logger->expects($this->exactly(2))
            ->method('error')
            ->with('Mail failed via smtp: SMTP failed');

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                ['Attempting to send mail via smtp'],
                ['Attempting to send mail via ses'],
                ['Mail sent successfully via ses']
            );

        $result = $this->manager->send($mailable);

        $this->assertTrue($result);
    }

    public function testQueueMailSuccess(): void
    {
        $mailable = new \Illuminate\Mail\Mailable();
        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.mail.primary', null, 'smtp'],
                ['mail.mailers.smtp.transport', null, 'smtp'],
            ]);

        $this->mailManager->expects($this->once())
            ->method('mailer')
            ->with('smtp')
            ->willReturn($mailer);

        Mail::shouldReceive('mailer')
            ->once()
            ->with('smtp')
            ->andReturn($mailer);

        $mailer->expects($this->once())
            ->method('queue')
            ->with($mailable, null);

        $result = $this->manager->queue($mailable);

        $this->assertTrue($result);
    }

    public function testCheckHealthReturnsProperStructure(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.mail.primary', null, 'smtp'],
                ['smart-failover.mail.fallbacks', [], ['ses']],
                ['mail.mailers.smtp.transport', null, 'smtp'],
                ['mail.mailers.smtp.host', null, 'smtp.example.com'],
                ['mail.mailers.smtp.port', null, 587],
                ['mail.mailers.ses.transport', null, 'ses'],
            ]);

        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        
        $this->mailManager->expects($this->exactly(2))
            ->method('mailer')
            ->willReturn($mailer);

        $health = $this->manager->checkHealth();

        $this->assertArrayHasKey('smtp', $health);
        $this->assertArrayHasKey('ses', $health);
        $this->assertArrayHasKey('status', $health['smtp']);
        $this->assertArrayHasKey('response_time', $health['smtp']);
        $this->assertArrayHasKey('transport', $health['smtp']);
        $this->assertArrayHasKey('last_checked', $health['smtp']);
    }

    public function testGetStatisticsReturnsCorrectData(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.mail.primary', null, 'smtp'],
                ['smart-failover.mail.fallbacks', [], ['ses', 'mailgun']],
                ['mail.mailers.smtp.transport', null, 'smtp'],
                ['mail.mailers.ses.transport', null, 'ses'],
                ['mail.mailers.mailgun.transport', null, 'mailgun'],
            ]);

        $stats = $this->manager->getStatistics();

        $this->assertEquals(3, $stats['total_mailers']);
        $this->assertEquals(3, $stats['healthy_mailers']); // All assumed healthy initially
        $this->assertEquals(0, $stats['unhealthy_mailers']);
        $this->assertArrayHasKey('smtp', $stats['mailers']);
        $this->assertArrayHasKey('ses', $stats['mailers']);
        $this->assertArrayHasKey('mailgun', $stats['mailers']);
        $this->assertTrue($stats['mailers']['smtp']['is_primary']);
        $this->assertTrue($stats['mailers']['ses']['is_fallback']);
    }
}
