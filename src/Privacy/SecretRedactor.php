<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Privacy;

final class SecretRedactor
{
    public const CIRCULAR_PLACEHOLDER = '[circular]';
    public const SENSITIVE_FIELD_PLACEHOLDER = '[redacted:sensitive-field]';

    /** @var list<array{pattern: string, replacement: string}> */
    private const RULES = [
        [
            'pattern' => '~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]*PRIVATE KEY-----~',
            'replacement' => '[redacted:pem]',
        ],
        [
            'pattern' => '~\b(password|passwd|pwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|authorization)([=:])([^\s"\'`,;&]{4,})~i',
            'replacement' => '$1$2[redacted:sensitive-kv]',
        ],
        [
            'pattern' => '~\b(?:AKIA|ASIA|ABIA|ACCA|AGPA|AIDA|AIPA|ANPA|ANVA|AROA)[A-Z0-9]{16}\b~',
            'replacement' => '[redacted:aws-access-key-id]',
        ],
        [
            'pattern' => '~\b(?:gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{22,255})\b~',
            'replacement' => '[redacted:github-token]',
        ],
        [
            'pattern' => '~\bAIza[0-9A-Za-z_-]{35}\b~',
            'replacement' => '[redacted:google-api-key]',
        ],
        [
            'pattern' => '~\bxox[abprs]-[A-Za-z0-9-]{10,}\b~',
            'replacement' => '[redacted:slack-token]',
        ],
        [
            'pattern' => '~\b[rs]k_(?:live|test)_[A-Za-z0-9]{16,}\b~',
            'replacement' => '[redacted:stripe-key]',
        ],
        [
            'pattern' => '~\bsk-ant-[A-Za-z0-9_-]{16,}\b~',
            'replacement' => '[redacted:anthropic-api-key]',
        ],
        [
            'pattern' => '~\bsk-[A-Za-z0-9_-]{20,}\b~',
            'replacement' => '[redacted:openai-api-key]',
        ],
        [
            'pattern' => '~\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{10,}\b~',
            'replacement' => '[redacted:jwt]',
        ],
        [
            'pattern' => '~\b([a-zA-Z][a-zA-Z0-9+.-]*://[^\s:/@]+):([^\s@]+)@~',
            'replacement' => '$1:[redacted:connection-string]@',
        ],
        [
            'pattern' => '~\b[Bb]earer +[A-Za-z0-9._\~+/=-]{16,}~',
            'replacement' => 'Bearer [redacted:bearer]',
        ],
        [
            'pattern' => '~\b[Bb]asic +[A-Za-z0-9+/=]{16,}~',
            'replacement' => 'Basic [redacted:basic]',
        ],
    ];

    /** @var array<string, true> */
    private const SENSITIVE_FIELD_NAMES = [
        'password' => true,
        'passwd' => true,
        'pwd' => true,
        'secret' => true,
        'apikey' => true,
        'accesskey' => true,
        'secretkey' => true,
        'secretaccesskey' => true,
        'token' => true,
        'accesstoken' => true,
        'refreshtoken' => true,
        'idtoken' => true,
        'sessiontoken' => true,
        'authorization' => true,
        'auth' => true,
        'clientsecret' => true,
        'privatekey' => true,
        'credential' => true,
        'credentials' => true,
        'connectionstring' => true,
        'databaseurl' => true,
        'dsn' => true,
    ];

    public static function string(string $value): string
    {
        $redacted = $value;
        foreach (self::RULES as $rule) {
            $next = \preg_replace($rule['pattern'], $rule['replacement'], $redacted);
            $redacted = null === $next ? Sanitizer::REDACTION_FAILED_PLACEHOLDER : $next;
        }

        return $redacted;
    }

    /**
     * @param \SplObjectStorage<object, mixed>|null $seen
     */
    public static function value(mixed $value, ?\SplObjectStorage $seen = null): mixed
    {
        if (\is_string($value)) {
            return self::string($value);
        }
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $key => $entry) {
                $result[$key] = \is_string($key)
                    && \is_string($entry)
                    && isset(self::SENSITIVE_FIELD_NAMES[self::normalizeFieldName($key)])
                    ? self::SENSITIVE_FIELD_PLACEHOLDER
                    : self::value($entry, $seen);
            }

            return $result;
        }
        if (!\is_object($value)) {
            return $value;
        }

        $tracked = $seen ?? new \SplObjectStorage();
        if ($tracked->contains($value)) {
            return self::CIRCULAR_PLACEHOLDER;
        }
        $tracked->attach($value);
        try {
            $result = [];
            foreach (\get_object_vars($value) as $key => $entry) {
                $result[$key] = \is_string($entry)
                    && isset(self::SENSITIVE_FIELD_NAMES[self::normalizeFieldName($key)])
                    ? self::SENSITIVE_FIELD_PLACEHOLDER
                    : self::value($entry, $tracked);
            }

            return $result;
        } finally {
            $tracked->detach($value);
        }
    }

    public static function normalizeFieldName(string $key): string
    {
        return \str_replace(['_', '-'], '', \strtolower($key));
    }
}
