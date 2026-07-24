<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Privacy;

final class InvalidRedactionResult extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('redact_event_invalid_result');
    }
}
