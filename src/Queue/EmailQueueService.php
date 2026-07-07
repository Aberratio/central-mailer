<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Suppression\RecipientSuppressedException;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Support\Uuid;
use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\LoggerInterface;

final class EmailQueueService
{
    public function __construct(
        private readonly EmailQueueRepository $repository,
        private readonly EmailRequestValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly ?Env $env = null,
        private readonly ?SuppressionRepository $suppressions = null
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $sourceApp, array $payload, ?string $idempotencyKey = null): EnqueueResult
    {
        $validated = $this->validator->validateQueuePayload($payload);
        if ($this->suppressions !== null
            && $this->suppressions->isSuppressed($validated['to'], $sourceApp, $validated['category'])
        ) {
            throw new RecipientSuppressedException('Recipient address is suppressed');
        }
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        if ($idempotencyKey === null) {
            $attachmentBytes = array_sum(array_column($validated['attachments'], 'sizeBytes'));
            $this->repository->assertCanEnqueue(
                $sourceApp,
                1,
                $attachmentBytes,
                $this->maxQueuedEmailsPerClient(),
                $this->maxActiveAttachmentBytesPerClient()
            );
        }
        $id = Uuid::v4();
        $attachments = $this->attachmentStorage->store($id, $validated['attachments']);
        try {
            $result = $this->repository->insert([
                ...$validated,
                'id' => $id,
                'attachments' => $attachments,
                'sourceApp' => $sourceApp,
                'idempotencyKey' => $idempotencyKey,
                'maxQueuedEmailsPerClient' => $this->maxQueuedEmailsPerClient(),
                'maxActiveAttachmentBytesPerClient' => $this->maxActiveAttachmentBytesPerClient(),
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
        // Suppressed recipients do not fail the whole batch; their rows are inserted
        // directly as failed so the caller sees the outcome in the batch status.
        $suppressedRecipients = $this->suppressions === null ? [] : $this->suppressions->filterSuppressed(
            array_map(static fn (array $recipient): string => (string) $recipient['to'], $validated['recipients']),
            $sourceApp,
            $validated['category']
        );
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $attachmentBytes = $this->batchAttachmentBytes($validated);
        if ($idempotencyKey === null) {
            $this->repository->assertCanEnqueue(
                $sourceApp,
                count($validated['recipients']),
                $attachmentBytes,
                $this->maxQueuedEmailsPerClient(),
                $this->maxActiveAttachmentBytesPerClient()
            );
        }
        $storedEmailIds = [];
        foreach ($validated['recipients'] as $index => $recipient) {
            $emailId = Uuid::v4();
            $recipientAttachments = array_merge($validated['attachments'], $recipient['attachments']);
            $validated['recipients'][$index]['id'] = $emailId;
            $validated['recipients'][$index]['attachments'] = $this->attachmentStorage->store($emailId, $recipientAttachments);
            $storedEmailIds[] = $emailId;
        }
        try {
            $result = $this->repository->insertBatch([
                ...$validated,
                'sourceApp' => $sourceApp,
                'idempotencyKey' => $idempotencyKey,
                'suppressedRecipients' => $suppressedRecipients,
                'maxQueuedEmailsPerClient' => $this->maxQueuedEmailsPerClient(),
                'maxActiveAttachmentBytesPerClient' => $this->maxActiveAttachmentBytesPerClient(),
            ]);
        } catch (\Throwable $exception) {
            foreach ($storedEmailIds as $emailId) {
                $this->attachmentStorage->delete($emailId);
            }
            throw $exception;
        }
        if (!$result->created) {
            foreach ($storedEmailIds as $emailId) {
                $this->attachmentStorage->delete($emailId);
            }
        }

        $this->logger->info($result->created ? 'Email batch queued' : 'Email batch enqueue replayed', [
            'id' => $result->id,
            'sourceApp' => $sourceApp,
            'emailCount' => count($result->emails),
            'idempotentReplay' => !$result->created,
        ]);

        return $result;
    }

    /** @param array<string, mixed> $validated */
    private function batchAttachmentBytes(array $validated): int
    {
        $total = 0;
        foreach ($validated['recipients'] as $recipient) {
            $total += array_sum(array_column($validated['attachments'], 'sizeBytes'));
            $total += array_sum(array_column($recipient['attachments'], 'sizeBytes'));
        }

        return $total;
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

    private function maxQueuedEmailsPerClient(): int
    {
        return max(1, $this->env?->int('EMAIL_MAX_QUEUED_PER_CLIENT', 10_000) ?? 10_000);
    }

    private function maxActiveAttachmentBytesPerClient(): int
    {
        return max(0, $this->env?->int('EMAIL_MAX_ACTIVE_ATTACHMENT_BYTES_PER_CLIENT', 100_000_000) ?? 100_000_000);
    }
}
