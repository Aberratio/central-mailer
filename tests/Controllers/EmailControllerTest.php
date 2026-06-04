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
                $logger
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
    }

    public function testCreateReplaysRequestWithSameIdempotencyKey(): void
    {
        $logger = new NullLogger();
        $controller = new EmailController(
            new EmailQueueService(
                $this->repository,
                new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false'])),
                $logger
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
}
