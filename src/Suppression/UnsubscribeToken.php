<?php

declare(strict_types=1);

namespace CentralMailer\Suppression;

/**
 * Stateless HMAC unsubscribe token. Deliberately has no expiry: unsubscribe links
 * must keep working from months-old emails (Gmail one-click requirement).
 */
final class UnsubscribeToken
{
    public function __construct(
        private readonly string $secret,
        private readonly ?string $previousSecret = null
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('Unsubscribe secret must not be empty');
        }
    }

    public function generate(string $email, string $sourceApp): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'e' => mb_strtolower(trim($email)),
            's' => $sourceApp,
            'v' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload . '.' . $this->signature($payload, $this->secret);
    }

    /** @return array{email: string, sourceApp: string}|null */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$payload, $signature] = $parts;

        $valid = false;
        foreach ([$this->secret, $this->previousSecret] as $secret) {
            if ($secret !== null && $secret !== '' && hash_equals($this->signature($payload, $secret), $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return null;
        }

        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            return null;
        }
        $email = $decoded['e'] ?? null;
        $sourceApp = $decoded['s'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($sourceApp)) {
            return null;
        }

        return ['email' => $email, 'sourceApp' => $sourceApp];
    }

    private function signature(string $payload, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), false);
    }
}
