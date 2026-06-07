<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Http\ApiVersion;
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
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'apiVersion' => ApiVersion::VERSION,
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });
    }
}
