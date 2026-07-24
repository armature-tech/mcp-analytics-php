<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Adapter\Official\AnalyticsHttpMiddleware;
use Armature\McpAnalytics\Adapter\Official\InstrumentedReferenceHandler;
use Armature\McpAnalytics\Adapter\Official\InstrumentedRegistry;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;

final class Instrumentation
{
    private readonly AnalyticsHttpMiddleware $httpMiddleware;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly InstrumentedRegistry $registry,
        private readonly InstrumentedReferenceHandler $referenceHandler,
        RequestContextStore $requestContextStore,
    ) {
        $this->httpMiddleware = new AnalyticsHttpMiddleware($requestContextStore);
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    public function registry(): InstrumentedRegistry
    {
        return $this->registry;
    }

    public function referenceHandler(): InstrumentedReferenceHandler
    {
        return $this->referenceHandler;
    }

    public function httpMiddleware(): AnalyticsHttpMiddleware
    {
        return $this->httpMiddleware;
    }

    public function flush(): void
    {
        $this->recorder->flush();
    }

    public function close(): void
    {
        $this->recorder->close();
    }

    public function dropped(): int
    {
        return $this->recorder->dropped();
    }
}
