<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\NullEmitter;
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
        self::assertFalse($config->sendFeedbackEnabled());
        self::assertNull($config->sendFeedback);
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

    public function testSendFeedbackIsOnByDefaultAndRequiresDelivery(): void
    {
        self::assertTrue((new Config(apiKey: 'test-key'))->sendFeedbackEnabled());
        self::assertTrue((new Config(emitter: new NullEmitter()))->sendFeedbackEnabled());
        self::assertFalse((new Config())->sendFeedbackEnabled());
        self::assertFalse((new Config(sendFeedback: true))->sendFeedbackEnabled());
        self::assertFalse((new Config(apiKey: 'test-key', enabled: false))->sendFeedbackEnabled());
        self::assertFalse((new Config(apiKey: 'test-key', enabled: false, sendFeedback: true))->sendFeedbackEnabled());
        self::assertTrue((new Config(apiKey: 'test-key', sendFeedback: true))->sendFeedbackEnabled());
        self::assertNull((new Config(apiKey: 'test-key'))->sendFeedbackSetting());
        self::assertFalse((new Config(apiKey: 'test-key'))->sendFeedbackExplicit());
        self::assertTrue((new Config(apiKey: 'test-key', sendFeedback: true))->sendFeedbackExplicit());
    }

    public function testSendFeedbackFalseDisablesIt(): void
    {
        $config = new Config(apiKey: 'test-key', sendFeedback: false);
        self::assertFalse($config->sendFeedbackEnabled());
        self::assertFalse($config->sendFeedbackExplicit());
        self::assertFalse($config->requestCapabilityEnabled());
    }

    public function testDeprecatedRequestCapabilityKeyIsAnAlias(): void
    {
        self::assertFalse((new Config(apiKey: 'test-key', requestCapability: false))->sendFeedbackEnabled());
        self::assertTrue((new Config(apiKey: 'test-key', requestCapability: true))->sendFeedbackEnabled());
        self::assertTrue((new Config(apiKey: 'test-key', requestCapability: true))->sendFeedbackExplicit());
        self::assertTrue((new Config(apiKey: 'test-key'))->requestCapabilityEnabled());
        self::assertTrue((new Config(apiKey: 'test-key', requestCapability: true))->requestCapabilityExplicit());
    }

    public function testFromEnvironmentCanDisableSendFeedback(): void
    {
        $previous = \getenv('ANALYTICS_INGEST_API_KEY');
        \putenv('ANALYTICS_INGEST_API_KEY=test-key');
        try {
            self::assertTrue(Config::fromEnvironment()->sendFeedbackEnabled());
            self::assertFalse(Config::fromEnvironment(sendFeedback: false)->sendFeedbackEnabled());
            self::assertTrue(Config::fromEnvironment(sendFeedback: true)->sendFeedbackExplicit());
        } finally {
            \putenv(false === $previous ? 'ANALYTICS_INGEST_API_KEY' : 'ANALYTICS_INGEST_API_KEY=' . $previous);
        }
    }

    public function testNewSendFeedbackKeyWinsOverTheOldKey(): void
    {
        $off = new Config(apiKey: 'test-key', requestCapability: true, sendFeedback: false);
        self::assertFalse($off->sendFeedbackSetting());
        self::assertFalse($off->sendFeedbackEnabled());
        self::assertFalse($off->sendFeedbackExplicit());

        $on = new Config(apiKey: 'test-key', requestCapability: false, sendFeedback: true);
        self::assertTrue($on->sendFeedbackSetting());
        self::assertTrue($on->sendFeedbackEnabled());
        self::assertTrue($on->sendFeedbackExplicit());
    }

    public function testFieldMapAcceptsPublicAndLegacyPurposeKeys(): void
    {
        $map = ['call_purpose' => 'action', 'agent_thinking' => 'legacy'];
        self::assertSame($map, (new Config(telemetryFieldMap: $map))->telemetryFieldMap);
    }

    public function testFieldMapStillAcceptsRetiredUserFrustrationKey(): void
    {
        $map = ['user_frustration' => 'mood'];
        self::assertSame($map, (new Config(telemetryFieldMap: $map))->telemetryFieldMap);
    }

    public function testFieldMapRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown telemetry field map key');

        new Config(telemetryFieldMap: ['user_turn' => 'turn']);
    }
}
