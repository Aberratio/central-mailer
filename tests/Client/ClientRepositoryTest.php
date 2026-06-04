<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Client;

use CentralMailer\Client\ClientRepository;
use CentralMailer\Config\Env;
use CentralMailer\Tests\Support\DatabaseTestCase;

final class ClientRepositoryTest extends DatabaseTestCase
{
    public function testSyncsLegacyClientAndRotatesExistingKey(): void
    {
        $repository = new ClientRepository($this->pdo);
        $this->pdo->exec("DELETE FROM email_clients WHERE source_app = 'app-b'");
        $repository->syncLegacyClients(new Env([
            'API_KEY_APP_A' => 'new-key-that-must-not-overwrite',
            'API_KEY_APP_B' => 'app-b-key',
        ]));

        self::assertSame('app-a', $repository->sourceAppForApiKey('new-key-that-must-not-overwrite'));
        self::assertSame('app-b', $repository->sourceAppForApiKey('app-b-key'));
    }
}
