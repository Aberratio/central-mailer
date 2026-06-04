<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');
        $maxBytes = max(1, $this->env->int('APP_MAX_REQUEST_BODY_BYTES', 12_000_000));
        $streamSize = $request->getBody()->getSize();

        if (
            ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBytes)
            || ($streamSize !== null && $streamSize > $maxBytes)
        ) {
            $response = new Response(413);
            $response->getBody()->write(json_encode(['error' => 'Request body is too large'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
