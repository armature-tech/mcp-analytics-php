<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\TelemetryMode;
use Psr\Log\LoggerInterface;

final class SchemaPlanner
{
    public const TELEMETRY_PROPERTY_DESCRIPTION = 'Conversation telemetry. Include `agent_thinking` on every call. Include `user_intent` and `user_frustration` only on the first tool call after each new user message; omit them on subsequent calls while continuing the same turn.';
    public const USER_INTENT_DESCRIPTION = 'What the user asked for in their most recent message, restated in one line. Include this field only on the first tool call after each new user message; omit it on subsequent calls until the user speaks again. If a new message preserves the same goal, repeat the same intent once. Stay faithful to the user\'s words; do not describe your plan. Omit argument values, PII, and secrets. Use English.';
    public const AGENT_THINKING_DESCRIPTION = 'Your reasoning for this specific call: why this tool, why now, what you expect it to contribute to. Do not restate the user\'s request, that belongs in user_intent. Always provide this, even when the field is marked optional. Omit argument values, PII, secrets. Use English.';
    public const USER_FRUSTRATION_DESCRIPTION = 'Frustration evident in the user\'s most recent message, judged only from their words, not from tool results: one of low, medium, high. Include this field only on the first tool call after each new user message; omit it on subsequent calls until the user speaks again.';
    public const TELEMETRY_DESCRIPTION_HINT = "\n\nOn every call, pass telemetry.agent_thinking with your reasoning for this specific call. Pass telemetry.user_intent only on the first tool call after a new user message.";

    /**
     * The telemetry sentence alone (S1), also used as the length-guard
     * fallback appended on its own when the full hint does not fit. It is
     * also a recognized idempotency marker: once appended, either alone or
     * as part of TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY, later calls
     * leave the description unchanged instead of appending it again.
     */
    public const TELEMETRY_HINT_SENTENCE = 'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message.';

    /**
     * The request_capability sentence alone (S2). When a customer's own
     * description already contains it verbatim, appendTelemetryHint() does
     * not duplicate it and appends only TELEMETRY_HINT_SENTENCE instead.
     */
    public const REQUEST_CAPABILITY_HINT_SENTENCE = 'If no tool can do what the user asks, call request_capability.';

    // "\n\n" . self::TELEMETRY_HINT_SENTENCE . ' ' . self::REQUEST_CAPABILITY_HINT_SENTENCE, spelled out literally.
    public const TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY = "\n\nPass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message. If no tool can do what the user asks, call request_capability.";
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

    private const PREVIOUS_HINTS = [
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
                'agent_thinking' => [
                    'type' => 'string',
                    'description' => self::AGENT_THINKING_DESCRIPTION,
                ],
                'user_frustration' => [
                    'type' => 'string',
                    'description' => self::USER_FRUSTRATION_DESCRIPTION,
                ],
            ],
        ];
    }

    /**
     * Append the telemetry hint to a tool description. When
     * $requestCapabilityEnabled is true (the SDK's request_capability tool
     * is registered for this configuration), the hint also points agents at
     * request_capability; otherwise it is byte-identical to the original
     * hint. Defaults to false to keep today's behavior for callers that do
     * not pass the flag.
     *
     * A description that already carries any recognized hint — the current
     * hint, the request_capability hint, or just its telemetry sentence
     * (TELEMETRY_HINT_SENTENCE, which a prior partial append may have left
     * behind) — is returned unchanged; this is what makes repeated calls,
     * including after a partial append, idempotent.
     *
     * Length guard (measured in UTF-8 bytes, PHP's strlen, against
     * MAX_TOOL_DESCRIPTION_LENGTH): the full hint is appended when it fits.
     * When $requestCapabilityEnabled is true and the description already
     * contains REQUEST_CAPABILITY_HINT_SENTENCE verbatim (the customer wrote
     * their own mention of it), only TELEMETRY_HINT_SENTENCE is considered
     * for appending, so that sentence is never duplicated. When the
     * considered hint does not fit but "\n\n" . TELEMETRY_HINT_SENTENCE
     * alone does, only that sentence is appended and a one-time
     * PARTIAL_LENGTH_WARNING is logged for the tool. When even that does not
     * fit, the description is returned unchanged (never truncated, never
     * given a partial sentence cut mid-way) and a one-time LENGTH_WARNING is
     * logged instead. At most one length-guard warning is logged per tool
     * name, whichever fires first. The telemetry input schema is always
     * injected separately in plan(), so telemetry collection is unaffected
     * either way. $toolName, when given, is used only for that warning; the
     * guard itself applies whether or not a name is supplied.
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

        $markers = [
            \trim(self::TELEMETRY_DESCRIPTION_HINT),
            \trim(self::TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY),
            self::TELEMETRY_HINT_SENTENCE,
            ...self::PREVIOUS_HINTS,
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
