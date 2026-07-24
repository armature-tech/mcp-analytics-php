<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Privacy\Sanitizer;
use Armature\McpAnalytics\Privacy\Utf8;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $vectors;

    protected function setUp(): void
    {
        $decoded = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../Fixtures/telemetry-contract-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $this->vectors = $decoded;
    }

    public function testCanonicalSanitizationVectors(): void
    {
        $vectors = $this->vectors['sanitization'] ?? null;
        self::assertIsArray($vectors);
        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            self::assertSame($vector['expect'], Sanitizer::value($vector['value']), (string) $vector['name']);
        }
    }

    public function testCanonicalPlaceholders(): void
    {
        $placeholders = $this->vectors['placeholders'] ?? null;
        self::assertIsArray($placeholders);
        self::assertSame($placeholders['binary'], Sanitizer::BINARY_REMOVED_PLACEHOLDER);
        self::assertSame($placeholders['base64'], Sanitizer::BASE64_REMOVED_PLACEHOLDER);
        self::assertSame($placeholders['redaction_failed'], Sanitizer::REDACTION_FAILED_PLACEHOLDER);
    }

    public function testCanonicalSecretRedactionVectors(): void
    {
        $vectors = $this->vectors['secret_redaction'] ?? null;
        self::assertIsArray($vectors);
        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            $value = $vector['value'] ?? \implode('', $vector['value_parts']);
            self::assertSame(
                $vector['expect'],
                Sanitizer::prepareForPreview($value),
                (string) $vector['name'],
            );
        }
    }

    public function testObjectCyclesAreCutWithoutTreatingSharedObjectsAsCycles(): void
    {
        $shared = (object) ['blob' => 'QUFBQQ==', 'note' => 'keep'];
        self::assertSame(
            [
                ['blob' => '[binary removed]', 'note' => 'keep'],
                ['blob' => '[binary removed]', 'note' => 'keep'],
            ],
            Sanitizer::value([$shared, $shared]),
        );

        $cyclic = new \stdClass();
        $cyclic->note = 'keep';
        $cyclic->self = $cyclic;
        self::assertSame(
            ['note' => 'keep', 'self' => '[circular]'],
            Sanitizer::value($cyclic),
        );
    }

    public function testSecretRedactionCanBeDisabledWithoutDisablingBinaryRemoval(): void
    {
        self::assertSame(
            [
                'token' => 'sk-proj-AbCdEfGhIjKlMnOpQrStUv123456',
                'blob' => '[binary removed]',
            ],
            Sanitizer::prepareForPreview(
                [
                    'token' => 'sk-proj-AbCdEfGhIjKlMnOpQrStUv123456',
                    'blob' => 'QUFB',
                ],
                redactSecrets: false,
            ),
        );
    }

    public function testCallbackFailureIsClosed(): void
    {
        self::assertSame(
            Sanitizer::REDACTION_FAILED_PLACEHOLDER,
            Sanitizer::prepareForPreview('secret', static function (): never {
                throw new \RuntimeException('failed');
            }),
        );
    }

    public function testUtf8TruncationNeverLeavesAnInvalidSequence(): void
    {
        self::assertSame(
            ['value' => '😀a', 'truncated' => true],
            Utf8::truncate('😀a😀', 6),
        );
    }
}
