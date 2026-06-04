<?php

declare(strict_types=1);

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

namespace CentralMailer\Tests\Support {
    final class DnsStub
    {
        /** @var list<array<string, mixed>> */
        public static array $mxRecords = [];
        public static bool $hasARecord = false;
        public static bool $hasAaaaRecord = false;
        public static int $mxLookupCount = 0;
        public static int $addressLookupCount = 0;

        public static function reset(): void
        {
            self::$mxRecords = [];
            self::$hasARecord = false;
            self::$hasAaaaRecord = false;
            self::$mxLookupCount = 0;
            self::$addressLookupCount = 0;
        }
    }
}

namespace CentralMailer\Validation {
    use CentralMailer\Tests\Support\DnsStub;

    /** @return list<array<string, mixed>> */
    function dns_get_record(string $hostname, int $type = DNS_ANY): array
    {
        DnsStub::$mxLookupCount++;

        return DnsStub::$mxRecords;
    }

    function checkdnsrr(string $hostname, string $type = 'MX'): bool
    {
        DnsStub::$addressLookupCount++;

        return match (strtoupper($type)) {
            'A' => DnsStub::$hasARecord,
            'AAAA' => DnsStub::$hasAaaaRecord,
            default => false,
        };
    }
}
