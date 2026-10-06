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
    private const OLD_TELEMETRY_HINT = 'Include telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.';
    private const OLD_CAPABILITY_SENTENCE = 'Call request_capability before you tell the user something can\'t be done here or has to be done elsewhere.';

    public function testInjectedModeCopiesSchemaAndLeavesDescriptionUnchanged(): void
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
        self::assertSame('Weather lookup', $plan->description);
    }

    public function testNoDescriptionIsModifiedWithOrWithoutSendFeedback(): void
    {
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];
        $long = \str_repeat('a', 2_000);
        foreach ([
            'capture on, no delivery' => new Config(),
            'send_feedback on by default' => new Config(apiKey: 'test-key'),
            'send_feedback off' => new Config(apiKey: 'test-key', sendFeedback: false),
            'send_feedback explicitly on' => new Config(apiKey: 'test-key', sendFeedback: true),
        ] as $label => $config) {
            $planner = new SchemaPlanner();
            foreach (['Weather lookup', '', $long, 'Ends with a blank paragraph.' . "\n\n"] as $description) {
                $plan = $planner->plan('weather', $schema, $description, $config);
                self::assertSame(TelemetryMode::Injected, $plan->mode, $label);
                self::assertSame($description, $plan->description, $label);
            }
            $plan = $planner->plan('no_description', $schema, null, $config);
            self::assertNull($plan->description, $label);
            self::assertArrayHasKey('telemetry', $plan->inputSchema['properties'], $label);
        }
    }

    public function testAdvertisedTelemetrySchemaHasExactlyUserIntentAndCallPurpose(): void
    {
        $schema = SchemaPlanner::telemetryJsonSchema();
        self::assertSame('object', $schema['type']);
        self::assertSame(SchemaPlanner::TELEMETRY_PROPERTY_DESCRIPTION, $schema['description']);
        self::assertSame(
            'Optional task context for usage analytics, based on the visible user request and the action performed by this tool.',
            $schema['description'],
        );
        self::assertSame(['user_intent', 'call_purpose'], \array_keys($schema['properties']));
        self::assertSame(
            ['type' => 'string', 'description' => SchemaPlanner::USER_INTENT_DESCRIPTION],
            $schema['properties']['user_intent'],
        );
        self::assertSame(
            ['type' => 'string', 'description' => SchemaPlanner::CALL_PURPOSE_DESCRIPTION],
            $schema['properties']['call_purpose'],
        );
        self::assertArrayNotHasKey('required', $schema);
        $encoded = \json_encode($schema, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('frustration', $encoded);
        self::assertStringNotContainsString('reasoning', $encoded);

        $plan = (new SchemaPlanner())->plan('weather', ['type' => 'object', 'properties' => []], 'Weather', new Config());
        self::assertSame($schema, $plan->inputSchema['properties']['telemetry']);
        self::assertSame($schema, SchemaPlanner::scrubValidationSchema([])['properties']['telemetry']);
    }

    public function testOldSdkHintSuffixesAreRemoved(): void
    {
        $planner = new SchemaPlanner();
        $schema = ['type' => 'object', 'properties' => []];
        $config = new Config(apiKey: 'test-key', sendFeedback: true);
        $old = [
            self::OLD_TELEMETRY_HINT . ' ' . self::OLD_CAPABILITY_SENTENCE,
            self::OLD_TELEMETRY_HINT,
            self::OLD_TELEMETRY_HINT . ' If no tool can do what the user asks, call request_capability.',
            'On every call, pass telemetry.agent_thinking with your reasoning for this specific call. Pass telemetry.user_intent only on the first tool call after a new user message.',
            'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message. If no tool can do what the user asks, call request_capability.',
            'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message.',
            'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request, and telemetry.agent_thinking with your reasoning for making this specific call.',
            'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request.',
            'Pass telemetry.intent with a one-line user intent for analytics.',
        ];
        foreach ($old as $hint) {
            self::assertSame('Find records.', $planner->plan('t', $schema, "Find records.\n\n" . $hint, $config)->description, $hint);
            self::assertSame('', $planner->plan('t', $schema, $hint, $config)->description, $hint);
            self::assertSame('Find records.', SchemaPlanner::removeLegacyHints("Find records.\n\n" . $hint));
        }

        // The deprecated constants spell out the same suffixes.
        self::assertSame('Lookup', SchemaPlanner::removeLegacyHints('Lookup' . SchemaPlanner::TELEMETRY_DESCRIPTION_HINT));
        self::assertSame('Lookup', SchemaPlanner::removeLegacyHints('Lookup' . SchemaPlanner::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY));

        // Stacked wrappers from several releases all come off.
        $stacked = "Lookup\n\n" . $old[3] . "\n\n" . $old[1];
        self::assertSame('Lookup', $planner->plan('t', $schema, $stacked, $config)->description);

        // An old wrapper that kept a customer's own capability sentence and
        // appended only the telemetry hint.
        $customer = 'Lookup. ' . self::OLD_CAPABILITY_SENTENCE;
        self::assertSame($customer, SchemaPlanner::removeLegacyHints($customer . "\n\n" . self::OLD_TELEMETRY_HINT));
    }

    public function testRemovalIsIdempotentAndPreservesCustomerProse(): void
    {
        $once = SchemaPlanner::removeLegacyHints("Lookup\n\n" . self::OLD_TELEMETRY_HINT);
        self::assertSame('Lookup', $once);
        self::assertSame($once, SchemaPlanner::removeLegacyHints($once));

        $quoted = 'Documentation quotes: "' . self::OLD_TELEMETRY_HINT . '". Keep this text.';
        self::assertSame($quoted, SchemaPlanner::removeLegacyHints($quoted));
        $inline = 'Lookup. ' . self::OLD_TELEMETRY_HINT;
        self::assertSame($inline, SchemaPlanner::removeLegacyHints($inline));
        $capabilityOnly = "Lookup.\n\n" . self::OLD_CAPABILITY_SENTENCE;
        self::assertSame($capabilityOnly, SchemaPlanner::removeLegacyHints($capabilityOnly));
        self::assertNull(SchemaPlanner::removeLegacyHints(null));
    }

    public function testDeprecatedAppendTelemetryHintAppendsNothing(): void
    {
        $planner = new SchemaPlanner();
        self::assertSame('Lookup', $planner->appendTelemetryHint('Lookup'));
        self::assertSame('Lookup', $planner->appendTelemetryHint('Lookup', true, 'tool', 'warning'));
        self::assertSame('Lookup', $planner->appendTelemetryHint("Lookup\n\n" . self::OLD_TELEMETRY_HINT, true));
        self::assertSame('', $planner->appendTelemetryHint(null, true));
    }

    public function testDescriptionLengthNoticeIsNeverLogged(): void
    {
        $silent = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'notice', 'warning', 'log'] as $method) {
            $silent->expects(self::never())->method($method);
        }
        $schema = ['type' => 'object', 'properties' => []];
        $long = \str_repeat('é', 1_000);
        foreach (['none', 'debug', 'info', 'warning'] as $level) {
            $plan = (new SchemaPlanner($silent))->plan('lookup', $schema, $long, new Config(descriptionLengthLogLevel: $level));
            self::assertSame($long, $plan->description);
        }
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
}
