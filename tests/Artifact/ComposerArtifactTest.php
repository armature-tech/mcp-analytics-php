<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Artifact;

use PHPUnit\Framework\TestCase;

final class ComposerArtifactTest extends TestCase
{
    public function testCleanConsumerInstallsAndRunsExactArchive(): void
    {
        $command = \escapeshellarg(PHP_BINARY)
            . ' '
            . \escapeshellarg(\dirname(__DIR__, 2) . '/scripts/run-publish-canary.php');
        \exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, \implode("\n", $output));
        self::assertStringContainsString(
            'Composer artifact canary passed',
            \implode("\n", $output),
        );
    }
}
