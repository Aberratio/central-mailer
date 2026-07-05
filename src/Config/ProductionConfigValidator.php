<?php

declare(strict_types=1);

namespace CentralMailer\Config;

final class ProductionConfigValidator
{
    public static function validate(Env $env): void
    {
        if (strtolower($env->string('APP_ENV', 'local')) !== 'production') {
            return;
        }

        $errors = [];
        if ($env->bool('APP_DEBUG', false)) {
            $errors[] = 'APP_DEBUG must be false';
        }
        $origins = array_values(array_filter(array_map(
            'trim',
            explode(',', $env->string('APP_CORS_ORIGIN', '*'))
        )));
        if ($origins === [] || in_array('*', $origins, true)) {
            $errors[] = 'APP_CORS_ORIGIN must contain explicit origins';
        }
        foreach ($origins as $origin) {
            if (!self::isHttpsUrl($origin)) {
                $errors[] = sprintf('CORS origin must be an HTTPS URL: %s', $origin);
            }
        }
        if (!self::isHttpsUrl($env->string('APP_URL', ''))) {
            $errors[] = 'APP_URL must use https';
        }
        if ($env->string('DB_PASSWORD', '') === '') {
            $errors[] = 'DB_PASSWORD must not be empty';
        }
        if (strlen($env->string('BACKUP_ENCRYPTION_KEY', '')) < 32) {
            $errors[] = 'BACKUP_ENCRYPTION_KEY must contain at least 32 characters';
        }
        if ($env->int('SMTP_DEBUG_LEVEL', 0) !== 0) {
            $errors[] = 'SMTP_DEBUG_LEVEL must be 0';
        }
        if ($env->bool('APP_DOCS_PUBLIC', false)) {
            $errors[] = 'APP_DOCS_PUBLIC must be false';
        }
        if ($env->bool('APP_DOCS_ENABLED', true)) {
            $errors[] = 'APP_DOCS_ENABLED must be false';
        }
        if (strlen($env->string('ADMIN_API_KEY', '')) < 32) {
            $errors[] = 'ADMIN_API_KEY must contain at least 32 characters';
        }

        self::validateSecureTransport($env, 'SMTP_SECURE', $errors);
        self::validateSecureTransport($env, 'GMAIL_SMTP_SECURE', $errors);

        foreach ($env->apiKeys() as $sourceApp => $apiKey) {
            if (strlen($apiKey) < 32) {
                $errors[] = sprintf('API key for %s must contain at least 32 characters', $sourceApp);
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('Unsafe production configuration: ' . implode('; ', $errors));
        }
    }

    private static function isHttpsUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== '';
    }

    /** @param list<string> $errors */
    private static function validateSecureTransport(Env $env, string $key, array &$errors): void
    {
        if (!in_array(strtolower($env->string($key, 'tls')), ['tls', 'ssl'], true)) {
            $errors[] = sprintf('%s must be tls or ssl', $key);
        }
    }
}
