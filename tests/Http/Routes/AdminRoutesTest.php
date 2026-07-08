<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Routes;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Client\ClientRepository;
use CentralMailer\Config\Env;
use CentralMailer\Controllers\AdminController;
use CentralMailer\Http\Middleware\AdminKeyAuthMiddleware;
use CentralMailer\Http\Middleware\ApiKeyAuthMiddleware;
use CentralMailer\Http\Routes\AdminRoutes;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use DI\Container;
use PDO;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AdminRoutesTest extends DatabaseTestCase
{
    public function testAdminStatusRequiresAdminKey(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/status');

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAdminStatusReturnsGlobalStateWithoutClientApiKey(): void
    {
        $this->insertQueueRow(['id' => 'admin-pending', 'status' => 'pending']);
        $heartbeats = new WorkerHeartbeatRepository($this->pdo);
        $heartbeats->beat('standard:test', 'standard');
        $heartbeats->beat('technical:test', 'technical');
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['statusCounts']['global']['pending']);
        self::assertTrue($payload['workers']['standardActive']);
        self::assertTrue($payload['workers']['technicalActive']);
        self::assertNotNull($payload['workers']['lastActivity']['standard']['lastSeenAt']);
        self::assertNotNull($payload['workers']['lastActivity']['technical']['lastSeenAt']);
        self::assertNull($payload['workers']['lastActivity']['standard']['lastProcessedAt']);
        self::assertSame('Mailer standardowy', $payload['mailers'][0]['name']);
        self::assertSame(['normalne', 'wysoki priorytet'], $payload['mailers'][0]['messageTypes']);
    }

    public function testAdminStatusOmitsWorkerMissingIssueWithinCronGracePeriod(): void
    {
        $heartbeats = new WorkerHeartbeatRepository($this->pdo);
        $heartbeats->beat('standard:test', 'standard');
        $heartbeats->beat('technical:test', 'technical');
        $this->backdateHeartbeat('standard', 90);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['workers']['standardActive']);
        $workerIssues = array_values(array_filter(
            $payload['issues'],
            static fn (array $issue): bool => $issue['type'] === 'worker_missing'
        ));
        self::assertSame([], $workerIssues);
    }

    public function testAdminStatusFlagsWorkerMissingIssueAfterCronGracePeriod(): void
    {
        $heartbeats = new WorkerHeartbeatRepository($this->pdo);
        $heartbeats->beat('standard:test', 'standard');
        $heartbeats->beat('technical:test', 'technical');
        $this->backdateHeartbeat('standard', 200);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $standardIssues = array_values(array_filter(
            $payload['issues'],
            static fn (array $issue): bool => $issue['type'] === 'worker_missing' && $issue['queue'] === 'standard'
        ));
        self::assertCount(1, $standardIssues);
        self::assertSame('critical', $standardIssues[0]['severity']);
        self::assertNotNull($standardIssues[0]['lastSeenAt']);
        self::assertNotNull($standardIssues[0]['nextExpectedAt']);
        $technicalIssues = array_values(array_filter(
            $payload['issues'],
            static fn (array $issue): bool => $issue['type'] === 'worker_missing' && $issue['queue'] === 'technical'
        ));
        self::assertSame([], $technicalIssues);
    }

    public function testAdminStatusFlagsWorkerMissingImmediatelyWhenNeverSeen(): void
    {
        $heartbeats = new WorkerHeartbeatRepository($this->pdo);
        $heartbeats->beat('technical:test', 'technical');
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $standardIssues = array_values(array_filter(
            $payload['issues'],
            static fn (array $issue): bool => $issue['type'] === 'worker_missing' && $issue['queue'] === 'standard'
        ));
        self::assertCount(1, $standardIssues);
        self::assertNull($standardIssues[0]['lastSeenAt']);
        self::assertNull($standardIssues[0]['nextExpectedAt']);
    }

    public function testAdminUnsentReturnsRowsFromAllClients(): void
    {
        $this->insertQueueRow(['id' => 'admin-app-a', 'source_app' => 'app-a', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'admin-app-b', 'source_app' => 'app-b', 'status' => 'retry']);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/unsent?limit=10')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['app-a', 'app-b'], array_column($payload['emails'], 'sourceApp'));
    }

    public function testAdminSentReturnsSentRowsFromAllClientsNewestFirst(): void
    {
        $this->insertQueueRow([
            'id' => 'admin-sent-older',
            'source_app' => 'app-a',
            'status' => 'sent',
            'provider_message_id' => 'provider-older',
            'sent_at' => '2026-01-01 10:05:00',
        ]);
        $this->insertQueueRow([
            'id' => 'admin-sent-newer',
            'source_app' => 'app-b',
            'status' => 'sent',
            'provider_message_id' => 'provider-newer',
            'sent_at' => '2026-01-01 10:10:00',
        ]);
        $this->insertQueueRow(['id' => 'admin-pending-hidden', 'source_app' => 'app-a', 'status' => 'pending']);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/sent?limit=10')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['admin-sent-newer', 'admin-sent-older'], array_column($payload['emails'], 'id'));
        self::assertSame(['app-b', 'app-a'], array_column($payload['emails'], 'sourceApp'));
        self::assertSame('provider-newer', $payload['emails'][0]['providerMessageId']);
    }

    public function testAdminUnsentFiltersByContextIdAndExposesOriginFields(): void
    {
        $this->insertQueueRow(['id' => 'admin-ctx-match', 'context_id' => 'evt-1', 'batch_id' => 'batch-9', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'admin-ctx-other', 'context_id' => 'evt-2', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'admin-ctx-none', 'status' => 'pending']);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/unsent?limit=10&contextId=evt-1')
            ->withQueryParams(['limit' => '10', 'contextId' => 'evt-1'])
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('evt-1', $payload['contextId']);
        self::assertSame(['admin-ctx-match'], array_column($payload['emails'], 'id'));
        self::assertSame('batch-9', $payload['emails'][0]['batchId']);
        self::assertSame('evt-1', $payload['emails'][0]['contextId']);
    }

    public function testAdminSentFiltersByContextId(): void
    {
        $this->insertQueueRow(['id' => 'admin-sent-ctx', 'context_id' => 'evt-1', 'status' => 'sent', 'sent_at' => '2026-01-01 10:05:00']);
        $this->insertQueueRow(['id' => 'admin-sent-other', 'status' => 'sent', 'sent_at' => '2026-01-01 10:06:00']);
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/sent?limit=10&contextId=evt-1')
            ->withQueryParams(['limit' => '10', 'contextId' => 'evt-1'])
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['admin-sent-ctx'], array_column($payload['emails'], 'id'));
        self::assertNull($payload['emails'][0]['batchId']);
    }

    public function testClientEndpointsStillRequireClientApiKey(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/diagnostics')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    private function backdateHeartbeat(string $queue, int $secondsAgo): void
    {
        $timestamp = (new \DateTimeImmutable(sprintf('-%d seconds', $secondsAgo)))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE email_worker_heartbeats SET last_seen_at = :ts WHERE queue = :queue');
        $stmt->execute(['ts' => $timestamp, 'queue' => $queue]);
    }

    private function app(): \Slim\App
    {
        $container = new Container();
        $env = new Env([
            'ADMIN_API_KEY' => 'admin-secret',
            'SMTP_HOST' => 'smtp.example.test',
            'SMTP_USER' => 'sender@example.test',
            'SMTP_FROM_EMAIL' => 'sender@example.test',
            'GMAIL_SMTP_USER' => 'technical@example.test',
            'GMAIL_FROM_EMAIL' => 'technical@example.test',
            'EMAIL_RATE_LIMIT_COUNT' => '10',
            'EMAIL_RATE_LIMIT_WINDOW_MINUTES' => '15',
            'EMAIL_WORKER_HEARTBEAT_STALE_SECONDS' => '60',
        ]);
        $container->set(PDO::class, $this->pdo);
        $container->set(Env::class, $env);
        $container->set(AttachmentStorage::class, $this->attachmentStorage);
        $container->set(EmailQueueRepository::class, $this->repository);
        $container->set(RateLimitRepository::class, new RateLimitRepository($this->pdo));
        $container->set(WorkerHeartbeatRepository::class, new WorkerHeartbeatRepository($this->pdo));
        $container->set(ClientRepository::class, new ClientRepository($this->pdo));
        $container->set(AdminController::class, fn ($c) => new AdminController(
            $c->get(PDO::class),
            $c->get(AttachmentStorage::class),
            $c->get(EmailQueueRepository::class),
            $c->get(RateLimitRepository::class),
            $c->get(WorkerHeartbeatRepository::class),
            $c->get(Env::class)
        ));

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->get('/emails/diagnostics', fn ($request, Response $response): Response => $response);
        $app->add(new ApiKeyAuthMiddleware($container->get(ClientRepository::class), $container->get(Env::class)));
        $app->add(new AdminKeyAuthMiddleware($container->get(Env::class)));
        AdminRoutes::register($app);

        return $app;
    }
}
