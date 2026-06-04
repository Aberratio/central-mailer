<?php

declare(strict_types=1);

namespace CentralMailer\Client;

use CentralMailer\Config\Env;
use PDO;

final class ClientRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sourceAppForApiKey(string $apiKey): ?string
    {
        if ($apiKey === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT source_app FROM email_clients WHERE api_key_hash = :api_key_hash AND active = 1'
        );
        $stmt->execute(['api_key_hash' => hash('sha256', $apiKey)]);
        $sourceApp = $stmt->fetchColumn();

        return $sourceApp === false ? null : (string) $sourceApp;
    }

    public function policy(string $sourceApp): ?ClientPolicy
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_app, queue_weight, rate_limit_count, rate_limit_window_minutes
             FROM email_clients
             WHERE source_app = :source_app AND active = 1'
        );
        $stmt->execute(['source_app' => $sourceApp]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return new ClientPolicy(
            (string) $row['source_app'],
            (int) $row['queue_weight'],
            $row['rate_limit_count'] === null ? null : (int) $row['rate_limit_count'],
            $row['rate_limit_window_minutes'] === null ? null : (int) $row['rate_limit_window_minutes']
        );
    }

    public function syncLegacyClients(Env $env): void
    {
        $now = self::now();

        foreach ($env->apiKeys() as $sourceApp => $apiKey) {
            $apiKeyHash = hash('sha256', $apiKey);
            $existing = $this->pdo->prepare(
                'SELECT api_key_hash FROM email_clients WHERE source_app = :source_app'
            );
            $existing->execute(['source_app' => $sourceApp]);
            $existingHash = $existing->fetchColumn();

            if ($existingHash !== false) {
                if (!hash_equals((string) $existingHash, $apiKeyHash)) {
                    $update = $this->pdo->prepare(
                        'UPDATE email_clients
                         SET api_key_hash = :api_key_hash, updated_at = :updated_at
                         WHERE source_app = :source_app'
                    );
                    $update->execute([
                        'source_app' => $sourceApp,
                        'api_key_hash' => $apiKeyHash,
                        'updated_at' => $now,
                    ]);
                }

                continue;
            }

            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO email_clients
                     (source_app, api_key_hash, active, queue_weight, queue_credit, created_at, updated_at)
                     VALUES
                     (:source_app, :api_key_hash, 1, 1, 0, :created_at, :updated_at)'
                );
                $stmt->execute([
                    'source_app' => $sourceApp,
                    'api_key_hash' => $apiKeyHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
