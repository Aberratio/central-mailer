<?php

declare(strict_types=1);

namespace CentralMailer\Suppression;

use CentralMailer\Queue\EmailQueueRepository;
use Psr\Log\LoggerInterface;

/**
 * Parses DSN (bounce) messages from the Return-Path mailbox and feeds hard bounces
 * into the suppression list. Parsing is separated from IMAP fetching so it can be
 * unit-tested with raw message fixtures.
 */
final class BounceMailboxProcessor
{
    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly EmailQueueRepository $queue,
        private readonly LoggerInterface $logger,
        private readonly ?string $messageIdDomain = null
    ) {
    }

    /** @return array{recipient: string|null, status: string|null, originEmailId: string|null, hardBounce: bool} */
    public function parse(string $rawMessage): array
    {
        $recipient = null;
        if (preg_match('/^X-Failed-Recipients:\s*(.+)$/mi', $rawMessage, $matches) === 1) {
            $recipient = trim($matches[1]);
        } elseif (preg_match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+)>?/i', $rawMessage, $matches) === 1) {
            $recipient = trim($matches[1]);
        }
        if ($recipient !== null && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipient = null;
        }

        $status = null;
        if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $rawMessage, $matches) === 1) {
            $status = $matches[1];
        }

        $originEmailId = null;
        $domainPattern = $this->messageIdDomain === null ? '[^>\s]+' : preg_quote($this->messageIdDomain, '/');
        if (preg_match(
            '/<([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})@' . $domainPattern . '>/i',
            $rawMessage,
            $matches
        ) === 1) {
            $originEmailId = strtolower($matches[1]);
        }

        return [
            'recipient' => $recipient,
            'status' => $status,
            'originEmailId' => $originEmailId,
            'hardBounce' => $status !== null && str_starts_with($status, '5.'),
        ];
    }

    /** @return bool true when the message was a hard bounce and was fed into the suppression list */
    public function process(string $rawMessage): bool
    {
        $parsed = $this->parse($rawMessage);
        if (!$parsed['hardBounce'] || $parsed['recipient'] === null) {
            return false;
        }

        $created = $this->suppressions->add(
            $parsed['recipient'],
            'bounce',
            'all',
            '',
            $parsed['originEmailId'],
            sprintf('DSN status %s', $parsed['status'])
        );
        if ($parsed['originEmailId'] !== null) {
            $this->queue->recordBounce(
                $parsed['originEmailId'],
                $parsed['status'],
                sprintf('Hard bounce reported by DSN for %s', $parsed['recipient'])
            );
        }
        $this->logger->info('Hard bounce processed', [
            'status' => $parsed['status'],
            'originEmailId' => $parsed['originEmailId'],
            'suppressionCreated' => $created,
        ]);

        return true;
    }
}
