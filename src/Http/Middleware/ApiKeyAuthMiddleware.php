<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Client\ClientRepository;
use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ClientRepository $clients, private readonly ?Env $env = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $publicDocs = $this->env?->bool('APP_DOCS_PUBLIC', false) ?? false;
        if (
            $request->getMethod() === 'OPTIONS'
            || $path === '/health'
            || $path === '/unsubscribe'
            || str_starts_with($path, '/admin/')
            || ($publicDocs && in_array($path, ['/docs', '/openapi.json'], true))
        ) {
            return $handler->handle($request);
        }

        $apiKey = $request->getHeaderLine('X-API-Key');
        $sourceApp = $this->clients->sourceAppForApiKey($apiKey);

        if ($sourceApp === null) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Invalid or missing API key'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request->withAttribute('sourceApp', $sourceApp));
    }
}
