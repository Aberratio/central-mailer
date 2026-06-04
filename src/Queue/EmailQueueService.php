<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\LoggerInterface;

final class EmailQueueService
{
    public function __construct(
        private readonly EmailQueueRepository $repository,
        private readonly EmailRequestValidator $validator,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $sourceApp, array $payload, ?string $idempotencyKey = null): EnqueueResult
    {
        $validated = $this->validator->validateQueuePayload($payload);
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $result = $this->repository->insert([
            ...$validated,
            'sourceApp' => $sourceApp,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $this->logger->info($result->created ? 'Email queued' : 'Email enqueue replayed', [
            'id' => $result->id,
            'sourceApp' => $sourceApp,
            'recipient' => $validated['to'],
            'priority' => $validated['priority'],
            'idempotentReplay' => !$result->created,
        ]);

        return $result;
    }

    /** @param array<string, mixed> $payload */
    public function enqueueTest(string $sourceApp, array $payload, ?string $idempotencyKey = null): EnqueueResult
    {
        $validated = $this->validator->validateTestPayload($payload);

        return $this->enqueue($sourceApp, [
            'to' => $validated['to'],
            'subject' => 'Test centralnej uslugi mailowej',
            'html' => '<p>To jest testowa wiadomosc dodana do kolejki centralnej uslugi mailowej.</p>',
            'text' => 'To jest testowa wiadomosc dodana do kolejki centralnej uslugi mailowej.',
            'priority' => 'normal',
            'metadata' => ['type' => 'test'],
        ], $idempotencyKey);
    }

    private function validateIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        if (strlen($idempotencyKey) > 255 || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Idempotency-Key must contain 1 to 255 visible ASCII characters');
        }

        return $idempotencyKey;
    }
}
