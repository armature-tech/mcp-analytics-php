<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Contract\SchemaPlanner;
use Armature\McpAnalytics\TelemetryMode;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SchemaPlannerTest extends TestCase
{
    public function testInjectedModeCopiesSchemaAndAppendsHint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];
        $planner = new SchemaPlanner();
        $plan = $planner->plan('weather', $schema, 'Weather lookup', new Config());

        self::assertSame(TelemetryMode::Injected, $plan->mode);
        self::assertSame($schema, [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);
        self::assertArrayHasKey('telemetry', $plan->inputSchema['properties']);
        self::assertSame(['city'], $plan->inputSchema['required']);
        self::assertIsString($plan->description);
        self::assertStringContainsString('telemetry.call_purpose', $plan->description);
    }

    public function testAdvertisedFieldsArePublicAndOptional(): void
    {
        $schema = SchemaPlanner::telemetryJsonSchema();
        self::assertSame(['user_intent', 'call_purpose', 'user_frustration'], \array_keys($schema['properties']));
        self::assertArrayNotHasKey('required', $schema);
        self::assertArrayNotHasKey('enum', $schema['properties']['user_frustration']);
        self::assertStringNotContainsString('reasoning', \json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function testOldSdkSuffixIsReplacedAndCustomerProseIsPreserved(): void
    {
        $planner = new SchemaPlanner();
        $old = 'On every call, pass telemetry.agent_thinking with your reasoning for this specific call. Pass telemetry.user_intent only on the first tool call after a new user message.';
        self::assertSame('Find records.' . SchemaPlanner::TELEMETRY_DESCRIPTION_HINT, $planner->appendTelemetryHint("Find records.\n\n" . $old));
        $quoted = 'Documentation quotes: "' . $old . '". Keep this text.';
        self::assertStringStartsWith($quoted, $planner->appendTelemetryHint($quoted));
        $long = \str_repeat('é', 500);
        self::assertSame($long, $planner->appendTelemetryHint($long . "\n\n" . $old));
    }

    public function testEarlierRequestCapabilitySentenceIsUpgraded(): void
    {
        $planner = new SchemaPlanner();
        $earlier = SchemaPlanner::TELEMETRY_DESCRIPTION_HINT . ' If no tool can do what the user asks, call request_capability.';
        self::assertSame(
            'Find records.' . SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY,
            $planner->appendTelemetryHint('Find records.' . $earlier, true),
        );
    }

    public function testOwnedModeLeavesCustomerContractUntouchedAndWarnsOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static function (mixed $message): bool {
                self::assertSame(
                    '[mcp-analytics] Tool "owned" already declares a top-level "telemetry" input field; leaving the tool untouched and not collecting Armature telemetry for it. Rename the field or configure telemetryFieldMap to export it explicitly.',
                    (string) $message,
                );

                return true;
            }));
        $schema = [
            'type' => 'object',
            'properties' => ['telemetry' => ['type' => 'string']],
        ];
        $planner = new SchemaPlanner($logger);

        $first = $planner->plan('owned', $schema, 'Original', new Config());
        $second = $planner->plan('owned', $schema, 'Original', new Config());

        self::assertSame(TelemetryMode::Owned, $first->mode);
        self::assertSame($schema, $first->inputSchema);
        self::assertSame('Original', $first->description);
        self::assertSame(TelemetryMode::Owned, $second->mode);
    }

    public function testScrubModeHasSeparatePublicAndValidationSchemas(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $planner = new SchemaPlanner();
        $plan = $planner->plan(
            'weather',
            $schema,
            'Original',
            new Config(captureTelemetry: false),
        );

        self::assertSame(TelemetryMode::Scrub, $plan->mode);
        self::assertSame($schema, $plan->inputSchema);
        self::assertSame('Original', $plan->description);
        self::assertArrayNotHasKey('telemetry', $plan->inputSchema['properties']);

        $validation = SchemaPlanner::scrubValidationSchema($schema);
        self::assertArrayHasKey('telemetry', $validation['properties']);
        self::assertFalse($validation['additionalProperties']);
    }

    public function testDescriptionHintIsIdempotent(): void
    {
        $planner = new SchemaPlanner();
        $once = $planner->appendTelemetryHint('Lookup');

        self::assertSame($once, $planner->appendTelemetryHint($once));
    }

    public function testRequestCapabilityEnabledUsesCapabilityHint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
        ];
        $planner = new SchemaPlanner();
        // apiKey alone gives the config a delivery path, and requestCapability
        // defaults to null, so Config::requestCapabilityEnabled() is true.
        $plan = $planner->plan('weather', $schema, 'Weather lookup', new Config(apiKey: 'test-key'));

        self::assertIsString($plan->description);
        self::assertStringContainsString(
            SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY,
            $plan->description,
        );
        self::assertStringContainsString('Call request_capability', $plan->description);
        self::assertStringNotContainsString(
            'only on the first tool call after a new user message',
            $plan->description,
        );
    }

    public function testRequestCapabilityExplicitlyDisabledKeepsCurrentHint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
        ];
        $planner = new SchemaPlanner();
        $plan = $planner->plan(
            'weather',
            $schema,
            'Weather lookup',
            new Config(apiKey: 'test-key', requestCapability: false),
        );

        self::assertIsString($plan->description);
        self::assertSame(
            'Weather lookup' . SchemaPlanner::TELEMETRY_DESCRIPTION_HINT,
            $plan->description,
        );
        self::assertStringNotContainsString('request_capability', $plan->description);
    }

    public function testCapabilityHintAppendIsIdempotent(): void
    {
        $planner = new SchemaPlanner();
        $once = $planner->appendTelemetryHint('Lookup', true);

        self::assertStringContainsString('Call request_capability', $once);
        self::assertSame($once, $planner->appendTelemetryHint($once, true));
    }

    public function testCapabilityHintDoesNotReplaceAnAlreadyPresentCurrentHint(): void
    {
        $planner = new SchemaPlanner();
        $withCurrentHint = $planner->appendTelemetryHint('Lookup', false);

        // A description already carrying the current hint is
        // returned unchanged, even if request_capability is now enabled.
        self::assertSame($withCurrentHint, $planner->appendTelemetryHint($withCurrentHint, true));
    }

    /**
     * Length guard: exact full/fallback boundaries use the current hint size.
     * UTF-8 descriptions use byte counts rather than character counts.
     */
    public function testLengthGuardBoundariesForBothHintVariants(): void
    {
        $partialHint = "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE;

        foreach (self::lengthGuardBoundaryCases() as $label => [$requestCapabilityEnabled, $descriptionLength, $expectedOutcome]) {
            $planner = new SchemaPlanner();
            $description = \str_repeat('a', $descriptionLength);
            $fullHint = $requestCapabilityEnabled
                ? SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY
                : SchemaPlanner::TELEMETRY_DESCRIPTION_HINT;

            $result = $planner->appendTelemetryHint($description, $requestCapabilityEnabled);

            $expected = $description;
            if ('full' === $expectedOutcome) {
                $expected = $description . $fullHint;
            } elseif ('partial' === $expectedOutcome) {
                $expected = $description . $partialHint;
            }
            self::assertSame($expected, $result, $label);
            self::assertLessThanOrEqual(SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH, \strlen($result), $label);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: int, 2: string}>
     */
    private static function lengthGuardBoundaryCases(): array
    {
        $limit = SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH;
        $current = $limit - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT);
        $capability = $limit - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY);
        $partial = $limit - \strlen("\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE);

        return [
            'disabled: full boundary' => [false, $current, 'full'],
            'disabled: one byte over full' => [false, $current + 1, 'none'],
            'disabled: partial boundary' => [false, $partial, 'full'],
            'disabled: one byte over partial' => [false, $partial + 1, 'none'],
            'enabled: full boundary' => [true, $capability, 'full'],
            'enabled: one byte over full' => [true, $capability + 1, 'partial'],
            'enabled: partial boundary' => [true, $partial, 'partial'],
            'enabled: one byte over partial' => [true, $partial + 1, 'none'],
        ];
    }

    public function testPartialHintAppendIsIdempotentOnReapplication(): void
    {
        $planner = new SchemaPlanner();
        // One byte past the current hint's full-fit boundary: lands in the
        // step-5 partial-hint branch.
        $description = \str_repeat('a', SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY) + 1);

        $once = $planner->appendTelemetryHint($description, true);
        $twice = $planner->appendTelemetryHint($once, true);

        self::assertSame($description . "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE, $once);
        self::assertSame($once, $twice);
    }

    public function testDescriptionLengthNoticeFollowsTheConfiguredLevel(): void
    {
        $description = \str_repeat('a', SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY) + 1);
        $message = '[mcp-analytics] Tool "long" description is too long for the full Armature telemetry hint within 1024 characters; appended only the telemetry sentence.';
        foreach (['debug', 'info'] as $level) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method($level)->with($message);
            $logger->expects(self::never())->method('warning');
            $planner = new SchemaPlanner($logger);
            $planner->appendTelemetryHint($description, true, 'long', $level);
            $planner->appendTelemetryHint($description, true, 'long', $level);
        }

        $silent = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'log'] as $method) {
            $silent->expects(self::never())->method($method);
        }
        $planner = new SchemaPlanner($silent);
        self::assertSame(
            $description . "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE,
            $planner->appendTelemetryHint($description, true, 'long', 'none'),
        );
        self::assertSame(\str_repeat('b', 1000), $planner->appendTelemetryHint(\str_repeat('b', 1000), true, 'verbose', 'none'));
    }

    public function testPlanUsesTheConfiguredDescriptionLengthLogLevel(): void
    {
        $schema = ['type' => 'object', 'properties' => []];
        $long = \str_repeat('a', 1000);
        foreach (['debug', 'info', 'warning'] as $level) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method($level)->with(self::stringContains('"lookup"'));
            (new SchemaPlanner($logger))->plan('lookup', $schema, $long, new Config(descriptionLengthLogLevel: $level));
        }

        $silent = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'log'] as $method) {
            $silent->expects(self::never())->method($method);
        }
        $plan = (new SchemaPlanner($silent))->plan('lookup', $schema, $long, new Config(descriptionLengthLogLevel: 'none'));
        self::assertSame($long, $plan->description);
    }

    public function testFullHintOneByteOverBoundaryWarnsPartialOncePerTool(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static function (mixed $message): bool {
                self::assertSame(
                    '[mcp-analytics] Tool "long" description is too long for the full Armature telemetry hint within 1024 characters; appended only the telemetry sentence.',
                    (string) $message,
                );

                return true;
            }));
        $planner = new SchemaPlanner($logger);
        // One byte past the capability hint's boundary uses the telemetry fallback.
        $description = \str_repeat('a', SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY) + 1);
        $partialHint = "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE;
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];

        $first = $planner->plan('long', $schema, $description, new Config(apiKey: 'test-key'));
        $second = $planner->plan('long', $schema, $description, new Config(apiKey: 'test-key'));

        // Idempotent: the second call's description already carries the
        // partial hint's TELEMETRY_HINT_SENTENCE marker, so it is returned
        // unchanged rather than appended again or re-warned.
        self::assertSame($description . $partialHint, $first->description);
        self::assertSame($first->description, $second->description);
        self::assertArrayHasKey('telemetry', $first->inputSchema['properties']);
        self::assertArrayHasKey('telemetry', $second->inputSchema['properties']);
    }

    public function testLengthGuardCountsUtf8BytesNotCharacters(): void
    {
        $planner = new SchemaPlanner();
        // 'é' is 2 bytes in UTF-8. Build a description whose byte length
        // alone crosses the current hint's full-fit budget (853 bytes) by 1
        // while its character count stays far below 1024, proving the guard
        // measures strlen (bytes), not mb_strlen (code points). It still
        // fits the partial-hint budget (913 bytes), so the telemetry
        // sentence alone is appended.
        $budget = SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH - \strlen(SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY);
        $description = \str_repeat('é', (int) \floor($budget / 2) + 1);
        self::assertGreaterThan($budget, \strlen($description));
        self::assertLessThan(500, \mb_strlen($description));

        $result = $planner->appendTelemetryHint($description, true);

        self::assertSame($description . "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE, $result);
    }

    public function testCapabilityHintOmitsAlreadyPresentRequestCapabilitySentence(): void
    {
        $planner = new SchemaPlanner();
        $description = 'Weather lookup. ' . SchemaPlanner::REQUEST_CAPABILITY_HINT_SENTENCE;

        $result = $planner->appendTelemetryHint($description, true);

        // Only the telemetry sentence is appended; the customer's own
        // request_capability sentence is not duplicated.
        self::assertSame($description . "\n\n" . SchemaPlanner::TELEMETRY_HINT_SENTENCE, $result);
        self::assertSame(
            1,
            \substr_count($result, SchemaPlanner::REQUEST_CAPABILITY_HINT_SENTENCE),
        );
    }

    public function testCapabilityHintWithPresentRequestCapabilitySentenceStillRespectsLengthGuard(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static function (mixed $message): bool {
                self::assertSame(
                    '[mcp-analytics] Tool "custom" description is too long to append the Armature telemetry hint without exceeding 1024 characters; leaving it unchanged. Telemetry is still collected.',
                    (string) $message,
                );

                return true;
            }));
        $planner = new SchemaPlanner($logger);
        // Customer description already mentions request_capability, but is
        // still long enough that even the reduced (S1-only) hint (111
        // bytes) would push it past 1024 bytes.
        $description = \str_repeat('a', 914) . ' ' . SchemaPlanner::REQUEST_CAPABILITY_HINT_SENTENCE;
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];

        $plan = $planner->plan('custom', $schema, $description, new Config(apiKey: 'test-key'));

        self::assertSame($description, $plan->description);
        self::assertArrayHasKey('telemetry', $plan->inputSchema['properties']);
    }

    public function testTooLongDescriptionWarnsOncePerToolNameAndSchemaStaysDecorated(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static function (mixed $message): bool {
                self::assertSame(
                    '[mcp-analytics] Tool "verbose" description is too long to append the Armature telemetry hint without exceeding 1024 characters; leaving it unchanged. Telemetry is still collected.',
                    (string) $message,
                );

                return true;
            }));
        $planner = new SchemaPlanner($logger);
        $description = \str_repeat('a', SchemaPlanner::MAX_TOOL_DESCRIPTION_LENGTH);
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];

        $first = $planner->plan('verbose', $schema, $description, new Config());
        $second = $planner->plan('verbose', $schema, $description, new Config());

        self::assertSame(TelemetryMode::Injected, $first->mode);
        self::assertSame($description, $first->description);
        self::assertArrayHasKey('telemetry', $first->inputSchema['properties']);
        self::assertSame(TelemetryMode::Injected, $second->mode);
        self::assertSame($description, $second->description);
        self::assertArrayHasKey('telemetry', $second->inputSchema['properties']);
    }
}
