<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Config;

use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use PHPUnit\Framework\TestCase;

final class ProductionConfigValidatorTest extends TestCase
{
    public function testRejectsUnsafeProductionConfiguration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false');

        ProductionConfigValidator::validate(new Env([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://mailer.example',
            'APP_CORS_ORIGIN' => '*',
            'APP_DOCS_PUBLIC' => 'true',
            'DB_PASSWORD' => '',
            'SMTP_SECURE' => 'none',
            'GMAIL_SMTP_SECURE' => 'tls',
            'SMTP_DEBUG_LEVEL' => '1',
            'API_KEY_APP_A' => 'short',
        ]));
    }

    public function testAcceptsSafeProductionConfiguration(): void
    {
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
            'API_KEY_APP_A' => str_repeat('a', 32),
            'ADMIN_API_KEY' => str_repeat('c', 32),
            'UNSUBSCRIBE_SECRET' => str_repeat('u', 32),
        ]));

        self::assertTrue(true);
    }

    public function testAllowsMissingUnsubscribeSecretWhenInstallationIsTransactionalOnly(): void
    {
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
            'API_KEY_APP_A' => str_repeat('a', 32),
            'ADMIN_API_KEY' => str_repeat('c', 32),
            'EMAIL_BATCH_DEFAULT_CATEGORY' => 'transactional',
        ]));

        self::assertTrue(true);
    }

    public function testRejectsMissingUnsubscribeSecretInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UNSUBSCRIBE_SECRET must contain at least 32 characters');

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
            'API_KEY_APP_A' => str_repeat('a', 32),
            'ADMIN_API_KEY' => str_repeat('c', 32),
        ]));
    }
}
