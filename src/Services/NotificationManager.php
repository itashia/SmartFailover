<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;
use JsonException;

final class NotificationManager
{
    private const NOTIFICATION_DISABLED = 0;
    private const DEFAULT_THROTTLE_MINUTES = 15;
    private const HTTP_TIMEOUT = 10;
    private const CACHE_PREFIX = 'smart_failover_throttle_';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Notify about service failure.
     */
    public function notifyFailure(Throwable $exception, array $context = []): void
    {
        if (!$this->isNotificationsEnabled()) {
            return;
        }

        $message = $this->formatFailureMessage($exception, $context);
        $throttleKey = $this->getThrottleKey($exception);

        if ($this->isThrottled($throttleKey)) {
            $this->logThrottledNotification($throttleKey, $exception);
            return;
        }

        $this->sendNotifications($message, $context);
        $this->setThrottle($throttleKey);
    }

    /**
     * Notify about service recovery.
     */
    public function notifyRecovery(string $service, array $context = []): void
    {
        if (!$this->isNotificationsEnabled()) {
            return;
        }

        $message = $this->formatRecoveryMessage($service, $context);
        $this->sendNotifications($message, $context, 'recovery');
    }

    /**
     * Check if notifications are enabled.
     */
    private function isNotificationsEnabled(): bool
    {
        return (bool) $this->config->get('smart-failover.notifications.enabled', false);
    }

    /**
     * Send notifications to all enabled channels.
     */
    private function sendNotifications(array $message, array $context = [], string $type = 'failure'): void
    {
        $channels = $this->config->get('smart-failover.notifications.channels', []);

        foreach ($this->getEnabledChannels($channels) as $channel => $config) {
            match ($channel) {
                'slack' => $this->sendSlackNotification($message, $config, $type),
                'telegram' => $this->sendTelegramNotification($message, $config, $type),
                'email' => $this->sendEmailNotification($message, $config, $type),
                default => null,
            };
        }
    }

    /**
     * Get enabled notification channels.
     */
    private function getEnabledChannels(array $channels): array
    {
        return array_filter($channels, fn($config) => $config['enabled'] ?? false);
    }

    /**
     * Send Slack notification.
     */
    private function sendSlackNotification(array $message, array $config, string $type): void
    {
        $webhookUrl = $config['webhook_url'] ?? null;

        if (!$webhookUrl) {
            $this->logger->warning('Slack webhook URL not configured');
            return;
        }

        try {
            $payload = $this->buildSlackPayload($message, $config, $type);
            
            Http::timeout(self::HTTP_TIMEOUT)
                ->post($webhookUrl, $payload);

            $this->logger->info('Slack notification sent', [
                'type' => $type,
                'channel' => $config['channel'] ?? '#alerts',
            ]);
        } catch (Throwable $e) {
            $this->logNotificationError('Slack', $e);
        }
    }

