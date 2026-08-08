<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Controllers;

use CentralMailer\Config\Env;
use CentralMailer\Controllers\AdminController;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminControllerStatusTest extends DatabaseTestCase
{
    /** @param array<string, string> $envOverrides */
    private function makeController(array $envOverrides = []): AdminController
    {
        return new AdminController(
            $this->pdo,
            $this->attachmentStorage,
            $this->repository,
            new RateLimitRepository($this->pdo),
            new WorkerHeartbeatRepository($this->pdo),
            new Env($envOverrides)
        );
    }

    /** @return array<string, mixed> */
    private function decode(AdminController $controller): array
    {
        $response = $controller->status((new ServerRequestFactory())->createServerRequest('GET', '/admin/status'));

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testGmailProviderUsageIsDisabledWhenLimitIsZero(): void
    {
        $payload = $this->decode($this->makeController(['GMAIL_RATE_LIMIT_COUNT' => '0']));

        self::assertSame(
            ['enabled' => false, 'used' => 0, 'limit' => 0, 'remaining' => null, 'retryAfter' => null],
            $payload['rateLimit']['provider']['gmail']
        );
    }

    public function testGmailProviderUsageReflectsScopeReservationsWhenEnabled(): void
    {
        $this->insertScopeReservation('provider:gmail');
        $this->insertScopeReservation('provider:gmail');

        $payload = $this->decode($this->makeController(['GMAIL_RATE_LIMIT_COUNT' => '5', 'GMAIL_RATE_LIMIT_WINDOW_MINUTES' => '1440']));

        $gmail = $payload['rateLimit']['provider']['gmail'];
        self::assertTrue($gmail['enabled']);
        self::assertSame(2, $gmail['used']);
        self::assertSame(5, $gmail['limit']);
        self::assertSame(3, $gmail['remaining']);
    }

    public function testByClientUsageDistinguishesClientsWithAndWithoutOwnLimit(): void
    {
        $this->pdo->exec(
            "UPDATE email_clients SET rate_limit_count = 3, rate_limit_window_minutes = 5 WHERE source_app = 'app-a'"
        );

        $payload = $this->decode($this->makeController());

        $byClient = $payload['rateLimit']['byClient'];
        $byApp = array_column($byClient, null, 'sourceApp');

        self::assertTrue($byApp['app-a']['hasOwnLimit']);
        self::assertSame(3, $byApp['app-a']['limit']);
        self::assertSame(5, $byApp['app-a']['windowMinutes']);

        self::assertFalse($byApp['app-b']['hasOwnLimit']);
        self::assertNull($byApp['app-b']['limit']);
        self::assertNull($byApp['app-b']['remaining']);
    }

    public function testThroughputCountsOnlyEmailsSentInLastHour(): void
    {
        $this->insertQueueRow([
            'id' => 'sent-recent',
            'status' => 'sent',
            'sent_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'),
        ]);
        $this->insertQueueRow([
            'id' => 'sent-old',
            'status' => 'sent',
            'sent_at' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController());

        self::assertSame(1, $payload['throughput']['sentLastHour']);
    }

    public function testBacklogReportsOldestUnsentAgeInSeconds(): void
    {
        $this->insertQueueRow([
            'id' => 'oldest-pending',
            'status' => 'pending',
            'created_at' => (new \DateTimeImmutable('-120 seconds'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController());

        self::assertGreaterThanOrEqual(120, $payload['backlog']['oldestUnsentAgeSeconds']);
    }

    public function testBacklogAgeIsNullWhenQueueIsEmpty(): void
    {
        $payload = $this->decode($this->makeController());

        self::assertNull($payload['backlog']['oldestUnsentAgeSeconds']);
    }

    public function testActiveBacklogIsNoLongerReportedAsAnIssue(): void
    {
        $this->insertQueueRow(['id' => 'pending-fresh', 'status' => 'pending', 'created_at' => self::now()]);

        $payload = $this->decode($this->makeController());

        $types = array_column($payload['issues'], 'type');
        self::assertNotContains('active_backlog', $types);
    }

    public function testRetryBacklogIsNotReportedWhileWithinLatencyThreshold(): void
    {
        $this->insertQueueRow([
            'id' => 'fresh-retry',
            'status' => 'retry',
            'created_at' => (new \DateTimeImmutable('-30 seconds'))->format('Y-m-d H:i:s'),
            'next_attempt_at' => (new \DateTimeImmutable('+60 seconds'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController(['QUEUE_LATENCY_ALERT_SECONDS' => '900']));

        $types = array_column($payload['issues'], 'type');
        self::assertNotContains('retry_backlog', $types);
    }

    public function testRetryBacklogIsReportedOnceOldestUnsentExceedsLatencyThreshold(): void
    {
        $this->insertQueueRow([
            'id' => 'stale-retry',
            'status' => 'retry',
            'created_at' => (new \DateTimeImmutable('-1000 seconds'))->format('Y-m-d H:i:s'),
            'next_attempt_at' => (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController(['QUEUE_LATENCY_ALERT_SECONDS' => '900']));

        $types = array_column($payload['issues'], 'type');
        self::assertContains('retry_backlog', $types);
        $issue = $payload['issues'][array_search('retry_backlog', $types, true)];
        self::assertFalse($issue['blocking']);
        self::assertIsString($issue['remedy']);
    }

    public function testFailedEmailsIsNotReportedOutsideLookbackWindow(): void
    {
        $this->insertQueueRow([
            'id' => 'old-failed',
            'status' => 'failed',
            'updated_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController(['MONITOR_FAILED_LOOKBACK_MINUTES' => '15']));

        $types = array_column($payload['issues'], 'type');
        self::assertNotContains('failed_emails', $types);
    }

    public function testFailedEmailsIsReportedWithinLookbackWindow(): void
    {
        $this->insertQueueRow([
            'id' => 'recent-failed',
            'status' => 'failed',
            'updated_at' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $payload = $this->decode($this->makeController(['MONITOR_FAILED_LOOKBACK_MINUTES' => '15']));

        $types = array_column($payload['issues'], 'type');
        self::assertContains('failed_emails', $types);
        $issue = $payload['issues'][array_search('failed_emails', $types, true)];
        self::assertFalse($issue['blocking']);
        self::assertSame(1, $issue['count']);
    }

    public function testRateLimitedIssueIsBlockingWithRetryAfterRemedy(): void
    {
        $this->insertScopeReservation('app-a', 0);
        $this->insertScopeReservation('app-a', 1);

        $payload = $this->decode($this->makeController([
            'EMAIL_RATE_LIMIT_COUNT' => '2',
            'EMAIL_RATE_LIMIT_WINDOW_MINUTES' => '15',
        ]));

        $types = array_column($payload['issues'], 'type');
        self::assertContains('rate_limited', $types);
        $issue = $payload['issues'][array_search('rate_limited', $types, true)];
        self::assertTrue($issue['blocking']);
        self::assertStringContainsString((string) $issue['retryAfter'], $issue['remedy']);
    }

    public function testGmailRateLimitedIssueIsBlockingWithRetryAfterRemedy(): void
    {
        $this->insertScopeReservation('provider:gmail', 0);
        $this->insertScopeReservation('provider:gmail', 1);

        $payload = $this->decode($this->makeController([
            'GMAIL_RATE_LIMIT_COUNT' => '2',
            'GMAIL_RATE_LIMIT_WINDOW_MINUTES' => '1440',
        ]));

        $types = array_column($payload['issues'], 'type');
        self::assertContains('gmail_rate_limited', $types);
        $issue = $payload['issues'][array_search('gmail_rate_limited', $types, true)];
        self::assertTrue($issue['blocking']);
        self::assertStringContainsString((string) $issue['retryAfter'], $issue['remedy']);
    }

    public function testGmailRateLimitedIssueIsNotReportedWhenCapIsDisabled(): void
    {
        $payload = $this->decode($this->makeController(['GMAIL_RATE_LIMIT_COUNT' => '0']));

        self::assertNotContains('gmail_rate_limited', array_column($payload['issues'], 'type'));
    }

    private function insertScopeReservation(string $scope, int $secondsAgoOffset = 0): void
    {
        $reservedAt = (new \DateTimeImmutable(sprintf('-%d seconds', $secondsAgoOffset)))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_rate_limit_reservations (id, source_app, reserved_at) VALUES (:id, :source_app, :reserved_at)'
        );
        $stmt->execute(['id' => uniqid('reservation-', true), 'source_app' => $scope, 'reserved_at' => $reservedAt]);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
