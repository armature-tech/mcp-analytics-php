<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Contract\Telemetry;
use Armature\McpAnalytics\TelemetryMode;
use PHPUnit\Framework\TestCase;

final class TelemetryTest extends TestCase
{
    public function testCanonicalExtractionVectors(): void
    {
        $decoded = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../Fixtures/telemetry-contract-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $vectors = $decoded['extraction'] ?? null;
        self::assertIsArray($vectors);

        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            self::assertIsString($vector['mode']);
            self::assertIsArray($vector['args']);
            self::assertSame(
                [
                    'arguments' => $vector['expect_args'],
                    'telemetry' => $vector['expect_telemetry'],
                ],
                Telemetry::extract($vector['args'], TelemetryMode::from($vector['mode'])),
                (string) $vector['name'],
            );
        }
    }

    public function testInjectedModeStripsAndNormalizesLegacyFields(): void
    {
        $result = Telemetry::extract([
            'city' => 'Paris',
            'telemetry' => [
                'intent' => 'Find weather',
                'context' => 'Need current conditions',
                'frustration_level' => 'medium',
                'user_turn' => 4,
            ],
        ]);

        self::assertSame(['city' => 'Paris'], $result['arguments']);
        self::assertSame([
            'user_intent' => 'Find weather',
            'agent_thinking' => 'Need current conditions',
            'user_frustration' => 'medium',
        ], $result['telemetry']);
    }

    public function testOwnedModeLeavesArgumentsUntouched(): void
    {
        $arguments = ['telemetry' => ['customer' => true]];
        $result = Telemetry::extract($arguments, TelemetryMode::Owned);

        self::assertSame($arguments, $result['arguments']);
        self::assertNull($result['telemetry']);
    }

    public function testScrubModeDropsTelemetry(): void
    {
        $result = Telemetry::extract(
            ['city' => 'Paris', 'telemetry' => ['user_intent' => 'secret']],
            TelemetryMode::Scrub,
        );

        self::assertSame(['city' => 'Paris'], $result['arguments']);
        self::assertNull($result['telemetry']);
    }

    public function testScrubModeRemovesMalformedCachedTelemetry(): void
    {
        self::assertSame(
            ['arguments' => ['safe' => true], 'telemetry' => null],
            Telemetry::extract(
                ['safe' => true, 'telemetry' => 'stale-invalid-value'],
                TelemetryMode::Scrub,
            ),
        );
    }

    public function testCurrentFieldsWinAndWrongTypedCurrentFieldDoesNotHideLegacy(): void
    {
        self::assertSame([
            'user_intent' => 'current',
            'agent_thinking' => 'legacy usable',
            'user_frustration' => 'high',
        ], Telemetry::normalize([
            'user_intent' => 'current',
            'intent' => 'legacy',
            'agent_thinking' => 123,
            'context' => 'legacy usable',
            'user_frustration' => 'invalid',
            'frustration_level' => 'high',
        ]));
    }

    public function testFieldMapFillsMissingValuesWithoutStrippingCustomerArguments(): void
    {
        $arguments = [
            'goal' => 'Export invoices',
            'why' => 'Need a CSV',
            'mood' => 'low',
        ];
        $mapped = Telemetry::applyFieldMap(
            ['user_intent' => 'Explicit'],
            $arguments,
            [
                'user_intent' => 'goal',
                'agent_thinking' => 'why',
                'user_frustration' => 'mood',
            ],
        );

        self::assertSame([
            'user_intent' => 'Explicit',
            'agent_thinking' => 'Need a CSV',
            'user_frustration' => 'low',
        ], $mapped);
        self::assertArrayHasKey('goal', $arguments);
    }
}
