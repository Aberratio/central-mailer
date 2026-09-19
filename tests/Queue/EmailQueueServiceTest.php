<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Suppression\RecipientSuppressedException;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\NullLogger;

final class EmailQueueServiceTest extends DatabaseTestCase
{
    private EmailQueueService $service;
    private SuppressionRepository $suppressions;

    protected function setUp(): void
    {
        parent::setUp();
        $env = new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']);
        $this->suppressions = new SuppressionRepository($this->pdo);
        $this->service = new EmailQueueService(
            $this->repository,
            new EmailRequestValidator($env),
            new NullLogger(),
            $this->attachmentStorage,
            $env,
            $this->suppressions
        );
    }

    public function testEnqueueRejectsSuppressedRecipient(): void
    {
        $this->suppressions->add('dead@deliverable.test', 'bounce');

        $this->expectException(RecipientSuppressedException::class);

        $this->service->enqueue('app-a', [
            'to' => 'dead@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
        ]);
    }

    public function testEnqueueAllowsTransactionalMailWhenSuppressionIsMarketingScoped(): void
    {
        $this->suppressions->add('optout@deliverable.test', 'unsubscribe', 'marketing', 'app-a');

        $result = $this->service->enqueue('app-a', [
            'to' => 'optout@deliverable.test',
            'subject' => 'Invoice',
            'html' => '<p>Invoice</p>',
        ]);

        self::assertSame('pending', $result->status);
    }

    public function testBatchInsertsSuppressedRecipientsAsFailedWithoutRejectingBatch(): void
    {
        $this->suppressions->add('optout@deliverable.test', 'unsubscribe', 'marketing', 'app-a');

        $result = $this->service->enqueueBatch('app-a', [
            'subject' => 'Newsletter',
            'html' => '<p>News</p>',
            'recipients' => [
                ['to' => 'ok@deliverable.test'],
                ['to' => 'optout@deliverable.test'],
            ],
        ]);

        $statuses = array_column($result->emails, 'status');
        sort($statuses);
        self::assertSame(['failed', 'pending'], $statuses);
    }

    public function testBatchQueuesValidRecipientsEvenWhenOneAddressIsInvalid(): void
    {
        // Sedno poprawki: przy 1600 adresach z importu literowka jest pewnikiem, a wczesniej
        // odrzucala cala paczke bledem 422 - nikt z niej nie dostawal maila.
        $result = $this->service->enqueueBatch('app-a', [
            'subject' => 'Kody QR',
            'html' => '<p>QR</p>',
            'recipients' => [
                ['to' => 'ok1@deliverable.test'],
                ['to' => 'literowka@@gmial'],
                ['to' => 'ok2@deliverable.test'],
            ],
        ]);

        self::assertCount(3, $result->emails);
        self::assertSame('pending', $result->emails[0]['status']);
        self::assertSame('failed', $result->emails[1]['status']);
        self::assertSame('pending', $result->emails[2]['status']);

        $rows = $this->pdo->query(
            'SELECT recipient_email, status, last_error FROM email_queue ORDER BY recipient_email ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame('literowka@@gmial', $rows[0]['recipient_email']);
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame('Recipient email is invalid', $rows[0]['last_error']);
        self::assertSame('pending', $rows[1]['status']);
        self::assertNull($rows[1]['last_error']);
        self::assertSame('pending', $rows[2]['status']);
    }

    public function testInvalidRecipientIsRecordedAsRejectedEventAndNeverPickedUpByWorker(): void
    {
        $this->service->enqueueBatch('app-a', [
            'subject' => 'Kody QR',
            'html' => '<p>QR</p>',
            'recipients' => [
                ['to' => 'zly-adres'],
                ['to' => 'ok@deliverable.test'],
            ],
        ]);

        $event = $this->pdo->query(
            "SELECT e.event_type, e.status, e.error_code, e.error_message
             FROM email_events e
             JOIN email_queue q ON q.id = e.email_id
             WHERE q.recipient_email = 'zly-adres'"
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('rejected', $event['event_type']);
        self::assertSame('failed', $event['status']);
        self::assertSame('invalid_recipient', $event['error_code']);
        self::assertSame('Recipient email is invalid', $event['error_message']);

        // Worker claimuje tylko pending/retry - odrzucony wiersz jest terminalny.
        $claimed = $this->repository->claimBatch(10, 300, 900, 'standard');
        self::assertSame(['ok@deliverable.test'], array_column($claimed, 'recipient_email'));
    }

    public function testInvalidRecipientStoresNoAttachmentOnDisk(): void
    {
        $this->service->enqueueBatch('app-a', [
            'subject' => 'Kody QR',
            'html' => '<p>QR</p>',
            'recipients' => [
                [
                    'to' => 'zly-adres',
                    'attachments' => [[
                        'filename' => 'kod-qr.png',
                        'contentBase64' => self::tinyPngBase64(),
                        'contentType' => 'image/png',
                    ]],
                ],
                [
                    'to' => 'ok@deliverable.test',
                    'attachments' => [[
                        'filename' => 'kod-qr.png',
                        'contentBase64' => self::tinyPngBase64(),
                        'contentType' => 'image/png',
                    ]],
                ],
            ],
        ]);

        $attachments = $this->pdo->query(
            'SELECT q.recipient_email
             FROM email_attachments a
             JOIN email_queue q ON q.id = a.email_id'
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['ok@deliverable.test'], $attachments);
    }

    public function testBatchReplayListsEmailsInRecipientOrderWithRejectionReason(): void
    {
        // Clients map emails[i] back to recipients[i]; a replay after a client timeout must
        // not shuffle them, or the wrong participant would be reported as rejected.
        $recipients = [];
        for ($i = 0; $i < 20; $i++) {
            $recipients[] = ['to' => $i === 7 ? 'literowka@@gmial' : sprintf('ok%d@deliverable.test', $i)];
        }
        $payload = ['subject' => 'Kody QR', 'html' => '<p>QR</p>', 'recipients' => $recipients];

        $first = $this->service->enqueueBatch('app-a', $payload, 'replay-order-key');
        $replay = $this->service->enqueueBatch('app-a', $payload, 'replay-order-key');

        self::assertFalse($replay->created);
        self::assertSame(array_column($first->emails, 'id'), array_column($replay->emails, 'id'));
        self::assertSame('failed', $first->emails[7]['status']);
        self::assertSame('Recipient email is invalid', $first->emails[7]['lastError']);
        self::assertSame('Recipient email is invalid', $replay->emails[7]['lastError']);
        self::assertNull($replay->emails[0]['lastError']);
    }

    public function testBatchOfOnlyInvalidRecipientsIsAcceptedInsteadOfThrowing(): void
    {
        $result = $this->service->enqueueBatch('app-a', [
            'subject' => 'Kody QR',
            'html' => '<p>QR</p>',
            'recipients' => [['to' => 'zly'], ['to' => 'tez-zly']],
        ]);

        self::assertSame(['failed', 'failed'], array_column($result->emails, 'status'));
    }

    private static function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=';
    }
}
