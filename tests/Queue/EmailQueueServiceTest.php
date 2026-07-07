<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Suppression\RecipientSuppressedException;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;
use CentralMailer\Validation\EmailRequestValidator;
use Psr\Log\NullLogger;

final class EmailQueueServiceTest extends DatabaseTestCase
{
    private EmailQueueService $service;
    private SuppressionRepository $suppressions;

    protected function setUp(): void
    {
        parent::setUp();
        $env = new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']);
        $this->suppressions = new SuppressionRepository($this->pdo);
        $this->service = new EmailQueueService(
            $this->repository,
            new EmailRequestValidator($env),
            new NullLogger(),
            $this->attachmentStorage,
            $env,
            $this->suppressions
        );
    }

    public function testEnqueueRejectsSuppressedRecipient(): void
    {
        $this->suppressions->add('dead@deliverable.test', 'bounce');

        $this->expectException(RecipientSuppressedException::class);

        $this->service->enqueue('app-a', [
            'to' => 'dead@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
        ]);
    }

    public function testEnqueueAllowsTransactionalMailWhenSuppressionIsMarketingScoped(): void
    {
        $this->suppressions->add('optout@deliverable.test', 'unsubscribe', 'marketing', 'app-a');

        $result = $this->service->enqueue('app-a', [
            'to' => 'optout@deliverable.test',
            'subject' => 'Invoice',
            'html' => '<p>Invoice</p>',
        ]);

        self::assertSame('pending', $result->status);
    }

    public function testBatchInsertsSuppressedRecipientsAsFailedWithoutRejectingBatch(): void
    {
        $this->suppressions->add('optout@deliverable.test', 'unsubscribe', 'marketing', 'app-a');

        $result = $this->service->enqueueBatch('app-a', [
            'subject' => 'Newsletter',
            'html' => '<p>News</p>',
            'recipients' => [
                ['to' => 'ok@deliverable.test'],
                ['to' => 'optout@deliverable.test'],
            ],
        ]);

        $statuses = array_column($result->emails, 'status');
        sort($statuses);
        self::assertSame(['failed', 'pending'], $statuses);
    }
}
