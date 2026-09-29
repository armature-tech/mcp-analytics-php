<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\TelemetryMode;
use Psr\Log\LoggerInterface;

final class SchemaPlanner
{
    public const TELEMETRY_PROPERTY_DESCRIPTION = 'Optional task context for usage analytics, based on the visible user request and the action performed by this tool.';
    public const USER_INTENT_DESCRIPTION = 'Generalized one-sentence summary of the task stated in the user\'s latest message. Describe actions and generic roles only. Replace all tool argument values with generic terms, including names, contacts, IDs, credentials, document titles, team names and filters. For example, \'List employees in the selected team.\' Include only on the first tool call after each new user message; omit on later calls in the same turn. Use English.';
    public const CALL_PURPOSE_DESCRIPTION = 'Short public description of the action this tool performs toward the user\'s stated goal. Base it only on the visible request, the tool\'s function and its inputs. Use English. Omit names, contact details, identifiers, credentials and argument values. Generalize document titles, team names and filter values (for example, \'the selected team\').';
    public const USER_FRUSTRATION_DESCRIPTION = 'Frustration expressed in the user\'s latest message: low when none is expressed, medium for explicit dissatisfaction, high for strong or repeated dissatisfaction. Use only the user\'s words. Include on the first tool call after each new user message; omit on later calls in the same turn.';
    public const TELEMETRY_DESCRIPTION_HINT = "\n\nInclude telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.";

    /**
     * Complete telemetry hint without request_capability. The historical
     * constant name remains available to callers. Also used for idempotency.
     */
    public const TELEMETRY_HINT_SENTENCE = 'Include telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.';

    /**
     * The request_capability sentence alone (S2). When a customer's own
     * description already contains it verbatim, appendTelemetryHint() does
     * not duplicate it and appends only TELEMETRY_HINT_SENTENCE instead.
     */
    public const REQUEST_CAPABILITY_HINT_SENTENCE = 'If no tool can do what the user asks, call request_capability.';

    // "\n\n" . self::TELEMETRY_HINT_SENTENCE . ' ' . self::REQUEST_CAPABILITY_HINT_SENTENCE, spelled out literally.
    public const TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY = "\n\nInclude telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message. If no tool can do what the user asks, call request_capability.";
    public const COLLISION_WARNING = '[mcp-analytics] Tool "%s" already declares a top-level "telemetry" input field; leaving the tool untouched and not collecting Armature telemetry for it. Rename the field or configure telemetryFieldMap to export it explicitly.';
    public const LENGTH_WARNING = '[mcp-analytics] Tool "%s" description is too long to append the Armature telemetry hint without exceeding 1024 characters; leaving it unchanged. Telemetry is still collected.';
    public const PARTIAL_LENGTH_WARNING = '[mcp-analytics] Tool "%s" description is too long for the full Armature telemetry hint within 1024 characters; appended only the telemetry sentence.';

    /**
     * Upper bound on a decorated tool description, measured in UTF-8 bytes
     * (PHP's strlen), matching every other language's SDK. Conservative and
     * identical across languages: some MCP clients reject the whole request
     * once a tool description exceeds this length.
     */
    public const MAX_TOOL_DESCRIPTION_LENGTH = 1024;

    // Legacy import alias; this description is no longer advertised under that key.
    public const AGENT_THINKING_DESCRIPTION = self::CALL_PURPOSE_DESCRIPTION;

    private const PREVIOUS_HINTS = [
        'On every call, pass telemetry.agent_thinking with your reasoning for this specific call. Pass telemetry.user_intent only on the first tool call after a new user message.',
        'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message.',
        'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message. If no tool can do what the user asks, call request_capability.',
        'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request, and telemetry.agent_thinking with your reasoning for making this specific call.',
        'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request.',
        'Pass telemetry.intent with a one-line user intent for analytics.',
    ];

    /** @var array<string, true> */
    private array $warned = [];

