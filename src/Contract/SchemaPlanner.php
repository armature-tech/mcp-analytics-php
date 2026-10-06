<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\TelemetryMode;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class SchemaPlanner
{
    public const TELEMETRY_PROPERTY_DESCRIPTION = 'Optional task context for usage analytics, based on the visible user request and the action performed by this tool.';
    public const USER_INTENT_DESCRIPTION = 'Generalized one-sentence summary of the task stated in the user\'s latest message. Describe actions and generic roles only. Replace all tool argument values with generic terms, including names, contacts, IDs, credentials, document titles, team names and filters. For example, \'List employees in the selected team.\' Include only on the first tool call after each new user message; omit on later calls in the same turn. Use English.';
    public const CALL_PURPOSE_DESCRIPTION = 'Short public description of the action this tool performs toward the user\'s stated goal. Base it only on the visible request, the tool\'s function and its inputs. Use English. Omit names, contact details, identifiers, credentials and argument values. Generalize document titles, team names and filter values (for example, \'the selected team\').';

    /**
     * @deprecated No longer advertised; the field is neither requested nor exported
     */
    public const USER_FRUSTRATION_DESCRIPTION = 'Frustration expressed in the user\'s latest message: low when none is expressed, medium for explicit dissatisfaction, high for strong or repeated dissatisfaction. Use only the user\'s words. Include on the first tool call after each new user message; omit on later calls in the same turn.';

    /**
     * Description suffix appended by earlier releases. The SDK no longer
     * appends anything; it only removes this exact suffix.
     *
     * @deprecated Kept for source compatibility and old-hint recognition
     */
    public const TELEMETRY_DESCRIPTION_HINT = "\n\nInclude telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.";

    /**
     * Telemetry hint appended by earlier releases, without the leading blank
     * line. Recognized and removed, never appended.
     *
     * @deprecated Kept for source compatibility and old-hint recognition
     */
    public const TELEMETRY_HINT_SENTENCE = 'Include telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.';

    /**
     * request_capability sentence appended by earlier releases after the
     * telemetry hint. Only that combined suffix is removed; customer prose
     * containing this sentence is kept.
     *
     * @deprecated Kept for source compatibility and old-hint recognition
     */
    public const REQUEST_CAPABILITY_HINT_SENTENCE = 'Call request_capability before you tell the user something can\'t be done here or has to be done elsewhere.';

    /**
     * "\n\n" . TELEMETRY_HINT_SENTENCE . ' ' . REQUEST_CAPABILITY_HINT_SENTENCE,
     * spelled out literally.
     *
     * @deprecated Kept for source compatibility and old-hint recognition
     */
    public const TELEMETRY_DESCRIPTION_HINT_WITH_CAPABILITY = "\n\nInclude telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message. Call request_capability before you tell the user something can't be done here or has to be done elsewhere.";
    public const COLLISION_WARNING = '[mcp-analytics] Tool "%s" already declares a top-level "telemetry" input field; leaving the tool untouched and not collecting Armature telemetry for it. Rename the field or configure telemetryFieldMap to export it explicitly.';

    /**
     * @deprecated Never logged: the SDK appends nothing to descriptions
     */
    public const LENGTH_WARNING = '[mcp-analytics] Tool "%s" description is too long to append the Armature telemetry hint without exceeding 1024 characters; leaving it unchanged. Telemetry is still collected.';

    /**
     * @deprecated Never logged: the SDK appends nothing to descriptions
     */
    public const PARTIAL_LENGTH_WARNING = '[mcp-analytics] Tool "%s" description is too long for the full Armature telemetry hint within 1024 characters; appended only the telemetry sentence.';

    /**
     * @deprecated The SDK appends nothing to descriptions, so it guards no length
     */
    public const MAX_TOOL_DESCRIPTION_LENGTH = 1024;

    // Legacy import alias; this description is no longer advertised under that key.
    public const AGENT_THINKING_DESCRIPTION = self::CALL_PURPOSE_DESCRIPTION;

    /**
     * Exact hint paragraphs appended by earlier releases, longest form of
     * each family first. Removed only as a trailing "\n\n"-separated
     * paragraph or as the whole description.
     */
    private const PREVIOUS_HINTS = [
        self::TELEMETRY_HINT_SENTENCE . ' ' . self::REQUEST_CAPABILITY_HINT_SENTENCE,
        self::TELEMETRY_HINT_SENTENCE . ' If no tool can do what the user asks, call request_capability.',
        self::TELEMETRY_HINT_SENTENCE,
        'On every call, pass telemetry.agent_thinking with your reasoning for this specific call. Pass telemetry.user_intent only on the first tool call after a new user message.',
        'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message. If no tool can do what the user asks, call request_capability.',
        'Pass telemetry.agent_thinking on every call, telemetry.user_intent on the first call after each user message.',
        'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request, and telemetry.agent_thinking with your reasoning for making this specific call.',
        'Pass telemetry.user_intent with a one-line restatement of the user\'s most recent request.',
        'Pass telemetry.intent with a one-line user intent for analytics.',
    ];

    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
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
            self::removeLegacyHints($description),
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
            ],
        ];
    }

    /**
     * Remove hint paragraphs that earlier SDK releases appended. Nothing is
     * appended. Only an exact trailing SDK paragraph is removed, repeatedly,
     * so stacked older wrappers come out clean and the result is idempotent.
     * Customer prose that quotes a hint is kept. A description that was only
     * an SDK hint becomes an empty string; null stays null.
     */
    public static function removeLegacyHints(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }

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

        return $description;
    }

    /**
     * @deprecated The SDK no longer appends a hint. This removes hints left
     *             by earlier releases and returns the description otherwise
     *             unchanged (an empty string for null). The remaining
     *             parameters are ignored. Use removeLegacyHints().
     */
    public function appendTelemetryHint(
        ?string $description,
        bool $requestCapabilityEnabled = false,
        ?string $toolName = null,
        string $logLevel = LogLevel::WARNING,
    ): string {
        return self::removeLegacyHints($description) ?? '';
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
