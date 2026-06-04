<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Middleware;

use CentralMailer\Config\Env;
use CentralMailer\Http\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsHstsInProduction(): void
    {
        $middleware = new SecurityHeadersMiddleware(new Env(['APP_ENV' => 'production']));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        });

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
        self::assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }
}
