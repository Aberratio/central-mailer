<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Middleware;

use CentralMailer\Config\Env;
use CentralMailer\Http\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CorsMiddlewareTest extends TestCase
{
    public function testAllowsConfiguredOriginFromList(): void
    {
        $middleware = new CorsMiddleware(new Env([
            'APP_CORS_ORIGIN' => 'https://one.example, https://two.example',
        ]));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails')
            ->withHeader('Origin', 'https://two.example');

        $response = $middleware->process($request, $this->handler());

        self::assertSame('https://two.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDoesNotAllowUnknownOrigin(): void
    {
        $middleware = new CorsMiddleware(new Env(['APP_CORS_ORIGIN' => 'https://app.example']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emails')
            ->withHeader('Origin', 'https://evil.example');

        $response = $middleware->process($request, $this->handler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