    /**
     * Build Slack notification payload.
     */
    private function buildSlackPayload(array $message, array $config, string $type): array
    {
        [$color, $emoji] = $this->getNotificationStyle($type);

        return [
            'channel' => $config['channel'] ?? '#alerts',
            'username' => $config['username'] ?? 'SmartFailover',
            'icon_emoji' => $emoji,
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $message['title'],
                    'text' => $message['description'],
                    'fields' => [
                        [
                            'title' => 'Service',
                            'value' => $message['service'] ?? 'Unknown',
                            'short' => true,
                        ],
                        [
                            'title' => 'Timestamp',
                            'value' => $message['timestamp'],
                            'short' => true,
                        ],
                    ],
                    'footer' => 'SmartFailover',
                    'ts' => time(),
                ],
            ],
        ];
    }

    /**
     * Send Telegram notification.
     */
    private function sendTelegramNotification(array $message, array $config, string $type): void
    {
        $botToken = $config['bot_token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if (!$botToken || !$chatId) {
            $this->logger->warning('Telegram bot token or chat ID not configured');
            return;
        }

        try {
            $text = $this->buildTelegramMessage($message, $type);
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

            Http::timeout(self::HTTP_TIMEOUT)
                ->post($url, [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);

            $this->logger->info('Telegram notification sent', [
                'type' => $type,
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            $this->logNotificationError('Telegram', $e);
        }
    }

    /**
     * Build Telegram message text.
     */
    private function buildTelegramMessage(array $message, string $type): string
    {
        $emoji = $type === 'failure' ? '⚠️' : '✅';
        
        return "{$emoji} *{$message['title']}*\n\n" .
               "{$message['description']}\n\n" .
               "*Service:* {$message['service']}\n" .
               "*Time:* {$message['timestamp']}";
    }

    /**
     * Send email notification.
     */
    private function sendEmailNotification(array $message, array $config, string $type): void
    {
        $to = $config['to'] ?? null;

        if (!$to) {
            $this->logger->warning('Email notification recipient not configured');
            return;
        }

        try {
            $subject = $this->buildEmailSubject($message, $type);
            $from = $config['from'] ?? 'noreply@example.com';

            Mail::raw($message['description'], function ($mail) use ($to, $from, $subject) {
                $mail->to($to)
                     ->from($from)
                     ->subject($subject);
            });

            $this->logger->info('Email notification sent', [
                'type' => $type,
                'to' => $to,
            ]);
        } catch (Throwable $e) {
            $this->logNotificationError('Email', $e);
        }
    }

    /**
     * Build email subject.
     */
    private function buildEmailSubject(array $message, string $type): string
    {
        $prefix = $type === 'failure' ? 'SmartFailover Alert' : 'SmartFailover Recovery';
        return "{$prefix}: {$message['title']}";
    }

    /**
     * Get notification style based on type.
     */
    private function getNotificationStyle(string $type): array
    {
        return match ($type) {
            'failure' => ['danger', ':warning:'],
            'recovery' => ['good', ':white_check_mark:'],
            default => ['#36a64f', ':information_source:']
        };
    }

    /**
     * Format failure message.
     */
    private function formatFailureMessage(Throwable $exception, array $context = []): array
    {
        $contextJson = $this->formatContext($context);

        return [
            'title' => 'Service Failure Detected',
            'description' => "A service failure has been detected:\n\n" .
                           "Error: {$exception->getMessage()}\n" .
                           "File: {$exception->getFile()}:{$exception->getLine()}\n" .
                           $contextJson,
            'service' => $context['service'] ?? 'Unknown',
            'timestamp' => now()->toISOString(),
            'severity' => 'high',
        ];
    }

    /**
     * Format recovery message.
     */
    private function formatRecoveryMessage(string $service, array $context = []): array
    {
        $contextJson = $this->formatContext($context);

        return [
            'title' => 'Service Recovery',
            'description' => "Service has recovered and is now operational:\n\n" .
                           "Service: {$service}\n" .
                           $contextJson,
            'service' => $service,
            'timestamp' => now()->toISOString(),
            'severity' => 'info',
        ];
    }

    /**
     * Format context data for messages.
     */
    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        try {
            return 'Context: ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException) {
            return 'Context: [Unable to encode context data]';
        }
    }

    /**
     * Get throttle key for exception.
     */
    private function getThrottleKey(Throwable $exception): string
    {
        $keyData = $exception->getMessage() . $exception->getFile() . $exception->getLine();
        return self::CACHE_PREFIX . md5($keyData);
    }

    /**
     * Check if notification is throttled.
     */
    private function isThrottled(string $throttleKey): bool
    {
        if (!$this->isThrottlingEnabled()) {
            return false;
        }

        return Cache::has($throttleKey);
    }

    /**
     * Check if throttling is enabled.
     */
    private function isThrottlingEnabled(): bool
    {
        return (bool) $this->config->get('smart-failover.notifications.throttle.enabled', true);
    }

    /**
     * Set throttle for notification.
     */
    private function setThrottle(string $throttleKey): void
    {
        if (!$this->isThrottlingEnabled()) {
            return;
        }

        $minutes = $this->config->get('smart-failover.notifications.throttle.minutes', self::DEFAULT_THROTTLE_MINUTES);
        Cache::put($throttleKey, true, now()->addMinutes($minutes));
    }

    /**
     * Log throttled notification.
     */
    private function logThrottledNotification(string $throttleKey, Throwable $exception): void
    {
        $this->logger->debug('Notification throttled', [
            'throttle_key' => $throttleKey,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Log notification error.
     */
    private function logNotificationError(string $channel, Throwable $e): void
    {
        $this->logger->error("Failed to send {$channel} notification", [
            'error' => $e->getMessage(),
        ]);
    }
}
