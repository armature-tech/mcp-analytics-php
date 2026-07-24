<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

final class DeliveryError extends \RuntimeException
{
    public readonly ?string $causeClass;

    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $status,
        public readonly bool $retryable,
        public readonly int $attempts,
        ?\Throwable $cause = null,
    ) {
        $this->causeClass = null === $cause ? null : $cause::class;
        parent::__construct($errorCode);
    }
}
