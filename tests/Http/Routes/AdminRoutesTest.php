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
        self::assertSame('Mailer standardowy', $payload['mailers'][0]['name']);
        self::assertSame(['normalne', 'wysoki priorytet'], $payload['mailers'][0]['messageTypes']);
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

    public function testClientEndpointsStillRequireClientApiKey(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails/diagnostics')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
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
