<?php

declare(strict_types=1);

namespace CentralMailer\Controllers;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Http\ApiVersion;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use CentralMailer\Suppression\SuppressionRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class AdminController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly EmailQueueRepository $repository,
        private readonly RateLimitRepository $rateLimitRepository,
        private readonly WorkerHeartbeatRepository $heartbeatRepository,
        private readonly Env $env,
        private readonly ?SuppressionRepository $suppressions = null
    ) {
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $now = self::now();
        $heartbeatFreshSeconds = $this->heartbeatFreshSeconds();
        $heartbeatFreshSince = (new \DateTimeImmutable(sprintf('-%d seconds', $heartbeatFreshSeconds)))->format('Y-m-d H:i:s');
        $workerCounts = $this->heartbeatRepository->activeCountsSince($heartbeatFreshSince);
        $activeWorkers = $this->heartbeatRepository->findActiveSince($heartbeatFreshSince);
        $workersOk = $workerCounts['standard'] > 0 && $workerCounts['technical'] > 0;
        $databaseStatus = 'ok';
        $attachmentsStatus = 'writable';

        try {
            $this->pdo->query('SELECT 1');
        } catch (\Throwable) {
            $databaseStatus = 'error';
        }

        try {
            $this->attachmentStorage->assertWritable();
        } catch (\Throwable) {
            $attachmentsStatus = 'error';
        }

        $globalWindowMinutes = $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15);
        $globalLimit = $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100);
        $globalSince = (new \DateTimeImmutable(sprintf('-%d minutes', $globalWindowMinutes)))->format('Y-m-d H:i:s');
        $statusCounts = $this->repository->globalStatusCounts();
        $backlog = [
            'oldestUnsent' => $this->repository->oldestUnsentGlobal(),
            'nextDelayedAt' => $this->repository->nextDelayedAttemptGlobal($now),
            'technicalBlocker' => $this->repository->technicalBlockerGlobal(),
            'staleProcessingCount' => $this->repository->staleProcessingCount($now),
        ];
        $rateLimit = $this->rateLimitRepository->globalUsage($globalLimit, $globalSince, $globalWindowMinutes);
        $failuresSince = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');

        return $this->json([
            'generatedAt' => $now,
            'health' => [
                'status' => $databaseStatus === 'ok' && $attachmentsStatus === 'writable' && $workersOk ? 'ok' : 'degraded',
                'apiVersion' => ApiVersion::VERSION,
                'checks' => [
                    'database' => $databaseStatus,
                    'attachments' => $attachmentsStatus,
                    'workers' => $workersOk ? 'ok' : 'degraded',
                ],
            ],
            'statusCounts' => [
                'global' => $statusCounts,
                'bySourceApp' => $this->repository->statusCountsBySourceApp(),
            ],
            'backlog' => $backlog,
            'failures' => [
                'since' => $failuresSince,
                'errorCodes' => $this->repository->errorCodeCountsSince($failuresSince),
            ],
            'rateLimit' => [
                'global' => $rateLimit,
            ],
            'mailers' => $this->mailers(),
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
            'issues' => $this->issues($statusCounts, $backlog, $rateLimit, $workerCounts),
        ]);
    }

    public function unsent(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int) $query['limit'] : 100;
        $limit = min(500, max(1, $limit));
        $now = new \DateTimeImmutable();

        return $this->json([
            'generatedAt' => self::now(),
            'limit' => $limit,
            'emails' => array_map(
                fn (array $row): array => $this->emailPayload($row, $now),
                $this->repository->findUnsentGlobal($limit)
            ),
        ]);
    }

    public function sent(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int) $query['limit'] : 100;
        $limit = min(500, max(1, $limit));
        $now = new \DateTimeImmutable();

        return $this->json([
            'generatedAt' => self::now(),
            'limit' => $limit,
            'emails' => array_map(
                fn (array $row): array => $this->emailPayload($row, $now),
                $this->repository->findSentGlobal($limit)
            ),
        ]);
    }

    public function suppressions(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->suppressions === null) {
            return $this->json(['error' => 'suppressions_unavailable'], 503);
        }
        $query = $request->getQueryParams();
        $limit = min(1000, max(1, isset($query['limit']) ? (int) $query['limit'] : 500));

        return $this->json([
            'generatedAt' => self::now(),
            'suppressions' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'email' => $row['email'],
                'sourceApp' => $row['source_app'] === '' ? null : $row['source_app'],
                'reason' => $row['reason'],
                'appliesTo' => $row['applies_to'],
                'originEmailId' => $row['origin_email_id'],
                'details' => $row['details'],
                'createdAt' => $row['created_at'],
            ], $this->suppressions->all($limit)),
        ]);
    }

    public function addSuppression(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->suppressions === null) {
            return $this->json(['error' => 'suppressions_unavailable'], 503);
        }
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'invalid_email', 'message' => 'Email address is invalid'], 400);
        }
        $reason = (string) ($body['reason'] ?? 'manual');
        $appliesTo = (string) ($body['appliesTo'] ?? 'all');
        $sourceApp = trim((string) ($body['sourceApp'] ?? ''));
        try {
            $created = $this->suppressions->add($email, $reason, $appliesTo, $sourceApp);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => 'invalid_suppression', 'message' => $exception->getMessage()], 400);
        }

        return $this->json(['email' => mb_strtolower($email), 'created' => $created], $created ? 201 : 200);
    }

    /** @param array<string, string> $args */
    public function removeSuppression(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($this->suppressions === null) {
            return $this->json(['error' => 'suppressions_unavailable'], 503);
        }
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || !$this->suppressions->remove($id)) {
            return $this->json(['error' => 'not_found', 'message' => 'Suppression does not exist'], 404);
        }

        return $this->json(['id' => $id, 'removed' => true]);
    }

    /** @param array<string, string> $args */
    public function requeue(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || !$this->repository->requeueUnknown($id)) {
            return $this->json([
                'error' => 'not_requeueable',
                'message' => 'Email does not exist or is not in unknown status',
            ], 409);
        }

        return $this->json(['id' => $id, 'status' => 'pending']);
    }

    /** @param array<string, int> $statusCounts @param array<string, mixed> $backlog @param array<string, mixed> $rateLimit @param array{standard: int, technical: int} $workerCounts @return list<array<string, mixed>> */
    private function issues(array $statusCounts, array $backlog, array $rateLimit, array $workerCounts): array
    {
        $issues = [];
        if ($workerCounts['standard'] === 0) {
            $issues[] = [
                'severity' => 'critical',
                'label' => 'Krytyczny',
                'type' => 'worker_missing',
                'title' => 'Brak aktywnego workera standardowego',
                'message' => 'Nie ma swiezego heartbeat dla kolejki standardowej. Wiadomosci normalne i wysokiego priorytetu moga nie wychodzic.',
            ];
        }
        if ($workerCounts['technical'] === 0) {
            $issues[] = [
                'severity' => 'critical',
                'label' => 'Krytyczny',
                'type' => 'worker_missing',
                'title' => 'Brak aktywnego workera technicznego',
                'message' => 'Nie ma swiezego heartbeat dla kolejki technicznej. Wiadomosci techniczne FIFO moga stac w kolejce.',
            ];
        }
        if (($backlog['staleProcessingCount'] ?? 0) > 0) {
            $issues[] = [
                'severity' => 'critical',
                'label' => 'Krytyczny',
                'type' => 'stale_processing',
                'title' => 'Wiadomosci utknely w przetwarzaniu',
                'message' => 'Czesc wiadomosci ma wygasnieta dzierzawe przetwarzania. Worker powinien je zwolnic przy kolejnym przebiegu.',
                'count' => $backlog['staleProcessingCount'],
            ];
        }
        if (($statusCounts['unknown'] ?? 0) > 0) {
            $issues[] = [
                'severity' => 'critical',
                'label' => 'Krytyczny',
                'type' => 'unknown_emails',
                'title' => 'Wiadomosci w kwarantannie (nieznany wynik wysylki)',
                'message' => 'Proba wysylki mogla dotrzec do serwera SMTP, ale wynik jest nieznany. Sprawdz logi i skrzynke nadawcy, a potem uzyj przycisku ponownego kolejkowania.',
                'count' => $statusCounts['unknown'],
            ];
        }
        if (($statusCounts['failed'] ?? 0) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'label' => 'Ostrzezenie',
                'type' => 'failed_emails',
                'title' => 'Sa trwale nieudane wysylki',
                'message' => 'Te wiadomosci wyczerpaly limit prob i nie beda juz wysylane automatycznie.',
                'count' => $statusCounts['failed'],
            ];
        }
        if (($statusCounts['retry'] ?? 0) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'label' => 'Ostrzezenie',
                'type' => 'retry_backlog',
                'title' => 'Wiadomosci czekaja na ponowna probe',
                'message' => 'Czesc wysylek czeka na ponowna probe. To zwykle oznacza blad SMTP, limit lub chwilowy problem dostawcy.',
                'count' => $statusCounts['retry'],
            ];
        }
        if (($rateLimit['remaining'] ?? null) === 0) {
            $issues[] = [
                'severity' => 'warning',
                'label' => 'Ostrzezenie',
                'type' => 'rate_limited',
                'title' => 'Globalny limit wysylki jest wyczerpany',
                'message' => 'Worker poczeka do zwolnienia okna limitu przed kolejnymi wysylkami.',
                'retryAfter' => $rateLimit['retryAfter'] ?? null,
            ];
        }
        if (($statusCounts['pending'] ?? 0) > 0 || ($statusCounts['processing'] ?? 0) > 0) {
            $issues[] = [
                'severity' => 'info',
                'label' => 'Informacja',
                'type' => 'active_backlog',
                'title' => 'Kolejka ma aktywne wiadomosci',
                'message' => 'Sa wiadomosci oczekujace albo aktualnie przetwarzane przez workery.',
                'count' => ($statusCounts['pending'] ?? 0) + ($statusCounts['processing'] ?? 0),
            ];
        }

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function mailers(): array
    {
        return [
            [
                'name' => 'Mailer standardowy',
                'queue' => 'standard',
                'messageTypes' => ['normalne', 'wysoki priorytet'],
                'provider' => 'SMTP',
                'host' => $this->env->string('SMTP_HOST', ''),
                'port' => $this->env->int('SMTP_PORT', 587),
                'secure' => $this->env->string('SMTP_SECURE', 'tls'),
                'username' => $this->env->string('SMTP_USER', ''),
                'fromEmail' => $this->env->string('SMTP_FROM_EMAIL', ''),
                'timeoutSeconds' => $this->env->int('SMTP_TIMEOUT_SECONDS', 30),
            ],
            [
                'name' => 'Mailer techniczny',
                'queue' => 'technical',
                'messageTypes' => ['techniczne FIFO'],
                'provider' => 'Gmail SMTP',
                'host' => 'smtp.gmail.com',
                'port' => $this->env->int('GMAIL_SMTP_PORT', 587),
                'secure' => $this->env->string('GMAIL_SMTP_SECURE', 'tls'),
                'username' => $this->env->string('GMAIL_SMTP_USER', ''),
                'fromEmail' => $this->env->string('GMAIL_FROM_EMAIL', ''),
                'timeoutSeconds' => $this->env->int('GMAIL_SMTP_TIMEOUT_SECONDS', 30),
                'fallbackToStandard' => $this->env->bool('TECHNICAL_EMAIL_FALLBACK_TO_STANDARD', true),
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function emailPayload(array $row, \DateTimeImmutable $now): array
    {
        $delay = $this->delay($row, $now);

        return [
            'sourceApp' => $row['source_app'],
            'id' => $row['id'],
            'status' => $row['status'],
            'priority' => $row['priority'],
            'to' => $row['recipient_email'],
            'subject' => $row['subject'],
            'queueAgeSeconds' => self::secondsSince((string) $row['created_at'], $now),
            'delayReason' => $delay['reason'],
            'delayUntil' => $delay['until'],
            'lastError' => $row['last_error'] ?? null,
            'providerMessageId' => $row['provider_message_id'] ?? null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'sentAt' => $row['sent_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array{reason: string|null, until: string|null} */
    private function delay(array $row, \DateTimeImmutable $now): array
    {
        $status = (string) $row['status'];
        $nextAttemptAt = $row['next_attempt_at'] ?? null;
        if (($status === 'pending' || $status === 'retry') && is_string($nextAttemptAt) && $nextAttemptAt !== '') {
            $nextAttempt = self::dateTime($nextAttemptAt);
            if ($nextAttempt !== null && $nextAttempt > $now) {
                return [
                    'reason' => $status === 'retry' ? 'retry_backoff' : 'scheduled',
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
