<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AdminKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'OPTIONS' || !str_starts_with($path, '/admin/')) {
            return $handler->handle($request);
        }

        $expected = $this->env->nullableString('ADMIN_API_KEY');
        if ($expected === null) {
            $response = new Response(503);
            $response->getBody()->write(json_encode(['error' => 'Admin API key is not configured'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $provided = $request->getHeaderLine('X-Admin-Key');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Invalid or missing admin key'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
