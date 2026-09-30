<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\DeliveryMode;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreSafeForPortablePhp(): void
    {
        $config = new Config();

        self::assertSame(DeliveryMode::Await, $config->delivery);
        self::assertSame(5_000, $config->timeoutMs);
        self::assertTrue($config->captureTelemetry);
        self::assertTrue($config->redactSecrets);
        self::assertFalse($config->hasDeliveryPath());
        self::assertFalse($config->requestCapabilityEnabled());
        self::assertSame('warning', $config->descriptionLengthLogLevel);
    }

    public function testDescriptionLengthLogLevelRejectsUnknownLevels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('descriptionLengthLogLevel must be one of none, debug, info, warning.');

        new Config(descriptionLengthLogLevel: 'error');
    }

    public function testDeferredDeliveryRequiresScheduler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deferred delivery requires a scheduler');

        new Config(delivery: DeliveryMode::Deferred);
    }

    public function testRequestCapabilityTriStateRequiresDelivery(): void
    {
        self::assertFalse((new Config(requestCapability: true))->requestCapabilityEnabled());
        self::assertTrue((new Config(apiKey: 'test-key'))->requestCapabilityEnabled());
        self::assertFalse((new Config(apiKey: 'test-key', requestCapability: false))->requestCapabilityEnabled());
        self::assertTrue((new Config(apiKey: 'test-key', requestCapability: true))->requestCapabilityExplicit());
    }

    public function testFieldMapAcceptsPublicAndLegacyPurposeKeys(): void
    {
        $map = ['call_purpose' => 'action', 'agent_thinking' => 'legacy'];
        self::assertSame($map, (new Config(telemetryFieldMap: $map))->telemetryFieldMap);
    }

    public function testFieldMapRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown telemetry field map key');

        new Config(telemetryFieldMap: ['user_turn' => 'turn']);
    }
}
