<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Suppression;

use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;

final class SuppressionRepositoryTest extends DatabaseTestCase
{
    private SuppressionRepository $suppressions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suppressions = new SuppressionRepository($this->pdo);
    }

    public function testAddIsIdempotentPerScope(): void
    {
        self::assertTrue($this->suppressions->add('User@Example.test', 'bounce'));
        self::assertFalse($this->suppressions->add('user@example.test', 'bounce'));
        self::assertTrue($this->suppressions->add('user@example.test', 'unsubscribe', 'marketing', 'app-a'));
    }

    public function testGlobalBounceSuppressionBlocksEveryClientAndCategory(): void
    {
        $this->suppressions->add('dead@example.test', 'bounce', 'all');

        self::assertTrue($this->suppressions->isSuppressed('dead@example.test', 'app-a', 'transactional'));
        self::assertTrue($this->suppressions->isSuppressed('Dead@Example.test', 'app-b', 'marketing'));
    }

    public function testMarketingUnsubscribeIsScopedToClientAndCategory(): void
    {
        $this->suppressions->add('optout@example.test', 'unsubscribe', 'marketing', 'app-a');

        self::assertTrue($this->suppressions->isSuppressed('optout@example.test', 'app-a', 'marketing'));
        self::assertFalse($this->suppressions->isSuppressed('optout@example.test', 'app-a', 'transactional'));
        self::assertFalse($this->suppressions->isSuppressed('optout@example.test', 'app-b', 'marketing'));
    }

    public function testFilterSuppressedReturnsOnlyMatchingAddresses(): void
    {
        $this->suppressions->add('dead@example.test', 'bounce', 'all');
        $this->suppressions->add('optout@example.test', 'unsubscribe', 'marketing', 'app-a');

        $suppressed = $this->suppressions->filterSuppressed(
            ['Dead@Example.test', 'optout@example.test', 'fresh@example.test'],
            'app-a',
            'marketing'
        );

        sort($suppressed);
        self::assertSame(['dead@example.test', 'optout@example.test'], $suppressed);
    }

    public function testRemoveDeletesSuppression(): void
    {
        $this->suppressions->add('dead@example.test', 'manual');
        $id = (string) $this->pdo->query('SELECT id FROM email_suppressions LIMIT 1')->fetchColumn();

        self::assertTrue($this->suppressions->remove($id));
        self::assertFalse($this->suppressions->remove($id));
        self::assertFalse($this->suppressions->isSuppressed('dead@example.test', 'app-a', 'transactional'));
    }

    public function testRejectsInvalidReasonAndScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->suppressions->add('user@example.test', 'because');
    }
}
