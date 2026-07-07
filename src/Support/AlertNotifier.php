<?php

declare(strict_types=1);

namespace CentralMailer\Support;

use CentralMailer\Config\Env;
use CentralMailer\Queue\EmailQueueRepository;
use PDO;
use Psr\Log\LoggerInterface;

final class AlertNotifier
{
    public const SOURCE_APP = 'central-mailer';

    private readonly ?string $alertEmail;
    private readonly ?string $webhookUrl;
    private readonly int $throttleSeconds;

    public function __construct(
        private readonly Env $env,
        private readonly LoggerInterface $logger,
        private readonly ?EmailQueueRepository $repository = null,
        private readonly ?PDO $pdo = null,
        private readonly ?string $statePath = null,
        private readonly ?\Closure $clock = null
    ) {
        $this->alertEmail = $this->env->nullableString('ALERT_EMAIL');
        $this->webhookUrl = $this->env->nullableString('ALERT_WEBHOOK_URL');
        $this->throttleSeconds = max(0, $this->env->int('ALERT_THROTTLE_SECONDS', 3600));
    }

    /** @param array<string, mixed> $context */
    public function notify(string $type, string $message, array $context = []): void
    {
        if ($this->alertEmail === null && $this->webhookUrl === null) {
            return;
        }
        if (!$this->markSentIfDue($type)) {
            return;
        }

        if ($this->webhookUrl !== null) {
            $this->sendWebhook($type, $message, $context);
        }
        if ($this->alertEmail !== null) {
            $this->enqueueAlertEmail($type, $message, $context);
        }
    }

    private function markSentIfDue(string $type): bool
    {
        if ($this->statePath === null || $this->throttleSeconds === 0) {
            return true;
        }

        $now = $this->clock !== null ? ($this->clock)() : time();
        $state = [];
        if (is_file($this->statePath)) {
            $decoded = json_decode((string) file_get_contents($this->statePath), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        $lastSentAt = (int) ($state['lastSentAt'][$type] ?? 0);
        if ($lastSentAt > 0 && ($now - $lastSentAt) < $this->throttleSeconds) {
            return false;
        }

        $state['lastSentAt'][$type] = $now;
        file_put_contents($this->statePath, json_encode($state, JSON_THROW_ON_ERROR));

        return true;
    }

    /** @param array<string, mixed> $context */
    private function sendWebhook(string $type, string $message, array $context): void
    {
        try {
            $payload = json_encode([
                'event' => $type,
                'message' => $message,
                'context' => $context,
                'hostname' => gethostname() ?: 'unknown',
                'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR);
            $streamContext = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            if (@file_get_contents($this->webhookUrl, false, $streamContext) === false) {
                $this->logger->warning('Alert webhook request failed', ['type' => $type]);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Alert webhook request failed', [
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function enqueueAlertEmail(string $type, string $message, array $context): void
    {
        if ($this->repository === null || $this->pdo === null) {
            return;
        }

        try {
            $this->ensureAlertClient();
            $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $body = sprintf("%s\n\nContext:\n%s", $message, $contextJson);
            $this->repository->insert([
                'sourceApp' => self::SOURCE_APP,
                'idempotencyKey' => null,
                'to' => $this->alertEmail,
                'subject' => sprintf('[central-mailer] Alert: %s', $type),
                'html' => '<pre>' . htmlspecialchars($body, ENT_QUOTES) . '</pre>',
                'text' => $body,
                'priority' => 'technical',
                'metadata' => ['alertType' => $type],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to enqueue alert email', [
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ensureAlertClient(): void
    {
        $existing = $this->pdo->prepare('SELECT 1 FROM email_clients WHERE source_app = :source_app');
        $existing->execute(['source_app' => self::SOURCE_APP]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO email_clients
                 (source_app, api_key_hash, active, queue_weight, queue_credit, created_at, updated_at)
                 VALUES
                 (:source_app, :api_key_hash, 1, 1, 0, :created_at, :updated_at)'
            );
            $stmt->execute([
                'source_app' => self::SOURCE_APP,
                // Hash of random bytes: no API key can ever authenticate as this internal client.
                'api_key_hash' => hash('sha256', bin2hex(random_bytes(32))),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }
}
