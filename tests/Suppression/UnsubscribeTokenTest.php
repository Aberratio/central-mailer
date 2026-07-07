<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Suppression;

use CentralMailer\Suppression\UnsubscribeToken;
use PHPUnit\Framework\TestCase;

final class UnsubscribeTokenTest extends TestCase
{
    public function testRoundTripsEmailAndSourceApp(): void
    {
        $token = new UnsubscribeToken(str_repeat('s', 32));

        $verified = $token->verify($token->generate('User@Example.test', 'app-a'));

        self::assertSame(['email' => 'user@example.test', 'sourceApp' => 'app-a'], $verified);
    }

    public function testRejectsTamperedToken(): void
    {
        $token = new UnsubscribeToken(str_repeat('s', 32));
        $generated = $token->generate('user@example.test', 'app-a');
        [$payload, $signature] = explode('.', $generated);
        $otherPayload = explode('.', $token->generate('other@example.test', 'app-a'))[0];

        self::assertNull($token->verify($otherPayload . '.' . $signature));
        self::assertNull($token->verify($payload . '.tampered'));
        self::assertNull($token->verify('garbage'));
        self::assertNull($token->verify(''));
    }

    public function testRejectsTokenSignedWithDifferentSecret(): void
    {
        $generated = (new UnsubscribeToken(str_repeat('a', 32)))->generate('user@example.test', 'app-a');

        self::assertNull((new UnsubscribeToken(str_repeat('b', 32)))->verify($generated));
    }

    public function testAcceptsTokenSignedWithPreviousSecretAfterRotation(): void
    {
        $oldSecret = str_repeat('a', 32);
        $generated = (new UnsubscribeToken($oldSecret))->generate('user@example.test', 'app-a');

        $rotated = new UnsubscribeToken(str_repeat('b', 32), $oldSecret);

        self::assertNotNull($rotated->verify($generated));
    }
}
