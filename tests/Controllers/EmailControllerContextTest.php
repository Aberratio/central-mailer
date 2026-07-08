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

final class EmailControllerContextTest extends DatabaseTestCase
{
    public function testCreateRoundTripsContextIdIntoContextListing(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'recipient@deliverable.test',
                'subject' => 'QR',
                'html' => '<p>QR</p>',
                'contextId' => 'evt-1',
            ]);

        $createResponse = $controller->create($request);
        self::assertSame(201, $createResponse->getStatusCode());

        $payload = $this->showContext($controller, 'evt-1');

        self::assertSame(1, $payload['total']);
        self::assertSame('evt-1', $payload['emails'][0]['contextId']);
        self::assertNull($payload['emails'][0]['batchId']);
    }

    public function testBatchRoundTripsContextIdAndMarksEmailsAsBatch(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails/batch')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'subject' => 'QR batch',
                'html' => '<p>QR</p>',
                'contextId' => 'evt-1',
                'recipients' => [
                    ['to' => 'one@deliverable.test', 'metadata' => ['participantId' => 1]],
                    ['to' => 'two@deliverable.test', 'metadata' => ['participantId' => 2]],
                ],
            ]);

        $batchResponse = $controller->batch($request);
        self::assertSame(201, $batchResponse->getStatusCode());

        $payload = $this->showContext($controller, 'evt-1');

        self::assertSame(2, $payload['total']);
        foreach ($payload['emails'] as $email) {
            self::assertSame('evt-1', $email['contextId']);
            self::assertNotNull($email['batchId']);
        }
        $participantIds = array_map(static fn (array $email): int => $email['metadata']['participantId'], $payload['emails']);
        sort($participantIds);
        self::assertSame([1, 2], $participantIds);
    }

    public function testShowContextAggregatesCountsAndDerivesBouncedStatus(): void
    {
        $bouncedId = $this->insertQueueRow(['id' => 'ctx-bounced', 'context_id' => 'evt-1', 'status' => 'sent', 'sent_at' => '2026-01-01 10:05:00']);
        $this->insertQueueRow(['id' => 'ctx-sent', 'context_id' => 'evt-1', 'status' => 'sent', 'sent_at' => '2026-01-01 10:06:00']);
        $this->insertQueueRow(['id' => 'ctx-pending', 'context_id' => 'evt-1', 'status' => 'pending']);
        $this->repository->recordBounce($bouncedId, '5.1.1', 'User unknown');

        $payload = $this->showContext($this->controller(), 'evt-1');

        self::assertSame(3, $payload['total']);
        self::assertSame(2, $payload['statusCounts']['sent']);
        self::assertSame(1, $payload['statusCounts']['pending']);
        self::assertSame(1, $payload['statusCounts']['bounced']);

        $byId = array_column($payload['emails'], null, 'id');
        self::assertSame('bounced', $byId['ctx-bounced']['effectiveStatus']);
        self::assertTrue($byId['ctx-bounced']['bounced']);
        self::assertNotNull($byId['ctx-bounced']['bouncedAt']);
        self::assertSame('bounced', $byId['ctx-bounced']['deliveryStatus']);
        self::assertSame('sent', $byId['ctx-sent']['effectiveStatus']);
        self::assertSame('unknown', $byId['ctx-sent']['deliveryStatus']);
        self::assertSame('pending', $byId['ctx-pending']['effectiveStatus']);
    }

    public function testShowContextDerivesSuppressedStatus(): void
    {
        $id = $this->insertQueueRow(['id' => 'ctx-suppressed', 'context_id' => 'evt-1', 'status' => 'failed']);
        $this->pdo->prepare(
            'INSERT INTO email_events
             (email_id, event_type, status, attempt, error_code, error_message, provider_message_id, details, created_at)
             VALUES
             (:email_id, "suppressed", "failed", 0, "suppressed", "Recipient address is suppressed", NULL, NULL, "2026-01-01 10:00:00")'
        )->execute(['email_id' => $id]);

        $payload = $this->showContext($this->controller(), 'evt-1');

        self::assertSame('suppressed', $payload['emails'][0]['effectiveStatus']);
    }

    public function testShowContextPaginatesWithHasMore(): void
    {
        $this->insertQueueRow(['id' => 'ctx-p1', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']);
        $this->insertQueueRow(['id' => 'ctx-p2', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 11:00:00', 'updated_at' => '2026-01-01 11:00:00']);
        $this->insertQueueRow(['id' => 'ctx-p3', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00']);

        $firstPage = $this->showContext($this->controller(), 'evt-1', '?limit=2');
        $secondPage = $this->showContext($this->controller(), 'evt-1', '?limit=2&offset=2');

        self::assertTrue($firstPage['hasMore']);
        self::assertSame(3, $firstPage['total']);
        self::assertSame(['ctx-p3', 'ctx-p2'], array_column($firstPage['emails'], 'id'));
        self::assertFalse($secondPage['hasMore']);
        self::assertSame(['ctx-p1'], array_column($secondPage['emails'], 'id'));
    }

    public function testShowContextIsIsolatedBetweenSourceApps(): void
    {
        $this->insertQueueRow(['context_id' => 'evt-1', 'source_app' => 'app-a']);

        $payload = $this->showContext($this->controller(), 'evt-1', '', 'app-b');

        self::assertSame(0, $payload['total']);
        self::assertSame([], $payload['emails']);
    }

    public function testCreateRejectsInvalidContextId(): void
    {
        $controller = $this->controller();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withAttribute('sourceApp', 'app-a')
            ->withParsedBody([
                'to' => 'recipient@deliverable.test',
                'subject' => 'QR',
                'html' => '<p>QR</p>',
                'contextId' => str_repeat('x', 65),
            ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contextId must be 1-64 characters');

        $controller->create($request);
    }

    /** @return array<string, mixed> */
    private function showContext(EmailController $controller, string $contextId, string $query = '', string $sourceApp = 'app-a'): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/context/' . $contextId . $query)
            ->withAttribute('sourceApp', $sourceApp);
        if ($query !== '') {
            parse_str(ltrim($query, '?'), $params);
            $request = $request->withQueryParams($params);
        }

        $response = $controller->showContext($request, new Response(), ['contextId' => $contextId]);
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
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
}
