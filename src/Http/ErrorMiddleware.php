<?php

declare(strict_types=1);

namespace CentralMailer\Http;

use CentralMailer\Config\Env;
use CentralMailer\Queue\IdempotencyConflictException;
use CentralMailer\Queue\QueueCapacityExceededException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Middleware\ErrorMiddleware as SlimErrorMiddleware;
use Slim\Psr7\Response;

final class ErrorMiddleware
{
    public static function create(App $app, Env $env, LoggerInterface $logger): SlimErrorMiddleware
    {
        $middleware = $app->addErrorMiddleware($env->bool('APP_DEBUG', false), true, true, $logger);
        $middleware->setDefaultErrorHandler(
            function ($request, \Throwable $exception, bool $displayErrorDetails) use ($env, $logger): ResponseInterface {
                $logger->error('HTTP error', [
                    'message' => $exception->getMessage(),
                    'path' => (string) $request->getUri()->getPath(),
                ]);

                $statusCode = match (true) {
                    $exception instanceof IdempotencyConflictException => 409,
                    $exception instanceof QueueCapacityExceededException => 429,
                    $exception instanceof \InvalidArgumentException => 400,
                    default => 500,
                };

                $payload = ['error' => $statusCode === 500 ? 'Internal server error' : $exception->getMessage()];
                if ($displayErrorDetails) {
                    $payload['details'] = $exception->getMessage();
                }

                $response = new Response($statusCode);
                $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

                $response = $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Referrer-Policy', 'no-referrer')
                    ->withHeader('X-Frame-Options', 'DENY')
                    ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

                if (strtolower($env->string('APP_ENV', 'local')) === 'production') {
                    $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
                }

                return $response;
            }
        );

        return $middleware;
    }
}
