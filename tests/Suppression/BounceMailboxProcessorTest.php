<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Suppression;

use CentralMailer\Suppression\BounceMailboxProcessor;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use Psr\Log\NullLogger;

final class BounceMailboxProcessorTest extends DatabaseTestCase
{
    private SuppressionRepository $suppressions;
    private BounceMailboxProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suppressions = new SuppressionRepository($this->pdo);
        $this->processor = new BounceMailboxProcessor(
            $this->suppressions,
            $this->repository,
            new NullLogger(),
            'mailer.example.test'
        );
    }

    public function testParsesDsnWithDeliveryStatusPart(): void
    {
        $parsed = $this->processor->parse($this->hardBounceDsn('71d9e180-b457-4fc8-b5bb-fc35ba5bc481'));

        self::assertSame('dead@example.test', $parsed['recipient']);
        self::assertSame('5.1.1', $parsed['status']);
        self::assertSame('71d9e180-b457-4fc8-b5bb-fc35ba5bc481', $parsed['originEmailId']);
        self::assertTrue($parsed['hardBounce']);
    }

    public function testParsesEximStyleXFailedRecipientsHeader(): void
    {
        $raw = "Return-Path: <>\r\n"
            . "X-Failed-Recipients: dead@example.test\r\n"
            . "Subject: Mail delivery failed\r\n"
            . "\r\n"
            . "Status: 5.2.1\r\n";

        $parsed = $this->processor->parse($raw);

        self::assertSame('dead@example.test', $parsed['recipient']);
        self::assertTrue($parsed['hardBounce']);
    }

    public function testSoftBounceIsNotSuppressed(): void
    {
        $raw = "Final-Recipient: rfc822; busy@example.test\r\nAction: delayed\r\nStatus: 4.4.1\r\n";

        self::assertFalse($this->processor->process($raw));
        self::assertFalse($this->suppressions->isSuppressed('busy@example.test', 'app-a', 'transactional'));
    }

    public function testHardBounceAddsGlobalSuppressionAndBounceEvent(): void
    {
        $emailId = '71d9e180-b457-4fc8-b5bb-fc35ba5bc481';
        $this->insertQueueRow(['id' => $emailId, 'status' => 'sent', 'recipient_email' => 'dead@example.test']);

        self::assertTrue($this->processor->process($this->hardBounceDsn($emailId)));

        self::assertTrue($this->suppressions->isSuppressed('dead@example.test', 'app-b', 'transactional'));
        self::assertSame('dsn:5.1.1', (string) $this->pdo->query(
            "SELECT error_code FROM email_events WHERE email_id = '$emailId' AND event_type = 'bounced'"
        )->fetchColumn());
    }

    private function hardBounceDsn(string $emailId): string
    {
        return "Return-Path: <>\r\n"
            . "From: Mail Delivery System <MAILER-DAEMON@relay.example>\r\n"
            . "Subject: Undelivered Mail Returned to Sender\r\n"
            . "Content-Type: multipart/report; report-type=delivery-status\r\n"
            . "\r\n"
            . "Reporting-MTA: dns; relay.example\r\n"
            . "Final-Recipient: rfc822; dead@example.test\r\n"
            . "Action: failed\r\n"
            . "Status: 5.1.1\r\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 User unknown\r\n"
            . "\r\n"
            . "Message-ID: <$emailId@mailer.example.test>\r\n"
            . "To: dead@example.test\r\n";
    }
}
