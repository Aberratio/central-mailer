<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Client\ClientRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ClientRepository $clients)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'OPTIONS' || in_array($path, ['/docs', '/openapi.json', '/health'], true)) {
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
