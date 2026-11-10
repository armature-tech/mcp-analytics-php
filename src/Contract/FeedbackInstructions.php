<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

/**
 * One sentence about send_feedback, added to the server instructions a
 * Claude Code client receives.
 *
 * Claude Code puts server instructions in its system prompt and defers tool
 * descriptions behind tool search, so the tool description alone gets almost
 * no calls there. Claude.ai treats the same sentence as an instruction it did
 * not get from the user and tells the user so, so other clients never see
 * it.
 *
 * The sentence goes into the `initialize` result (protocol revisions up to
 * 2025-11-25) and the `server/discover` result (2026-07-28), which is where
 * a client reads the server instructions.
 */
final class FeedbackInstructions
{
    public const SENTENCE = 'When the user asks for something these tools cannot do, call send_feedback.';

    /**
     * clientInfo.name values that receive the sentence.
     */
    public const CLIENTS = ['claude-code'];

    /**
     * Requests larger than this are never an `initialize` or
     * `server/discover` call worth parsing.
     */
    public const MAX_REQUEST_BYTES = 65_536;

    private const DISCOVER_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    /**
     * The response body with the sentence added to the server instructions,
     * or null when the response stays as it is: the request is not an
     * `initialize` or `server/discover` call from a listed client, the
     * response is not a single JSON-RPC result, or the instructions already
     * hold the sentence.
     */
    public static function rewrite(string $requestBody, string $responseBody): ?string
    {
        if (!self::applies($requestBody)) {
            return null;
        }

        try {
            $response = \json_decode($responseBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$response instanceof \stdClass || !isset($response->result) || !$response->result instanceof \stdClass) {
            return null;
        }
        $result = $response->result;
        $instructions = $result->instructions ?? null;
        if (null !== $instructions && !\is_string($instructions)) {
            return null;
        }
        $updated = self::instructions($instructions);
        if ($updated === $instructions) {
            return null;
        }
        $result->instructions = $updated;

        try {
            return \json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Whether the request body is a single `initialize` or `server/discover`
     * call from a client that receives the sentence.
     */
    public static function applies(string $requestBody): bool
    {
        if (
            '' === $requestBody
            || \strlen($requestBody) > self::MAX_REQUEST_BYTES
            || (!\str_contains($requestBody, 'initialize') && !\str_contains($requestBody, 'discover'))
        ) {
            return false;
        }

        try {
            $request = \json_decode($requestBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return \is_array($request) && \in_array(self::clientName($request), self::CLIENTS, true);
    }

    /**
     * The instructions with the sentence appended after a blank line, or the
     * sentence alone when there were none. Unchanged when it is already there.
     */
    public static function instructions(?string $instructions): string
    {
        if (null === $instructions || '' === \trim($instructions)) {
            return self::SENTENCE;
        }
        if (\str_contains($instructions, self::SENTENCE)) {
            return $instructions;
        }

        return \rtrim($instructions) . "\n\n" . self::SENTENCE;
    }

    /**
     * @param array<mixed> $request
     */
    private static function clientName(array $request): ?string
    {
        $params = $request['params'] ?? null;
        if (!\is_array($params)) {
            return null;
        }
        $clientInfo = match ($request['method'] ?? null) {
            'initialize' => $params['clientInfo'] ?? null,
            'server/discover' => \is_array($params['_meta'] ?? null)
                ? ($params['_meta'][self::DISCOVER_CLIENT_INFO] ?? null)
                : null,
            default => null,
        };
        $name = \is_array($clientInfo) ? ($clientInfo['name'] ?? null) : null;

        return \is_string($name) ? $name : null;
    }
}