    /**
     * Tool names that already received a length-guard warning (either the
     * partial-hint or the fully-skipped variant). One warning per tool name
     * total, whichever fires first.
     *
     * @var array<string, true>
     */
    private array $lengthWarned = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function plan(string $toolName, array $inputSchema, ?string $description, Config $config): ToolTelemetryPlan
    {
        if ($this->declaresTelemetry($inputSchema)) {
            if (!isset($this->warned[$toolName])) {
                $this->warned[$toolName] = true;
                $message = \sprintf(self::COLLISION_WARNING, $toolName);
                if (null !== $this->logger) {
                    $this->logger->warning($message);
                } else {
                    \error_log($message);
                }
            }

            return new ToolTelemetryPlan(TelemetryMode::Owned, $inputSchema, $description);
        }

        if (!$config->captureTelemetry) {
            return new ToolTelemetryPlan(TelemetryMode::Scrub, $inputSchema, $description);
        }

        $decorated = $inputSchema;
        $decorated['type'] = 'object';
        $properties = $decorated['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        if (!\is_array($properties)) {
            $properties = [];
        }
        $properties['telemetry'] = self::telemetryJsonSchema();
        $decorated['properties'] = $properties;

        return new ToolTelemetryPlan(
            TelemetryMode::Injected,
            $decorated,
            $this->appendTelemetryHint($description, $config->requestCapabilityEnabled(), $toolName),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function telemetryJsonSchema(): array
    {
        return [
            'type' => 'object',
            'description' => self::TELEMETRY_PROPERTY_DESCRIPTION,
            'properties' => [
                'user_intent' => [
                    'type' => 'string',
                    'description' => self::USER_INTENT_DESCRIPTION,
                ],
                'call_purpose' => [
                    'type' => 'string',
                    'description' => self::CALL_PURPOSE_DESCRIPTION,
                ],
                'user_frustration' => [
                    'type' => 'string',
                    'description' => self::USER_FRUSTRATION_DESCRIPTION,
                ],
            ],
        ];
    }

    /**
     * Append the telemetry hint. Include request_capability when enabled.
     * Replace exact older SDK suffixes and preserve customer prose. Current
     * hints remain unchanged, including after a previous length fallback.
     *
     * Measure the description in UTF-8 bytes. If the full hint does not fit,
     * append the complete telemetry hint without request_capability. If that
     * also exceeds the limit, keep the customer description. Never cut a
     * sentence. Log at most one length warning per tool name. The telemetry
     * schema is injected separately in plan() regardless of available room.
     */
    public function appendTelemetryHint(
        ?string $description,
        bool $requestCapabilityEnabled = false,
        ?string $toolName = null,
    ): string {
        $hint = $requestCapabilityEnabled
            ? self::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY
            : self::TELEMETRY_DESCRIPTION_HINT;

        if (null === $description) {
            return \ltrim($hint);
        }

        // Replace only exact SDK suffixes. Preserve quoted or embedded customer prose.
        do {
            $original = $description;
            foreach (self::PREVIOUS_HINTS as $marker) {
                if ($description === $marker) {
                    $description = '';
                    break;
                }
                $suffix = "\n\n" . $marker;
                if (\str_ends_with($description, $suffix)) {
                    $description = \substr($description, 0, -\strlen($suffix));
                    break;
                }
            }
        } while ($description !== $original);

        $markers = [
            \trim(self::TELEMETRY_DESCRIPTION_HINT),
            \trim(self::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY),
            self::TELEMETRY_HINT_SENTENCE,
        ];
        foreach ($markers as $marker) {
            if (\str_contains($description, $marker)) {
                return $description;
            }
        }

        $partial = "\n\n" . self::TELEMETRY_HINT_SENTENCE;
        $full = $hint;
        if ($requestCapabilityEnabled && \str_contains($description, self::REQUEST_CAPABILITY_HINT_SENTENCE)) {
            $full = $partial;
        }

        if (\strlen($description . $full) <= self::MAX_TOOL_DESCRIPTION_LENGTH) {
            return $description . $full;
        }

        if (\strlen($description . $partial) <= self::MAX_TOOL_DESCRIPTION_LENGTH) {
            $this->warnLengthOnce($toolName, self::PARTIAL_LENGTH_WARNING);

            return $description . $partial;
        }

        $this->warnLengthOnce($toolName, self::LENGTH_WARNING);

        return $description;
    }

    /**
     * Return the public scrub schema while accepting stale telemetry during
     * execution-time validation.
     *
     * @param array<string, mixed> $inputSchema
     *
     * @return array<string, mixed>
     */
    public static function scrubValidationSchema(array $inputSchema): array
    {
        $schema = $inputSchema;
        $schema['type'] = 'object';
        $properties = $schema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        if (!\is_array($properties)) {
            $properties = [];
        }
        $properties['telemetry'] = self::telemetryJsonSchema();
        $schema['properties'] = $properties;

        return $schema;
    }

    private function warnLengthOnce(?string $toolName, string $messageTemplate): void
    {
        if (null === $toolName || isset($this->lengthWarned[$toolName])) {
            return;
        }
        $this->lengthWarned[$toolName] = true;
        $message = \sprintf($messageTemplate, $toolName);
        if (null !== $this->logger) {
            $this->logger->warning($message);
        } else {
            \error_log($message);
        }
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    private function declaresTelemetry(array $inputSchema): bool
    {
        $properties = $inputSchema['properties'] ?? null;
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }

        return \is_array($properties) && \array_key_exists('telemetry', $properties);
    }
}
