<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Support\Uuid;
use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\LoggerInterface;

final class EmailQueueService
{
    public function __construct(
        private readonly EmailQueueRepository $repository,
        private readonly EmailRequestValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly AttachmentStorage $attachmentStorage
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $sourceApp, array $payload, ?string $idempotencyKey = null): EnqueueResult
    {
        $validated = $this->validator->validateQueuePayload($payload);
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $id = Uuid::v4();
        $attachments = $this->attachmentStorage->store($id, $validated['attachments']);
        try {
            $result = $this->repository->insert([
                ...$validated,
                'id' => $id,
                'attachments' => $attachments,
                'sourceApp' => $sourceApp,
                'idempotencyKey' => $idempotencyKey,
            ]);
        } catch (\Throwable $exception) {
            $this->attachmentStorage->delete($id);
            throw $exception;
        }
        if (!$result->created) {
            $this->attachmentStorage->delete($id);
        }

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
    public function enqueueBatch(string $sourceApp, array $payload, ?string $idempotencyKey = null): BatchEnqueueResult
    {
        $validated = $this->validator->validateBatchPayload($payload);
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $result = $this->repository->insertBatch([
            ...$validated,
            'sourceApp' => $sourceApp,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $this->logger->info($result->created ? 'Email batch queued' : 'Email batch enqueue replayed', [
            'id' => $result->id,
            'sourceApp' => $sourceApp,
            'emailCount' => count($result->emails),
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
