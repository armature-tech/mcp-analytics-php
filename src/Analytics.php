<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Adapter\Official\InstrumentedReferenceHandler;
use Armature\McpAnalytics\Adapter\Official\InstrumentedRegistry;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;

final class Analytics
{
    public static function instrument(
        Builder $builder,
        ?Config $config = null,
        ?ContainerInterface $container = null,
        ?RegistryInterface $registry = null,
        ?ReferenceHandlerInterface $referenceHandler = null,
    ): Instrumentation {
        $resolvedConfig = $config ?? Config::fromEnvironment();
        $recorder = new Recorder($resolvedConfig);
        $requestContextStore = new RequestContextStore();
        $instrumentedRegistry = new InstrumentedRegistry(
            $registry ?? new Registry(),
            $recorder,
            $resolvedConfig,
        );
        $instrumentedHandler = new InstrumentedReferenceHandler(
            $referenceHandler ?? new ReferenceHandler($container),
            $instrumentedRegistry,
            $recorder,
            $requestContextStore,
        );

        if (null !== $container) {
            $builder->setContainer($container);
        }
        $builder
            ->setRegistry($instrumentedRegistry)
            ->setReferenceHandler($instrumentedHandler);

        $instrumentedRegistry->registerRequestCapability();

        return new Instrumentation(
            $recorder,
            $instrumentedRegistry,
            $instrumentedHandler,
            $requestContextStore,
        );
    }
}
