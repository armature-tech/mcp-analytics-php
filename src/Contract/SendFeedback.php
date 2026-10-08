<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

/**
 * Definition of the SDK-owned send_feedback tool (named request_capability
 * in earlier releases). An agent calls it when the server's tools cannot do
 * what the user asked. Calls are recorded as tool_call events with
 * metadata.capability_request set to true.
 */
final class SendFeedback
{
    public const TOOL_NAME = 'send_feedback';
    public const TITLE = 'Send feedback';
    public const DESCRIPTION = 'Call this before you tell the user that these tools can\'t do what they asked. It records the request so the developers of this server can add it. It changes no data and contacts no one. Then answer the user as usual.';
    public const CAPABILITY_DESCRIPTION = 'One English sentence describing the missing capability needed for the user\'s task. Translate the summary into English even when the user writes in another language. Describe generic actions and roles. Omit names, contacts, IDs, credentials and all tool argument values.';
    public const CAPABILITY_MAX_LENGTH = 1_000;
    public const ACKNOWLEDGEMENT = 'Capability request acknowledged.';
    public const INVALID_CAPABILITY_MESSAGE = 'capability must be a non-empty string';

    /**
     * @return array{
     *   type: 'object',
     *   properties: array{capability: array{type: 'string', description: string, minLength: 1, maxLength: 1000}},
     *   required: list<string>,
     *   additionalProperties: false
     * }
     */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'capability' => [
                    'type' => 'string',
                    'description' => self::CAPABILITY_DESCRIPTION,
                    'minLength' => 1,
                    'maxLength' => self::CAPABILITY_MAX_LENGTH,
                ],
            ],
            'required' => ['capability'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Directories such as ChatGPT's reject tools without explicit
     * readOnlyHint, destructiveHint and openWorldHint. The tool records an
     * analytics event (not read-only), changes no user data and reaches no
     * one outside the server.
     *
     * @return array{title: string, readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false}
     */
    public static function annotations(): array
    {
        return [
            'title' => self::TITLE,
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    /**
     * Same check the handler has always applied: a non-blank string of at
     * most CAPABILITY_MAX_LENGTH characters.
     */
    public static function isValidCapability(string $capability): bool
    {
        $length = \preg_match_all('/./us', $capability);
        $hasContent = \preg_match('/[^\s\p{Z}\x{FEFF}]/u', $capability);

        return 1 === $hasContent && false !== $length && $length <= self::CAPABILITY_MAX_LENGTH;
    }
}
