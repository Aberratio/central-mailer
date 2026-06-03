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
    public function enqueue(string $sourceApp, array $payload): string
    {
        $validated = $this->validator->validateQueuePayload($payload);
        $id = $this->repository->insert([
            ...$validated,
            'sourceApp' => $sourceApp,
        ]);

        $this->logger->info('Email queued', [
            'id' => $id,
            'sourceApp' => $sourceApp,
            'recipient' => $validated['to'],
            'priority' => $validated['priority'],
        ]);

        return $id;
    }

    /** @param array<string, mixed> $payload */
    public function enqueueTest(string $sourceApp, array $payload): string
    {
        $validated = $this->validator->validateTestPayload($payload);

        return $this->enqueue($sourceApp, [
            'to' => $validated['to'],
            'subject' => 'Test centralnej uslugi mailowej',
            'html' => '<p>To jest testowa wiadomosc dodana do kolejki centralnej uslugi mailowej.</p>',
            'text' => 'To jest testowa wiadomosc dodana do kolejki centralnej uslugi mailowej.',
            'priority' => 'normal',
            'metadata' => ['type' => 'test'],
        ]);
    }
}
