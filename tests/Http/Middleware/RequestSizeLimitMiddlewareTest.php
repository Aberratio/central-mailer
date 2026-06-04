<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Middleware;

use CentralMailer\Config\Env;
use CentralMailer\Http\Middleware\RequestSizeLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RequestSizeLimitMiddlewareTest extends TestCase
{
    public function testRejectsOversizedRequest(): void
    {
        $middleware = new RequestSizeLimitMiddleware(new Env(['APP_MAX_REQUEST_BODY_BYTES' => '100']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/emails')
            ->withHeader('Content-Length', '101');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(413, $response->getStatusCode());
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
