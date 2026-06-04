<?php

declare(strict_types=1);

namespace CentralMailer\Http\Middleware;

use CentralMailer\Config\Env;
use CentralMailer\Queue\EnqueueRateLimitRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class EnqueueRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EnqueueRateLimitRepository $repository,
        private readonly Env $env
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !in_array($request->getUri()->getPath(), ['/emails', '/emails/batch'], true)) {
            return $handler->handle($request);
        }

        $sourceApp = (string) $request->getAttribute('sourceApp');
        $limit = max(1, $this->env->int('EMAIL_ENQUEUE_RATE_LIMIT_COUNT', 60));
        $windowMinutes = max(1, $this->env->int('EMAIL_ENQUEUE_RATE_LIMIT_WINDOW_MINUTES', 1));
        $retentionMinutes = max(
            $windowMinutes,
            $this->env->int('EMAIL_ENQUEUE_RATE_LIMIT_RETENTION_MINUTES', 1440)
        );
        $since = (new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes)))->format('Y-m-d H:i:s');
        $cleanupSince = (new \DateTimeImmutable(sprintf('-%d minutes', $retentionMinutes)))->format('Y-m-d H:i:s');

        if ($this->repository->tryReserve($sourceApp, $limit, $since, $cleanupSince)) {
            return $handler->handle($request);
        }

        $response = new Response(429);
        $response->getBody()->write(json_encode(['error' => 'Email enqueue rate limit reached'], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) ($windowMinutes * 60));
    }
}
