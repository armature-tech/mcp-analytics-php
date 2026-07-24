<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Event;

use Symfony\Component\Uid\Uuid;

final class SessionIds
{
    private const ANONYMOUS_NAME = 'unknown';
    private const SESSION_ID_PATTERN = '/^mcp_([A-Za-z0-9.-]+)_v_([A-Za-z0-9.-]*)_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';
    private const SESSION_SEED_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const WORKFLOW_RUN_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private static ?string $processScopedId = null;

    /**
     * @param array{name?: mixed, version?: mixed}|null $clientInfo
     */
    public static function buildStateless(?array $clientInfo = null, ?string $sessionSeed = null): string
    {
        $seed = \trim($sessionSeed ?? '');
        $uuid = 1 === \preg_match(self::SESSION_SEED_PATTERN, $seed)
            ? \strtolower($seed)
            : Uuid::v4()->toRfc4122();

        return \sprintf(
            'mcp_%s_v_%s_%s',
            self::slug($clientInfo['name'] ?? null, self::ANONYMOUS_NAME),
            self::slug($clientInfo['version'] ?? null, ''),
            $uuid,
        );
    }

    /**
     * @return array{name: string, version?: string}|null
     */
    public static function parseClientInfo(?string $sessionId): ?array
    {
        if (1 !== \preg_match(self::SESSION_ID_PATTERN, $sessionId ?? '', $matches)) {
            return null;
        }
        if (self::ANONYMOUS_NAME === $matches[1]) {
            return null;
        }

        if ('' === $matches[2]) {
            return ['name' => $matches[1]];
        }

        return ['name' => $matches[1], 'version' => $matches[2]];
    }

    public static function requestId(?string $requestId, ?string $sessionId = null): string
    {
        if (null === $requestId || '' === $requestId) {
            return Uuid::v4()->toRfc4122();
        }

        return null === $sessionId || '' === $sessionId
            ? $requestId
            : $sessionId . '#' . $requestId;
    }

    public static function processScoped(): string
    {
        return self::$processScopedId ??= 'stdio-' . Uuid::v4()->toRfc4122();
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 !== \strcasecmp($key, $name)) {
                continue;
            }
            if (\is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function workflowRunId(array $headers): ?string
    {
        $value = \trim(self::header($headers, 'x-armature-workflow-run-id') ?? '');

        return 1 === \preg_match(self::WORKFLOW_RUN_ID_PATTERN, $value) ? $value : null;
    }

    private static function slug(mixed $value, string $fallback): string
    {
        $text = \trim(\is_scalar($value) ? (string) $value : '');
        $text = \preg_replace('/[^A-Za-z0-9.-]+/', '-', $text) ?? '';
        $text = \trim($text, '-');
        $text = \substr($text, 0, 48);

        return '' === $text ? $fallback : $text;
    }
}
