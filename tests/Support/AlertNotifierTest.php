<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Support;

use CentralMailer\Config\Env;
use CentralMailer\Support\AlertNotifier;
use Psr\Log\NullLogger;

final class AlertNotifierTest extends DatabaseTestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = sys_get_temp_dir() . '/alert-notifier-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->statePath)) {
            unlink($this->statePath);
        }
    }

    public function testEnqueuesTechnicalAlertEmailAndCreatesInternalClient(): void
    {
        $notifier = $this->notifier(['ALERT_EMAIL' => 'admin@example.com']);

        $notifier->notify('failed_emails', 'Something failed', ['failedCount' => 3]);

        $row = $this->pdo->query(
            "SELECT * FROM email_queue WHERE source_app = 'central-mailer'"
        )->fetch();
        self::assertNotFalse($row);
        self::assertSame('admin@example.com', $row['recipient_email']);
        self::assertSame('technical', $row['priority']);
        self::assertSame('[central-mailer] Alert: failed_emails', $row['subject']);
        self::assertStringContainsString('failedCount', $row['text_body']);

        $client = $this->pdo->query(
            "SELECT active FROM email_clients WHERE source_app = 'central-mailer'"
        )->fetchColumn();
        self::assertSame(1, (int) $client);
    }

    public function testThrottlesRepeatedAlertsOfSameTypeButNotOtherTypes(): void
    {
        $time = 1_000_000;
        $notifier = $this->notifier(
            ['ALERT_EMAIL' => 'admin@example.com', 'ALERT_THROTTLE_SECONDS' => '3600'],
            static function () use (&$time): int {
                return $time;
            }
        );

        $notifier->notify('failed_emails', 'First alert');
        $time += 60;
        $notifier->notify('failed_emails', 'Suppressed alert');
        $notifier->notify('queue_latency', 'Different type goes through');
        $time += 3600;
        $notifier->notify('failed_emails', 'After throttle window');

        $count = fn (string $subject): int => (int) $this->pdo
            ->query("SELECT COUNT(*) FROM email_queue WHERE subject = '$subject'")
            ->fetchColumn();
        self::assertSame(2, $count('[central-mailer] Alert: failed_emails'));
        self::assertSame(1, $count('[central-mailer] Alert: queue_latency'));
    }

    public function testDoesNothingWhenNoChannelIsConfigured(): void
    {
        $notifier = $this->notifier([]);

        $notifier->notify('failed_emails', 'No channels');

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn());
        self::assertFileDoesNotExist($this->statePath);
    }

    /** @param array<string, string> $envValues */
    private function notifier(array $envValues, ?\Closure $clock = null): AlertNotifier
    {
        return new AlertNotifier(
            new Env($envValues),
            new NullLogger(),
            $this->repository,
            $this->pdo,
            $this->statePath,
            $clock
        );
    }
}
