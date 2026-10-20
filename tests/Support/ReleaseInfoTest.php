<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Support;

use CentralMailer\Support\ReleaseInfo;
use PHPUnit\Framework\TestCase;

final class ReleaseInfoTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'release');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testReadsTheVersionAndShortCommitOfATaggedDeploy(): void
    {
        file_put_contents($this->path, "GIT_SHA=5d3e47e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=v2026.10.0\n");

        self::assertSame(['version' => '2026.10.0', 'commit' => '5d3e47e'], ReleaseInfo::fromFile($this->path));
    }

    public function testAnUntaggedDeployHasACommitButNoVersion(): void
    {
        file_put_contents($this->path, "GIT_SHA=5d3e47e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=\n");

        self::assertSame(['version' => null, 'commit' => '5d3e47e'], ReleaseInfo::fromFile($this->path));
    }

    public function testAMissingMarkerGivesNulls(): void
    {
        self::assertSame(['version' => null, 'commit' => null], ReleaseInfo::fromFile($this->path . '-missing'));
    }
}
