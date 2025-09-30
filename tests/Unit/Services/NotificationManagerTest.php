<?php

namespace Tests\Unit\Services;

use Mirzaaghazadeh\SmartFailover\Services\NotificationManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Exception;

class NotificationManagerTest extends TestCase
{
    private NotificationManager $manager;
    private Config $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->manager = new NotificationManager($this->config, $this->logger);
        
        // Clear any previous HTTP fakes
        Http::preventStrayRequests();
    }

    public function testNotifyFailureWhenNotificationsDisabled(): void
    {
        $this->config->method('get')
            ->with('smart-failover.notifications.enabled', false)
            ->willReturn(false);

        $this->logger->expects($this->never())
            ->method('info');

        $exception = new Exception('Test error');
        $this->manager->notifyFailure($exception);
    }

    public function testNotifyFailureWhenThrottled(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.notifications.enabled', false, true],
                ['smart-failover.notifications.throttle.enabled', true, true],
            ]);

        $exception = new Exception('Test error');
        $throttleKey = 'smart_failover_throttle_' . md5('Test error' . __FILE__ . $exception->getLine());

        Cache::shouldReceive('has')
            ->with($throttleKey)
            ->once()
            ->andReturn(true);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Notification throttled');

        $this->manager->notifyFailure($exception);
    }

    public function testSendSlackNotificationSuccess(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.notifications.enabled', false, true],
                ['smart-failover.notifications.throttle.enabled', true, false],
                ['smart-failover.notifications.channels.slack.webhook_url', null, 'https://hooks.slack.com/test'],
                ['smart-failover.notifications.channels.slack.channel', '#alerts', '#test-channel'],
                ['smart-failover.notifications.channels.slack.username', 'SmartFailover', 'TestBot'],
            ]);

        Http::fake([
            'hooks.slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Slack notification sent');

        $exception = new Exception('Test error');
        $this->manager->notifyFailure($exception);
    }

    public function testSendTelegramNotificationSuccess(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.notifications.enabled', false, true],
                ['smart-failover.notifications.throttle.enabled', true, false],
                ['smart-failover.notifications.channels.telegram.bot_token', null, 'test_bot_token'],
                ['smart-failover.notifications.channels.telegram.chat_id', null, 'test_chat_id'],
            ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Telegram notification sent');

        $exception = new Exception('Test error');
        $this->manager->notifyFailure($exception);
    }

    public function testSendEmailNotificationSuccess(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.notifications.enabled', false, true],
                ['smart-failover.notifications.throttle.enabled', true, false],
                ['smart-failover.notifications.channels.email.to', null, 'admin@example.com'],
                ['smart-failover.notifications.channels.email.from', 'noreply@example.com', 'alerts@example.com'],
            ]);

        Mail::fake();

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Email notification sent');

        $exception = new Exception('Test error');
        $this->manager->notifyFailure($exception);

        Mail::assertSent(function ($mail) {
            return $mail->hasTo('admin@example.com') &&
                   $mail->subject === 'SmartFailover Alert: Service Failure Detected';
        });
    }

    public function testNotifyRecovery(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['smart-failover.notifications.enabled', false, true],
                ['smart-failover.notifications.throttle.enabled', true, false],
                ['smart-failover.notifications.channels.slack.enabled', false, true],
                ['smart-failover.notifications.channels.slack.webhook_url', null, 'https://hooks.slack.com/test'],
            ]);

        Http::fake([
            'hooks.slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Slack notification sent');

        $this->manager->notifyRecovery('database', ['recovery_time' => '5s']);
    }

    public function testFormatContextWithValidData(): void
    {
        $context = ['service' => 'database', 'error_count' => 5];
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('formatContext');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->manager, $context);
        
        $this->assertStringContainsString('Context:', $result);
        $this->assertStringContainsString('"service": "database"', $result);
    }

    public function testFormatContextWithEmptyData(): void
    {
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('formatContext');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->manager, []);
        
        $this->assertEquals('', $result);
    }
}
