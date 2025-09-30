<?php

namespace Mirzaaghazadeh\SmartFailover\Services;

use Exception;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

final class MailFailoverManager
{
    private const DEFAULT_RETRY_ATTEMPTS = 3;
    private const DEFAULT_RETRY_DELAY_MS = 1000;
    private const HEALTH_STATUS_HEALTHY = 'healthy';
    private const HEALTH_STATUS_UNHEALTHY = 'unhealthy';
    private const HEALTH_CHECK_TTL = 300; // 5 minutes
    private const SMTP_REQUIRED_KEYS = ['host', 'port'];

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly MailManager $mailManager
    ) {
        $this->loadConfiguration();
    }

    /**
     * Set mailers for failover.
     */
    public function setMailers(string $primary, array $fallbacks = []): self
    {
        $this->primaryMailer = $primary;
        $this->fallbackMailers = $fallbacks;
        $this->mailers = [$primary, ...$fallbacks];

        return $this;
    }

    /**
     * Send mail with failover protection.
     */
    public function send(mixed $mailable, ?callable $callback = null): bool
    {
        foreach ($this->getAvailableMailers() as $mailerName) {
            try {
                $this->logger->info("Attempting to send mail via {$mailerName}");

                $this->switchToMailer($mailerName);
                $result = $this->executeMailOperation($mailerName, $mailable, $callback);

                $this->markMailerHealthy($mailerName);
                $this->logger->info("Mail sent successfully via {$mailerName}");

                return $result;

            } catch (Throwable $e) {
                $this->handleMailerError($mailerName, $e, 'Mail failed');
                continue;
            }
        }

        $this->logger->error('All mail services failed');
        throw new Exception('All configured mail services are unavailable');
    }

    /**
     * Execute mail operation with proper callback handling.
     */
    private function executeMailOperation(string $mailerName, mixed $mailable, ?callable $callback): bool
    {
        if ($callback) {
            $result = $callback($this->mailManager->mailer($mailerName));
            return $result !== false;
        }

        Mail::mailer($mailerName)->send($mailable);
        return true;
    }

    /**
     * Queue mail with failover protection.
     */
    public function queue(mixed $mailable, ?string $queue = null): bool
    {
        foreach ($this->getAvailableMailers() as $mailerName) {
            try {
                $this->logger->info("Attempting to queue mail via {$mailerName}");

                $this->switchToMailer($mailerName);
                $this->executeQueueOperation($mailerName, $mailable, $queue);

                $this->markMailerHealthy($mailerName);
                $this->logger->info("Mail queued successfully via {$mailerName}");

                return true;

            } catch (Throwable $e) {
                $this->handleMailerError($mailerName, $e, 'Mail queue failed');
                continue;
            }
        }

        $this->logger->error('All mail services failed for queuing');
        throw new Exception('All configured mail services are unavailable for queuing');
    }

    /**
     * Execute queue operation.
     */
    private function executeQueueOperation(string $mailerName, mixed $mailable, ?string $queue): void
    {
        $mailer = Mail::mailer($mailerName);

        if ($queue) {
            $mailer->queue($mailable, $queue);
        } else {
            $mailer->queue($mailable);
        }
    }

    /**
     * Check health of all configured mailers.
     */
    public function checkHealth(): array
    {
        $health = [];

        foreach ($this->mailers as $mailerName) {
            $health[$mailerName] = $this->checkMailerHealth($mailerName);
        }

        return $health;
    }

    /**
     * Check health of a specific mailer.
     */
    private function checkMailerHealth(string $mailerName): array
    {
        $startTime = microtime(true);

        try {
            $mailerConfig = $this->getMailerConfig($mailerName);
            $this->validateMailerConfiguration($mailerName, $mailerConfig);
            
            $this->mailManager->mailer($mailerName); // Test mailer instantiation

            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'status' => self::HEALTH_STATUS_HEALTHY,
                'response_time' => $responseTime,
                'transport' => $mailerConfig['transport'] ?? 'unknown',
                'last_checked' => now()->toISOString(),
            ];

        } catch (Throwable $e) {
            $responseTime = $this->calculateResponseTime($startTime);

            return [
                'status' => self::HEALTH_STATUS_UNHEALTHY,
                'response_time' => $responseTime,
                'error' => $e->getMessage(),
                'last_checked' => now()->toISOString(),
            ];
        }
    }

    /**
     * Get mailer configuration.
     */
    private function getMailerConfig(string $mailerName): array
    {
        $mailerConfig = $this->config->get("mail.mailers.{$mailerName}");

        if (!$mailerConfig) {
            throw new Exception("Mailer {$mailerName} not configured");
        }

        return $mailerConfig;
    }

    /**
     * Validate mailer configuration.
     */
    private function validateMailerConfiguration(string $mailerName, array $mailerConfig): void
    {
        if (($mailerConfig['transport'] ?? '') === 'smtp') {
            $this->validateSmtpConfiguration($mailerConfig);
        }
    }

    /**
     * Validate SMTP configuration.
     */
    private function validateSmtpConfiguration(array $mailerConfig): void
    {
        foreach (self::SMTP_REQUIRED_KEYS as $key) {
            if (empty($mailerConfig[$key])) {
                throw new Exception("Missing required SMTP configuration: {$key}");
            }
        }
    }

    /**
     * Calculate response time in milliseconds.
     */
    private function calculateResponseTime(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000.0, 2);
    }

    /**
     * Get available mailers in priority order.
     */
    private function getAvailableMailers(): array
    {
        $available = [];

        // Add primary mailer if healthy
        if ($this->primaryMailer && $this->isMailerHealthy($this->primaryMailer)) {
            $available[] = $this->primaryMailer;
        }

        // Add healthy fallback mailers
        foreach ($this->fallbackMailers as $mailer) {
            if ($this->isMailerHealthy($mailer)) {
                $available[] = $mailer;
            }
        }

        // If no healthy mailers, try all configured mailers as last resort
        return empty($available) ? $this->mailers : $available;
    }

    /**
     * Switch to a specific mailer.
     */
    private function switchToMailer(string $mailerName): void
    {
        // Set the default mailer
        $this->config->set('mail.default', $mailerName);

        // Purge the mailer instance to force recreation
        $this->mailManager->purge($mailerName);
    }

    /**
     * Check if mailer is healthy.
     */
    private function isMailerHealthy(string $mailerName): bool
    {
        if (!isset($this->healthStatus[$mailerName])) {
            return true; // Assume healthy if not checked yet
        }

        $status = $this->healthStatus[$mailerName];

        if ($status['status'] === self::HEALTH_STATUS_UNHEALTHY) {
            $lastFailure = $status['last_failure'] ?? 0;
            return (time() - $lastFailure) > self::HEALTH_CHECK_TTL;
        }

        return $status['status'] === self::HEALTH_STATUS_HEALTHY;
    }

    /**
     * Mark mailer as healthy.
     */
    private function markMailerHealthy(string $mailerName): void
    {
        $this->healthStatus[$mailerName] = [
            'status' => self::HEALTH_STATUS_HEALTHY,
            'last_success' => time(),
        ];
    }

    /**
     * Mark mailer as unhealthy.
     */
    private function markMailerUnhealthy(string $mailerName, string $error): void
    {
        $this->healthStatus[$mailerName] = [
            'status' => self::HEALTH_STATUS_UNHEALTHY,
            'last_failure' => time(),
            'error' => $error,
        ];
    }

    /**
     * Handle mailer error with consistent logging and marking.
     */
    private function handleMailerError(string $mailerName, Throwable $e, string $context): void
    {
        $this->markMailerUnhealthy($mailerName, $e->getMessage());
        $this->logger->error("{$context} via {$mailerName}: " . $e->getMessage());
    }

    /**
     * Load configuration.
     */
    private function loadConfiguration(): void
    {
        $config = $this->config->get('smart-failover.mail', []);

        $this->primaryMailer = $config['primary'] ?? null;
        $this->fallbackMailers = $config['fallbacks'] ?? [];
        $this->retryAttempts = $config['retry_attempts'] ?? self::DEFAULT_RETRY_ATTEMPTS;
        $this->retryDelay = $config['retry_delay'] ?? self::DEFAULT_RETRY_DELAY_MS;

        $this->mailers = array_filter([
            $this->primaryMailer,
            ...$this->fallbackMailers
        ]);
    }

    /**
     * Get current mailer status.
     */
    public function getStatus(): array
    {
        return [
            'primary' => $this->primaryMailer,
            'fallbacks' => $this->fallbackDisks,
            'health' => $this->healthStatus,
            'available_mailers' => $this->getAvailableMailers(),
        ];
    }

    /**
     * Get statistics about mail operations.
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_mailers' => count($this->mailers),
            'healthy_mailers' => 0,
            'unhealthy_mailers' => 0,
            'mailers' => [],
        ];

        foreach ($this->mailers as $mailerName) {
            $isHealthy = $this->isMailerHealthy($mailerName);
            
            if ($isHealthy) {
                $stats['healthy_mailers']++;
            } else {
                $stats['unhealthy_mailers']++;
            }

            $stats['mailers'][$mailerName] = [
                'healthy' => $isHealthy,
                'is_primary' => $mailerName === $this->primaryMailer,
                'is_fallback' => in_array($mailerName, $this->fallbackMailers, true),
            ];
        }

        return $stats;
    }
}
