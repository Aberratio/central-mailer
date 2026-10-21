<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Client;

use CentralMailer\Client\ClientRepository;
use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use CentralMailer\Tests\Support\DatabaseTestCase;

/** Dedicated `zmierzymy-czas` client whose key comes only from the ZMIERZYMY_CZAS_API_KEY variable. */
final class ZmierzymyCzasClientTest extends DatabaseTestCase
{
    public function testEnvExposesDedicatedKeyUnderItsOwnSourceApp(): void
    {
        $keys = (new Env(['ZMIERZYMY_CZAS_API_KEY' => 'zc-key']))->apiKeys();

        self::assertSame(['zmierzymy-czas' => 'zc-key'], $keys);
        self::assertSame([], (new Env([]))->apiKeys());
    }

    public function testSyncCreatesDedicatedClientAndLegacyKeysStayIndependent(): void
    {
        $repository = new ClientRepository($this->pdo);
        $repository->syncLegacyClients(new Env([
            'API_KEY_APP_A' => 'app-a-key',
            'ZMIERZYMY_CZAS_API_KEY' => 'zc-key',
        ]));

        self::assertSame('zmierzymy-czas', $repository->sourceAppForApiKey('zc-key'));
        self::assertSame('app-a', $repository->sourceAppForApiKey('app-a-key'));
        self::assertNotNull($repository->policy('zmierzymy-czas'));
    }

    public function testShortDedicatedKeyIsRejectedInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key for zmierzymy-czas');

        ProductionConfigValidator::validate(new Env([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://mailer.example',
            'APP_CORS_ORIGIN' => 'https://app.example',
            'APP_DOCS_ENABLED' => 'false',
            'APP_DOCS_PUBLIC' => 'false',
            'DB_PASSWORD' => 'strong-database-password',
            'BACKUP_ENCRYPTION_KEY' => str_repeat('b', 32),
            'SMTP_SECURE' => 'tls',
            'GMAIL_SMTP_SECURE' => 'tls',
            'SMTP_DEBUG_LEVEL' => '0',
            'ADMIN_API_KEY' => str_repeat('c', 32),
            'UNSUBSCRIBE_SECRET' => str_repeat('u', 32),
            'ZMIERZYMY_CZAS_API_KEY' => 'short',
        ]));
    }
}
