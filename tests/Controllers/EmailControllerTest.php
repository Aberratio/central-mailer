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

    public function testTestEndpointStoresTechnicalPriority(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails/test')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'developer@deliverable.test',
                'priority' => 'technical',
            ]);

        $response = $this->controller()->test($request);
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
