<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> */
    private array $apiKeys;

    public function __construct(Env $env)
    {
        $this->apiKeys = $env->apiKeys();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'OPTIONS' || in_array($path, ['/docs', '/openapi.json'], true)) {
            return $handler->handle($request);
        }

        $apiKey = $request->getHeaderLine('X-API-Key');
        $sourceApp = $this->sourceAppForKey($apiKey);

        if ($sourceApp === null) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Invalid or missing API key'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request->withAttribute('sourceApp', $sourceApp));
    }

    private function sourceAppForKey(string $apiKey): ?string
    {
        if ($apiKey === '') {
            return null;
        }

        foreach ($this->apiKeys as $sourceApp => $configuredKey) {
            if (hash_equals($configuredKey, $apiKey)) {
                return $sourceApp;
            }
        }

        return null;
    }
}
