<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Middleware;

use CentralMailer\Config\Env;
use CentralMailer\Http\Middleware\AdminKeyAuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AdminKeyAuthMiddlewareTest extends TestCase
{
    public function testRejectsMissingAdminKey(): void
    {
        $middleware = new AdminKeyAuthMiddleware(new Env(['ADMIN_API_KEY' => 'admin-secret']));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/status');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Invalid or missing admin key', (string) $response->getBody());
    }

    public function testRejectsInvalidAdminKey(): void
    {
        $middleware = new AdminKeyAuthMiddleware(new Env(['ADMIN_API_KEY' => 'admin-secret']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'wrong');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAcceptsValidAdminKey(): void
    {
        $middleware = new AdminKeyAuthMiddleware(new Env(['ADMIN_API_KEY' => 'admin-secret']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/status')
            ->withHeader('X-Admin-Key', 'admin-secret');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresNonAdminPaths(): void
    {
        $middleware = new AdminKeyAuthMiddleware(new Env(['ADMIN_API_KEY' => 'admin-secret']));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/emails');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
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
