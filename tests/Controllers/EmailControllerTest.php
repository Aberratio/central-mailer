<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Controllers;

use CentralMailer\Config\Env;
use CentralMailer\Controllers\EmailController;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Tests\Support\DatabaseTestCase;
use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class EmailControllerTest extends DatabaseTestCase
{
    public function testShowIncludesProviderMessageId(): void
    {
        $id = $this->insertQueueRow([
            'status' => 'sent',
            'provider_message_id' => '<message-id@mailer.test>',
            'sent_at' => '2026-01-01 10:05:00',
        ]);
        $logger = new NullLogger();
        $controller = new EmailController(
            new EmailQueueService(
                $this->repository,
                new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false'])),
                $logger,
                $this->attachmentStorage
            ),
            $this->repository
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/' . $id)
            ->withAttribute('sourceApp', 'app-a');

        $response = $controller->show($request, new Response(), ['id' => $id]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<message-id@mailer.test>', $payload['providerMessageId']);
        self::assertSame('accepted', $payload['providerAcceptanceStatus']);
        self::assertSame('unknown', $payload['deliveryStatus']);
        self::assertSame('normal', $payload['priority']);
    }

    public function testCreateReplaysRequestWithSameIdempotencyKey(): void
    {
        $logger = new NullLogger();
        $controller = new EmailController(
            new EmailQueueService(
                $this->repository,
                new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false'])),
                $logger,
                $this->attachmentStorage
            ),
            $this->repository
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withHeader('Idempotency-Key', 'request-123')
            ->withParsedBody([
                'to' => 'recipient@deliverable.test',
                'subject' => 'Subject',
                'html' => '<p>Body</p>',
            ]);

        $created = $controller->create($request);
        $replayed = $controller->create($request);
        $createdPayload = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $replayedPayload = json_decode((string) $replayed->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(200, $replayed->getStatusCode());
        self::assertSame($createdPayload['id'], $replayedPayload['id']);
    }

    public function testCreateStoresPngAttachmentAndEventsEndpointReturnsQueuedEvent(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'recipient@deliverable.test',
                'subject' => 'QR code',
                'html' => '<p>QR</p>',
                'attachments' => [[
                    'filename' => 'qr.png',
                    'contentBase64' => self::tinyPngBase64(),
                ]],
            ]);

        $response = $controller->create($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $attachment = $this->pdo->query('SELECT * FROM email_attachments')->fetch();

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('image/png', $attachment['content_type']);
        self::assertFileExists($this->attachmentStorage->absolutePath($attachment['storage_path']));

        $eventsRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/' . $payload['id'] . '/events')
            ->withAttribute('sourceApp', 'app-a');
        $eventsResponse = $controller->events($eventsRequest, new Response(), ['id' => $payload['id']]);
        $eventsPayload = json_decode((string) $eventsResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('queued', $eventsPayload['events'][0]['type']);

        $this->attachmentStorage->delete($payload['id']);
    }

    public function testCreateStoresInlinePngAttachmentContentId(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'recipient@deliverable.test',
                'subject' => 'QR code',
                'html' => '<img src="cid:qr-inline">',
                'attachments' => [[
                    'filename' => 'qr.png',
                    'contentBase64' => self::tinyPngBase64(),
                    'contentId' => 'qr-inline',
                ]],
            ]);

        $response = $controller->create($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $attachment = $this->pdo->query('SELECT * FROM email_attachments')->fetch();

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('image/png', $attachment['content_type']);
        self::assertSame('qr-inline', $attachment['content_id']);

        $this->attachmentStorage->delete($payload['id']);
    }

    public function testBatchEndpointCreatesMultipleEmails(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails/batch')
            ->withAttribute('sourceApp', 'app-a')
            ->withHeader('Idempotency-Key', 'batch-request-1')
            ->withParsedBody([
                'subject' => 'Batch',
                'html' => '<p>Batch</p>',
                'recipients' => [
                    ['to' => 'one@deliverable.test'],
                    ['to' => 'two@deliverable.test'],
                ],
            ]);

        $response = $controller->batch($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $payload['emails']);
    }

    public function testBatchEndpointStoresRecipientOverridesAndAttachments(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails/batch')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'subject' => 'Batch',
                'html' => '<p>Batch</p>',
                'recipients' => [
                    [
                        'to' => 'one@deliverable.test',
                        'subject' => 'Personalized QR',
                        'html' => '<p>Personalized</p>',
                        'text' => 'Personalized',
                        'attachments' => [[
                            'filename' => 'qr.png',
                            'contentBase64' => self::tinyPngBase64(),
                        ]],
                    ],
                ],
            ]);

        $response = $controller->batch($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $emailId = $payload['emails'][0]['id'];
        $queueRow = $this->pdo->query('SELECT subject, html_body, text_body FROM email_queue WHERE id = ' . $this->pdo->quote($emailId))->fetch();
        $attachment = $this->pdo->query('SELECT * FROM email_attachments WHERE email_id = ' . $this->pdo->quote($emailId))->fetch();

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Personalized QR', $queueRow['subject']);
        self::assertSame('<p>Personalized</p>', $queueRow['html_body']);
        self::assertSame('Personalized', $queueRow['text_body']);
        self::assertSame('image/png', $attachment['content_type']);
        self::assertFileExists($this->attachmentStorage->absolutePath($attachment['storage_path']));

        $this->attachmentStorage->delete($emailId);
    }

    public function testIndexReturnsEmailsFromRequestedDateRange(): void
    {
        $controller = $this->controller();
        $insideId = $this->insertQueueRow([
            'created_at' => '2026-01-02 10:00:00',
            'updated_at' => '2026-01-02 10:00:00',
        ]);
        $this->insertQueueRow([
            'created_at' => '2026-01-03 10:00:00',
            'updated_at' => '2026-01-03 10:00:00',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withQueryParams([
                'from' => '2026-01-02',
                'to' => '2026-01-02',
            ]);

        $response = $controller->index($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2026-01-02 00:00:00', $payload['from']);
        self::assertSame('2026-01-02 23:59:59', $payload['to']);
        self::assertSame([$insideId], array_column($payload['emails'], 'id'));
    }

    public function testUnsentReturnsOnlyEmailsWithStatusDifferentThanSent(): void
    {
        $controller = $this->controller();
        $pendingId = $this->insertQueueRow(['status' => 'pending']);
        $retryId = $this->insertQueueRow(['status' => 'retry']);
        $this->insertQueueRow(['status' => 'sent', 'sent_at' => '2026-01-01 10:05:00']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/unsent')
            ->withAttribute('sourceApp', 'app-a');

        $response = $controller->unsent($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$pendingId, $retryId], array_column($payload['emails'], 'id'));
    }

    public function testBatchStatusAndEventsCanBeFetchedByBatchId(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails/batch')
            ->withAttribute('sourceApp', 'app-a')
            ->withHeader('Idempotency-Key', 'batch-history-1')
            ->withParsedBody([
                'subject' => 'Batch history',
                'html' => '<p>Batch history</p>',
                'recipients' => [
                    ['to' => 'one@deliverable.test'],
                    ['to' => 'two@deliverable.test'],
                ],
            ]);
        $created = $controller->batch($request);
        $createdPayload = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $statusRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/batch/' . $createdPayload['id'])
            ->withAttribute('sourceApp', 'app-a');
        $statusResponse = $controller->showBatch($statusRequest, new Response(), ['id' => $createdPayload['id']]);
        $statusPayload = json_decode((string) $statusResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $eventsResponse = $controller->batchEvents($statusRequest, new Response(), ['id' => $createdPayload['id']]);
        $eventsPayload = json_decode((string) $eventsResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $statusResponse->getStatusCode());
        self::assertSame($createdPayload['id'], $statusPayload['id']);
        self::assertCount(2, $statusPayload['emails']);
        self::assertSame(2, $statusPayload['statusCounts']['pending']);
        self::assertSame(0, $statusPayload['providerAcceptedCount']);
        self::assertSame('in_progress', $statusPayload['deliveryStatus']);
        self::assertSame($createdPayload['emails'][0]['id'], $eventsPayload['events'][0]['emailId']);
        self::assertSame('queued', $eventsPayload['events'][0]['type']);
    }

    public function testBatchStatusSeparatesProviderAcceptanceFromDelivery(): void
    {
        $controller = $this->controller();
        $batchId = 'batch-status-summary';
        $messageId = 'batch-message-summary';
        $this->pdo->prepare(
            'INSERT INTO email_messages (id, source_app, subject, html_body, text_body, metadata, created_at)
             VALUES (:id, :source_app, :subject, :html_body, NULL, NULL, :created_at)'
        )->execute([
            'id' => $messageId,
            'source_app' => 'app-a',
            'subject' => 'Summary',
            'html_body' => '<p>Summary</p>',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->pdo->prepare(
            'INSERT INTO email_batches (id, source_app, idempotency_key, request_hash, message_id, created_at)
             VALUES (:id, :source_app, NULL, NULL, :message_id, :created_at)'
        )->execute([
            'id' => $batchId,
            'source_app' => 'app-a',
            'message_id' => $messageId,
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'accepted-email',
            'message_id' => $messageId,
            'batch_id' => $batchId,
            'status' => 'sent',
            'provider_message_id' => '<accepted@mailer.test>',
            'sent_at' => '2026-01-01 10:05:00',
        ]);
        $this->insertQueueRow([
            'id' => 'retry-email',
            'message_id' => $messageId,
            'batch_id' => $batchId,
            'status' => 'retry',
            'next_attempt_at' => '2026-01-01 10:10:00',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/batch/' . $batchId)
            ->withAttribute('sourceApp', 'app-a');

        $response = $controller->showBatch($request, new Response(), ['id' => $batchId]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['statusCounts']['sent']);
        self::assertSame(1, $payload['statusCounts']['retry']);
        self::assertSame(1, $payload['providerAcceptedCount']);
        self::assertSame('in_progress', $payload['deliveryStatus']);
        self::assertSame('accepted', $payload['emails'][0]['providerAcceptanceStatus']);
        self::assertSame('unknown', $payload['emails'][0]['deliveryStatus']);
    }

    public function testCreateStoresTechnicalPriority(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'developer@deliverable.test',
                'subject' => 'Technical alert',
                'html' => '<p>Technical alert</p>',
                'priority' => 'technical',
            ]);

        $response = $this->controller()->create($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('technical', $this->fetchQueueRow($payload['id'])['priority']);
    }

    private function controller(): EmailController
    {
        return new EmailController(
            new EmailQueueService(
                $this->repository,
                new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false'])),
                new NullLogger(),
                $this->attachmentStorage
            ),
            $this->repository
        );
    }

    private static function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=';
    }
}
