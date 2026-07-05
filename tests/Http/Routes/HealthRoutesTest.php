<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Routes;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Http\Routes\HealthRoutes;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use DI\Container;
use PDO;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthRoutesTest extends DatabaseTestCase
{
    public function testHealthIsDegradedWithoutFreshWorkerHeartbeats(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('degraded', $payload['status']);
        self::assertSame('degraded', $payload['checks']['workers']);
    }

    public function testHealthIsOkWithFreshStandardAndTechnicalWorkerHeartbeats(): void
    {
        $heartbeats = new WorkerHeartbeatRepository($this->pdo);
        $heartbeats->beat('standard:test', 'standard');
        $heartbeats->beat('technical:test', 'technical');
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertSame('ok', $payload['checks']['workers']);
    }

    private function app(): \Slim\App
    {
        $container = new Container();
        $container->set(PDO::class, $this->pdo);
        $container->set(Env::class, new Env(['EMAIL_WORKER_HEARTBEAT_STALE_SECONDS' => '60']));
        $container->set(AttachmentStorage::class, $this->attachmentStorage);
        $container->set(WorkerHeartbeatRepository::class, new WorkerHeartbeatRepository($this->pdo));
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        HealthRoutes::register($app);

        return $app;
    }
}
