<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Contract\Telemetry;
use Armature\McpAnalytics\TelemetryMode;
use PHPUnit\Framework\TestCase;

final class TelemetryTest extends TestCase
{
    public function testCallPurposePrecedesCachedAliasesIncludingEmptyString(): void
    {
        foreach (['Action summary', '', 123] as $value) {
            $result = Telemetry::extract([
                'query' => 'x',
                'telemetry' => ['call_purpose' => $value, 'agent_thinking' => 'old', 'context' => 'older'],
            ]);
            self::assertSame(['query' => 'x'], $result['arguments']);
            self::assertSame(['agent_thinking' => \is_string($value) ? $value : 'old'], $result['telemetry']);
        }
    }

    public function testCallPurposeFieldMapKeepsExplicitValuesAndCustomerArguments(): void
    {
        $arguments = ['action' => 'Retrieve records', 'old' => 'Legacy value'];
        $map = ['call_purpose' => 'action', 'agent_thinking' => 'old'];
        self::assertSame(['agent_thinking' => 'Retrieve records'], Telemetry::applyFieldMap(null, $arguments, $map));
        self::assertSame(['call_purpose' => ''], Telemetry::applyFieldMap(['call_purpose' => ''], $arguments, $map));
        self::assertSame('Retrieve records', $arguments['action']);
    }

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
        ], $mapped);
        self::assertArrayHasKey('goal', $arguments);
        self::assertSame('low', $arguments['mood']);
    }

    public function testCachedFrustrationFieldsAreStrippedAndNeverExported(): void
    {
        foreach (['user_frustration', 'frustration_level'] as $field) {
            $result = Telemetry::extract([
                'q' => 'x',
                'telemetry' => ['user_intent' => 'find x', $field => 'high'],
            ]);
            self::assertSame(['q' => 'x'], $result['arguments'], $field);
            self::assertSame(['user_intent' => 'find x'], $result['telemetry'], $field);

            $scrubbed = Telemetry::extract(['q' => 'x', 'telemetry' => [$field => 'high']], TelemetryMode::Scrub);
            self::assertSame(['arguments' => ['q' => 'x'], 'telemetry' => null], $scrubbed, $field);
        }
        self::assertSame([], Telemetry::normalize(['user_frustration' => 'low', 'frustration_level' => 'high']));
        self::assertSame(
            ['call_purpose' => 'x'],
            Telemetry::withoutRetiredFields(['call_purpose' => 'x', 'user_frustration' => 'low', 'frustration_level' => 'high']),
        );
        self::assertNull(Telemetry::applyFieldMap(null, ['mood' => 'high'], ['user_frustration' => 'mood']));
    }
}
