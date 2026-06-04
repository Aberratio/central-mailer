<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; "
                . "style-src 'self' 'unsafe-inline' https://unpkg.com; "
                . "script-src 'self' 'unsafe-inline' https://unpkg.com; "
                . "img-src 'self' data: https:; connect-src 'self'"
            );

        if (strtolower($this->env->string('APP_ENV', 'local')) === 'production') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
