<?php

declare(strict_types=1);

namespace CentralMailer\Controllers;

use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailQueueService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class EmailController
{
    public function __construct(
        private readonly EmailQueueService $queueService,
        private readonly EmailQueueRepository $repository
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->payload($request);
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $result = $this->queueService->enqueue($sourceApp, $payload, $request->getHeaderLine('Idempotency-Key'));

        return $this->json(['id' => $result->id, 'status' => $result->status], $result->created ? 201 : 200);
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->payload($request);
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $result = $this->queueService->enqueueTest($sourceApp, $payload, $request->getHeaderLine('Idempotency-Key'));

        return $this->json(['id' => $result->id, 'status' => $result->status], $result->created ? 201 : 200);
    }

    public function batch(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->payload($request);
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $result = $this->queueService->enqueueBatch($sourceApp, $payload, $request->getHeaderLine('Idempotency-Key'));

        return $this->json([
            'id' => $result->id,
            'emails' => $result->emails,
        ], $result->created ? 201 : 200);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        $row = $this->repository->findForSourceApp($args['id'], $sourceApp);

        if ($row === null) {
            return $this->json(['error' => 'Email not found'], 404);
        }

        return $this->json([
            'id' => $row['id'],
            'status' => $row['status'],
            'sourceApp' => $row['source_app'],
            'to' => $row['recipient_email'],
            'subject' => $row['subject'],
            'priority' => $row['priority'],
            'attempts' => (int) $row['attempts'],
            'lastError' => $row['last_error'],
            'providerMessageId' => $row['provider_message_id'],
            'createdAt' => $row['created_at'],
            'sentAt' => $row['sent_at'],
            'batchId' => $row['batch_id'],
        ]);
    }

    /** @param array<string, string> $args */
    public function events(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sourceApp = (string) $request->getAttribute('sourceApp');
        if ($this->repository->findForSourceApp($args['id'], $sourceApp) === null) {
            return $this->json(['error' => 'Email not found'], 404);
        }

        $events = array_map(static function (array $row): array {
            return [
                'type' => $row['event_type'],
                'status' => $row['status'],
                'attempt' => (int) $row['attempt'],
                'errorCode' => $row['error_code'],
                'errorMessage' => $row['error_message'],
                'providerMessageId' => $row['provider_message_id'],
                'details' => $row['details'] === null ? null : json_decode($row['details'], true, flags: JSON_THROW_ON_ERROR),
                'createdAt' => $row['created_at'],
            ];
        }, $this->repository->findEventsForSourceApp($args['id'], $sourceApp));

        return $this->json(['id' => $args['id'], 'events' => $events]);
    }

    /** @return array<string, mixed> */
    private function payload(ServerRequestInterface $request): array
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON object body is required', 400);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
