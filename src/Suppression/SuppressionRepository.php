<?php

declare(strict_types=1);

namespace CentralMailer\Suppression;

use CentralMailer\Support\Uuid;
use PDO;

final class SuppressionRepository
{
    public const REASONS = ['bounce', 'complaint', 'unsubscribe', 'manual'];
    public const APPLIES_TO = ['all', 'marketing'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param string $sourceApp empty string = suppression applies to every client
     */
    public function add(
        string $email,
        string $reason,
        string $appliesTo = 'all',
        string $sourceApp = '',
        ?string $originEmailId = null,
        ?string $details = null
    ): bool {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Suppression reason is invalid');
        }
        if (!in_array($appliesTo, self::APPLIES_TO, true)) {
            throw new \InvalidArgumentException('Suppression scope is invalid');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO email_suppressions
                 (id, email, source_app, reason, applies_to, origin_email_id, details, created_at)
                 VALUES
                 (:id, :email, :source_app, :reason, :applies_to, :origin_email_id, :details, :created_at)'
            );
            $stmt->execute([
                'id' => Uuid::v4(),
                'email' => mb_strtolower(trim($email)),
                'source_app' => $sourceApp,
                'reason' => $reason,
                'applies_to' => $appliesTo,
                'origin_email_id' => $originEmailId,
                'details' => $details === null ? null : mb_substr($details, 0, 2000),
                'created_at' => self::now(),
            ]);

            return true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }

    public function isSuppressed(string $email, string $sourceApp, string $category): bool
    {
        return $this->filterSuppressed([$email], $sourceApp, $category) !== [];
    }

    /**
     * @param list<string> $emails
     * @return list<string> lowercased addresses that are suppressed for this client and category
     */
    public function filterSuppressed(array $emails, string $sourceApp, string $category): array
    {
        if ($emails === []) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            $emails
        )));
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $appliesToClause = $category === 'marketing'
            ? "applies_to IN ('all', 'marketing')"
            : "applies_to = 'all'";
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT email
             FROM email_suppressions
             WHERE email IN ($placeholders)
               AND (source_app = '' OR source_app = ?)
               AND $appliesToClause"
        );
        $stmt->execute([...$normalized, $sourceApp]);

        return array_map(static fn (array $row): string => (string) $row['email'], $stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, source_app, reason, applies_to, origin_email_id, details, created_at
             FROM email_suppressions
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function remove(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_suppressions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
