<?php

declare(strict_types=1);

namespace CentralMailer\Config;

final class Env
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new \RuntimeException(sprintf('Missing required environment variable: %s', $key));
        }

        return (string) $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, string> */
    public function apiKeys(): array
    {
        return array_filter([
            'app-a' => $this->nullableString('API_KEY_APP_A'),
            'app-b' => $this->nullableString('API_KEY_APP_B'),
        ]);
    }
}
