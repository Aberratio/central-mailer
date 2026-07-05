<?php

declare(strict_types=1);

namespace CentralMailer\Controllers;

use CentralMailer\Config\Env;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class EmailController
{
    public function __construct(
        private readonly EmailQueueService $queueService,
        private readonly EmailQueueRepository $repository,
        private readonly ?EmailWorker $worker = null,
        private readonly ?RateLimitRepository $rateLimitRepository = null,
        private readonly ?WorkerHeartbeatRepository $heartbeatRepository = null,
        private readonly ?Env $env = null
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        [$from, $to] = $this->dateRange($request);

        return $this->json([
            'from' => $from,
            'to' => $to,
            'emails' => array_map(
                fn (array $row): array => $this->emailStatusPayload($row),
                $this->repository->findForSourceAppBetween($sourceApp, $from, $to)
            ),
        ]);
    }

    public function unsent(ServerRequestInterface $request): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');

        return $this->json([
            'emails' => array_map(
                fn (array $row): array => $this->emailStatusPayload($row),
                $this->repository->findUnsentForSourceApp($sourceApp)
            ),
        ]);
    }

    public function diagnostics(ServerRequestInterface $request): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $now = self::now();
        $globalWindowMinutes = $this->env?->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15) ?? 15;
        $globalLimit = $this->env?->int('EMAIL_RATE_LIMIT_COUNT', 100) ?? 100;
        $clientLimit = $this->repository->clientRateLimitForSourceApp($sourceApp);
        $clientWindowMinutes = $clientLimit['rateLimitWindowMinutes'] ?? $globalWindowMinutes;
        $heartbeatFreshSeconds = $this->heartbeatFreshSeconds();
        $heartbeatFreshSince = (new \DateTimeImmutable(sprintf('-%d seconds', $heartbeatFreshSeconds)))->format('Y-m-d H:i:s');
        $activeWorkers = $this->heartbeatRepository?->findActiveSince($heartbeatFreshSince) ?? [];
        $workerCounts = $this->heartbeatRepository?->activeCountsSince($heartbeatFreshSince) ?? ['standard' => 0, 'technical' => 0];
        $globalSince = (new \DateTimeImmutable(sprintf('-%d minutes', $globalWindowMinutes)))->format('Y-m-d H:i:s');
        $clientSince = (new \DateTimeImmutable(sprintf('-%d minutes', $clientWindowMinutes)))->format('Y-m-d H:i:s');

        return $this->json([
            'sourceApp' => $sourceApp,
            'generatedAt' => $now,
            'statusCounts' => $this->repository->statusCountsForSourceApp($sourceApp),
            'backlog' => [
                'oldestUnsent' => $this->repository->oldestUnsentForSourceApp($sourceApp),
                'nextDelayedAt' => $this->repository->nextDelayedAttemptForSourceApp($sourceApp, $now),
                'technicalBlocker' => $this->repository->technicalBlockerForSourceApp($sourceApp),
            ],
            'rateLimit' => [
                'global' => $this->rateLimitRepository?->globalUsage($globalLimit, $globalSince, $globalWindowMinutes),
                'client' => $this->rateLimitRepository?->clientUsage(
                    $sourceApp,
                    $clientLimit['rateLimitCount'] ?? null,
                    $clientSince,
                    $clientWindowMinutes
                ),
            ],
            'workers' => [
                'heartbeatFreshSeconds' => $heartbeatFreshSeconds,
                'standardActive' => $workerCounts['standard'] > 0,
                'technicalActive' => $workerCounts['technical'] > 0,
                'active' => array_map(static fn (array $row): array => [
                    'workerId' => $row['worker_id'],
                    'queue' => $row['queue'],
                    'host' => $row['host'],
                    'processId' => $row['process_id'] === null ? null : (int) $row['process_id'],
                    'startedAt' => $row['started_at'],
                    'lastSeenAt' => $row['last_seen_at'],
                    'lastProcessedAt' => $row['last_processed_at'],
                    'lastError' => $row['last_error'],
                    'processedCount' => (int) $row['processed_count'],
                ], $activeWorkers),
            ],
        ]);
    }

    public function runWorker(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->worker === null) {
            return $this->json(['error' => 'Worker is not available in this runtime'], 503);
        }

        $processed = $this->worker->runOnce();

        return $this->json(['status' => 'ok', 'queue' => 'standard', 'processed' => $processed]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->payload($request);
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $result = $this->queueService->enqueue($sourceApp, $payload, $request->getHeaderLine('Idempotency-Key'));

        return $this->json(['id' => $result->id, 'status' => $result->status], $result->created ? 201 : 200);
    }

    public function batch(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->payload($request);
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $result = $this->queueService->enqueueBatch($sourceApp, $payload, $request->getHeaderLine('Idempotency-Key'));

        return $this->json([
            'id' => $result->id,
            'emails' => $result->emails,
        ], $result->created ? 201 : 200);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $row = $this->repository->findForSourceApp($args['id'], $sourceApp);

        if ($row === null) {
            return $this->json(['error' => 'Email not found'], 404);
        }

        return $this->json($this->emailStatusPayload($row));
    }

    /** @param array<string, string> $args */
    public function showBatch(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $batch = $this->repository->findBatchForSourceApp($args['id'], $sourceApp);

        if ($batch === null) {
            return $this->json(['error' => 'Email batch not found'], 404);
        }

        $emails = array_map(
            fn (array $row): array => $this->emailStatusPayload($row),
            $this->repository->findBatchEmailsForSourceApp($args['id'], $sourceApp)
        );

        return $this->json([
            'id' => $batch['id'],
            'sourceApp' => $batch['source_app'],
            'subject' => $batch['subject'],
            'createdAt' => $batch['created_at'],
            'statusCounts' => $this->statusCounts($emails),
            'providerAcceptedCount' => count(array_filter(
                $emails,
                static fn (array $email): bool => $email['providerAcceptanceStatus'] === 'accepted'
            )),
            'deliveryStatus' => $this->batchDeliveryStatus($emails),
            'emails' => $emails,
        ]);
    }

    /** @param array<string, string> $args */
    public function events(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        if ($this->repository->findForSourceApp($args['id'], $sourceApp) === null) {
            return $this->json(['error' => 'Email not found'], 404);
        }

        $events = array_map(static function (array $row): array {
            return [
                'type' => $row['event_type'],
                'status' => $row['status'],
                'attempt' => (int) $row['attempt'],
                'errorCode' => $row['error_code'],
                'errorMessage' => $row['error_message'],
                'providerMessageId' => $row['provider_message_id'],
                'details' => $row['details'] === null ? null : json_decode($row['details'], true, flags: JSON_THROW_ON_ERROR),
                'createdAt' => $row['created_at'],
            ];
        }, $this->repository->findEventsForSourceApp($args['id'], $sourceApp));

        return $this->json(['id' => $args['id'], 'events' => $events]);
    }

    /** @param array<string, string> $args */
    public function batchEvents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        if ($this->repository->findBatchForSourceApp($args['id'], $sourceApp) === null) {
            return $this->json(['error' => 'Email batch not found'], 404);
        }

        $events = array_map(static function (array $row): array {
            return [
                'emailId' => $row['email_id'],
                'type' => $row['event_type'],
                'status' => $row['status'],
                'attempt' => (int) $row['attempt'],
                'errorCode' => $row['error_code'],
                'errorMessage' => $row['error_message'],
                'providerMessageId' => $row['provider_message_id'],
                'details' => $row['details'] === null ? null : json_decode($row['details'], true, flags: JSON_THROW_ON_ERROR),
                'createdAt' => $row['created_at'],
            ];
        }, $this->repository->findBatchEventsForSourceApp($args['id'], $sourceApp));

        return $this->json(['id' => $args['id'], 'events' => $events]);
    }

    /** @return array<string, mixed> */
    private function payload(ServerRequestInterface $request): array
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON object body is required', 400);
        }

        return $payload;
    }

    /** @return array{0: string, 1: string} */
    private function dateRange(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $today = new \DateTimeImmutable('today');
        $from = $this->dateTimeQuery($query['from'] ?? null, $today->format('Y-m-d 00:00:00'));
        $to = $this->dateTimeQuery($query['to'] ?? null, $today->format('Y-m-d 23:59:59'));

        if ($from > $to) {
            throw new \InvalidArgumentException('from must be earlier than or equal to to', 400);
        }

        return [$from, $to];
    }

    private function dateTimeQuery(mixed $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Date range values must be strings', 400);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date !== false && $date->format('Y-m-d') === $value) {
            return $date->format('Y-m-d') . (str_ends_with($default, '23:59:59') ? ' 23:59:59' : ' 00:00:00');
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($dateTime === false || $dateTime->format('Y-m-d H:i:s') !== $value) {
            throw new \InvalidArgumentException('Date range values must use Y-m-d or Y-m-d H:i:s format', 400);
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function emailStatusPayload(array $row): array
    {
        $now = new \DateTimeImmutable();
        $delay = $this->delay($row, $now);

        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'providerAcceptanceStatus' => $row['status'] === 'sent' ? 'accepted' : null,
            'deliveryStatus' => $row['status'] === 'sent' ? 'unknown' : null,
            'sourceApp' => $row['source_app'],
            'to' => $row['recipient_email'],
            'subject' => $row['subject'],
            'priority' => $row['priority'],
            'attempts' => (int) $row['attempts'],
            'failedAttempts' => (int) $row['attempts'],
            'sendAttempts' => (int) ($row['send_attempts'] ?? 0),
            'nextAttemptAt' => $row['next_attempt_at'] ?? null,
            'leaseExpiresAt' => $row['lease_expires_at'] ?? null,
            'delayReason' => $delay['reason'],
            'delayUntil' => $delay['until'],
            'queueAgeSeconds' => self::secondsSince((string) $row['created_at'], $now),
            'processingAgeSeconds' => $row['status'] === 'processing'
                ? self::secondsSince((string) $row['updated_at'], $now)
                : null,
            'lastEventType' => $row['last_event_type'] ?? null,
            'lastEventAt' => $row['last_event_at'] ?? null,
            'lastError' => $row['last_error'],
            'providerMessageId' => $row['provider_message_id'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'sentAt' => $row['sent_at'],
            'batchId' => $row['batch_id'],
        ];
    }

    /** @param list<array<string, mixed>> $emails @return array<string, int> */
    private function statusCounts(array $emails): array
    {
        $counts = [
            'pending' => 0,
            'processing' => 0,
            'retry' => 0,
            'sent' => 0,
            'failed' => 0,
        ];
        foreach ($emails as $email) {
            $status = (string) $email['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $emails */
    private function batchDeliveryStatus(array $emails): string
    {
        if ($emails === []) {
            return 'empty';
        }

        $counts = $this->statusCounts($emails);
        if ($counts['failed'] > 0) {
            return 'failed';
        }
        if ($counts['pending'] > 0 || $counts['processing'] > 0 || $counts['retry'] > 0) {
            return 'in_progress';
        }

        return 'provider_accepted';
    }

    /** @param array<string, mixed> $row @return array{reason: string|null, until: string|null} */
    private function delay(array $row, \DateTimeImmutable $now): array
    {
        $status = (string) $row['status'];
        $nextAttemptAt = $row['next_attempt_at'] ?? null;
        if (($status === 'pending' || $status === 'retry') && is_string($nextAttemptAt) && $nextAttemptAt !== '') {
            $nextAttempt = self::dateTime($nextAttemptAt);
            if ($nextAttempt !== null && $nextAttempt > $now) {
                $lastEventType = $row['last_event_type'] ?? null;

                return [
                    'reason' => $lastEventType === 'rate_limited'
                        ? 'rate_limited'
                        : ($status === 'retry' ? 'retry_backoff' : 'scheduled'),
                    'until' => $nextAttemptAt,
                ];
            }
        }

        if ($status === 'processing') {
            $leaseExpiresAt = $row['lease_expires_at'] ?? null;
            if (is_string($leaseExpiresAt) && $leaseExpiresAt !== '') {
                $leaseExpires = self::dateTime($leaseExpiresAt);
                if ($leaseExpires !== null && $leaseExpires < $now) {
                    return ['reason' => 'stale_processing', 'until' => $leaseExpiresAt];
                }

                return ['reason' => 'processing', 'until' => $leaseExpiresAt];
            }
        }

        if ($status === 'pending') {
            return ['reason' => 'awaiting_worker', 'until' => null];
        }
        if ($status === 'retry') {
            return ['reason' => 'retry_due', 'until' => null];
        }

        return ['reason' => null, 'until' => null];
    }

    private function heartbeatFreshSeconds(): int
    {
        if ($this->env === null) {
            return 60;
        }

        return max(
            10,
            $this->env->int('EMAIL_WORKER_HEARTBEAT_STALE_SECONDS', 60),
            $this->env->int('EMAIL_WORKER_SLEEP_SECONDS', 10) * 3,
            $this->env->int('TECHNICAL_EMAIL_WORKER_SLEEP_SECONDS', 10) * 3
        );
    }

    private static function secondsSince(string $value, \DateTimeImmutable $now): ?int
    {
        $date = self::dateTime($value);
        if ($date === null) {
            return null;
        }

        return max(0, $now->getTimestamp() - $date->getTimestamp());
    }

    private static function dateTime(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
