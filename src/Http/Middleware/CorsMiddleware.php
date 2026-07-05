<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestOrigin = $request->getHeaderLine('Origin');
        $allowedOrigins = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->env->string('APP_CORS_ORIGIN', '*'))
        )));
        $response = $request->getMethod() === 'OPTIONS'
            ? new Response(204)
            : $handler->handle($request);

        $response = $response
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, X-Admin-Key, Idempotency-Key')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        if (in_array('*', $allowedOrigins, true)) {
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        $response = $response->withAddedHeader('Vary', 'Origin');
        if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
            return $response->withHeader('Access-Control-Allow-Origin', $requestOrigin);
        }

        return $response;
    }
}
