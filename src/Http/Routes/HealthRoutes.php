<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Http\ApiVersion;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

final class HealthRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();

        $app->get('/health', function ($request, ResponseInterface $response) use ($container): ResponseInterface {
            $container->get(PDO::class)->query('SELECT 1');
            $container->get(AttachmentStorage::class)->assertWritable();
            $env = $container->get(Env::class);
            $freshSeconds = max(
                10,
                $env->int('EMAIL_WORKER_HEARTBEAT_STALE_SECONDS', 60),
                $env->int('EMAIL_WORKER_SLEEP_SECONDS', 10) * 3,
                $env->int('TECHNICAL_EMAIL_WORKER_SLEEP_SECONDS', 10) * 3
            );
            $freshSince = (new \DateTimeImmutable(sprintf('-%d seconds', $freshSeconds)))->format('Y-m-d H:i:s');
            $workerCounts = $container->get(WorkerHeartbeatRepository::class)->activeCountsSince($freshSince);
            $workersOk = $workerCounts['standard'] > 0 && $workerCounts['technical'] > 0;
            $response->getBody()->write(json_encode([
                'status' => $workersOk ? 'ok' : 'degraded',
                'apiVersion' => ApiVersion::VERSION,
                'checks' => [
                    'database' => 'ok',
                    'attachments' => 'writable',
                    'workers' => $workersOk ? 'ok' : 'degraded',
                ],
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });
    }
}
