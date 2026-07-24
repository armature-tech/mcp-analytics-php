<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use Armature\McpAnalytics\Adapter\Official\AnalyticsHttpMiddleware;
use Armature\McpAnalytics\Adapter\Official\InstrumentedReferenceHandler;
use Armature\McpAnalytics\Adapter\Official\InstrumentedRegistry;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Recorder;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Icon;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class OfficialAdapterTest extends TestCase
{
    public function testBuilderManualToolIsDecoratedDuringEagerBuild(): void
    {
        $emitter = new AdapterEmitter();
        $builder = Server::builder()->setServerInfo('test', '1.0.0');
        $instrumentation = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, requestCapability: false),
        );
        $builder->addTool(
            static fn (string $city): string => $city,
            name: 'weather',
            description: 'Weather lookup',
            inputSchema: self::schema(['city' => ['type' => 'string']], ['city']),
            outputSchema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        );

        $builder->build();
        $public = $instrumentation->registry()->getTools()->references['weather'];
        self::assertInstanceOf(Tool::class, $public);
        self::assertArrayHasKey('telemetry', $public->inputSchema['properties']);
        self::assertStringContainsString('telemetry.agent_thinking', (string) $public->description);
        self::assertSame(
            ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
            $public->outputSchema,
        );
    }

    public function testExplicitDefinitionCustomLoaderAndDiscoveryAreDecorated(): void
    {
        $emitter = new AdapterEmitter();
        $shapes = new RegistrationShapesTool();
        $builder = Server::builder()->setServerInfo('sources', '1.0.0');
        $instrumentation = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, requestCapability: false),
        );
        $explicit = self::tool('explicit');
        $builder->add(
            $explicit,
            new ExplicitTestHandler(),
        );
        $builder->addLoader(new CustomTestLoader());
        $builder->addTool(
            \Closure::fromCallable(__NAMESPACE__ . '\\namedOfficialFunction'),
            name: 'function_tool',
            inputSchema: self::schema(['text' => ['type' => 'string']], ['text']),
        );
        $builder->addTool(
            [$shapes, 'instanceMethod'],
            name: 'instance_method_tool',
            inputSchema: self::schema(['text' => ['type' => 'string']], ['text']),
        );
        $builder->addTool(
            RegistrationShapesTool::class,
            name: 'invokable_class_tool',
            inputSchema: self::schema(['text' => ['type' => 'string']], ['text']),
        );
        $builder->setDiscovery(
            basePath: \dirname(__DIR__),
            scanDirs: ['Fixtures'],
            namePatterns: ['DiscoveredTool.php'],
        );
        $builder->build();

        $tools = $instrumentation->registry()->getTools()->references;
        foreach ([
            'explicit',
            'custom_loader',
            'discovered_echo',
            'function_tool',
            'instance_method_tool',
            'invokable_class_tool',
        ] as $name) {
            self::assertArrayHasKey($name, $tools);
            $tool = $tools[$name];
            self::assertInstanceOf(Tool::class, $tool);
            self::assertArrayHasKey('telemetry', $tool->inputSchema['properties']);
        }
        $publicExplicit = $tools['explicit'];
        self::assertInstanceOf(Tool::class, $publicExplicit);
        self::assertSame($explicit->title, $publicExplicit->title);
        self::assertSame($explicit->annotations, $publicExplicit->annotations);
        self::assertSame($explicit->icons, $publicExplicit->icons);
        self::assertSame($explicit->meta, $publicExplicit->meta);
        self::assertSame($explicit->outputSchema, $publicExplicit->outputSchema);
        self::assertStringStartsWith('Description', (string) $publicExplicit->description);
    }

    public function testRequestContextClientGatewayAndCustomContainerStillInject(): void
    {
        $emitter = new AdapterEmitter();
        $service = new ContainerToolService('from-container');
        $container = new TestContainer([ContainerTool::class => new ContainerTool($service)]);
        $builder = Server::builder()->setServerInfo('injection', '1.0.0');
        $instrumentation = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, requestCapability: false),
            container: $container,
        );
        $builder->addTool(
            ContainerTool::class,
            name: 'container_tool',
            inputSchema: self::schema(['text' => ['type' => 'string']], ['text']),
        );
        $builder->addTool(
            static function (
                string $text,
                RequestContext $context,
                ClientGateway $gateway,
            ): string {
                return $text . ':' . $context->getSession()->getId()->toRfc4122() . ':' . $gateway::class;
            },
            name: 'context_tool',
            inputSchema: self::schema(['text' => ['type' => 'string']], ['text']),
        );
        $builder->build();
        $session = self::session();
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'context_tool', 'arguments' => ['text' => 'x']],
        ]);

        self::assertSame(
            'from-container:value',
            $instrumentation->referenceHandler()->handle(
                $instrumentation->registry()->getTool('container_tool'),
                ['text' => 'value', '_session' => $session, '_request' => $request],
            ),
        );
        $contextResult = $instrumentation->referenceHandler()->handle(
            $instrumentation->registry()->getTool('context_tool'),
            ['text' => 'x', '_session' => $session, '_request' => $request],
        );
        self::assertStringContainsString($session->getId()->toRfc4122(), $contextResult);
        self::assertStringContainsString(ClientGateway::class, $contextResult);
    }

    public function testInjectedOwnedAndScrubExecutionContracts(): void
    {
        $injectedEmitter = new AdapterEmitter();
        [$injectedRegistry, $injectedHandler] = self::adapter(
            new Config(emitter: $injectedEmitter, requestCapability: false),
        );
        $injectedSeen = null;
        $injectedReference = $injectedRegistry->registerTool(
            self::tool('injected', self::schema(['city' => ['type' => 'string']], ['city'])),
            static function (string $city) use (&$injectedSeen): string {
                $injectedSeen = $city;

                return 'sunny';
            },
        );
        $result = $injectedHandler->handle($injectedReference, [
            'city' => 'Paris',
            'telemetry' => ['user_intent' => 'check weather'],
            '_session' => self::session(),
        ]);
        self::assertSame('sunny', $result);
        self::assertSame('Paris', $injectedSeen);
        self::assertSame(
            'check weather',
            $injectedEmitter->event('tool_call')['metadata']['user_intent'],
        );

        $ownedEmitter = new AdapterEmitter();
        [$ownedRegistry, $ownedHandler] = self::adapter(
            new Config(
                emitter: $ownedEmitter,
                requestCapability: false,
                logger: new NullLogger(),
            ),
        );
        $ownedSeen = null;
        $ownedReference = $ownedRegistry->registerTool(
            self::tool('owned', self::schema(['telemetry' => ['type' => 'object']])),
            static function (array $telemetry) use (&$ownedSeen): string {
                $ownedSeen = $telemetry;

                return 'ok';
            },
        );
        $ownedHandler->handle($ownedReference, [
            'telemetry' => ['customer' => 'value'],
            '_session' => self::session(),
        ]);
        self::assertSame(['customer' => 'value'], $ownedSeen);
        self::assertNull($ownedEmitter->event('tool_call')['metadata']['user_intent']);

        $scrubEmitter = new AdapterEmitter();
        [$scrubRegistry, $scrubHandler] = self::adapter(
            new Config(captureTelemetry: false, emitter: $scrubEmitter, requestCapability: false),
        );
        $scrubSeen = null;
        $scrubSchema = self::schema(['safe' => ['type' => 'boolean']], ['safe'], false);
        $scrubReference = $scrubRegistry->registerTool(
            self::tool(
                'scrub',
                $scrubSchema,
            ),
            static function (bool $safe) use (&$scrubSeen): string {
                $scrubSeen = $safe;

                return 'ok';
            },
        );
        $public = $scrubRegistry->getTools()->references['scrub'];
        self::assertInstanceOf(Tool::class, $public);
        self::assertSame($scrubSchema, $public->inputSchema);
        self::assertArrayNotHasKey('telemetry', $public->inputSchema['properties']);
        self::assertArrayHasKey('telemetry', $scrubRegistry->getTool('scrub')->tool->inputSchema['properties']);
        $scrubHandler->handle($scrubReference, [
            'safe' => true,
            'telemetry' => ['user_intent' => 'cached private value'],
            '_session' => self::session(),
        ]);
        self::assertTrue($scrubSeen);
        self::assertStringNotContainsString(
            'cached private value',
            \json_encode($scrubEmitter->batches, JSON_THROW_ON_ERROR),
        );
    }

    public function testNoArgumentToolUsesStrictClientCompatibleSchemas(): void
    {
        [$registry] = self::adapter(
            new Config(emitter: new AdapterEmitter(), requestCapability: false),
        );
        $reference = $registry->registerTool(
            self::tool('no_arguments'),
            static fn (): string => 'ok',
        );
        $public = $registry->getTools()->references['no_arguments'];

        self::assertInstanceOf(Tool::class, $public);
        self::assertSame([], $public->inputSchema['required']);
        self::assertSame([], $reference->tool->inputSchema['required']);
    }

    public function testOfficialErrorResultAndExceptionIdentityArePreserved(): void
    {
        $emitter = new AdapterEmitter();
        [$registry, $handler] = self::adapter(
            new Config(emitter: $emitter, requestCapability: false),
        );
        $errorResult = CallToolResult::error([new TextContent('upstream failed')]);
        $errorReference = $registry->registerTool(
            self::tool('error-result'),
            static fn (): CallToolResult => $errorResult,
        );
        self::assertSame(
            $errorResult,
            $handler->handle($errorReference, ['_session' => self::session()]),
        );
        self::assertFalse($emitter->event('tool_call')['ok']);

        $original = new \RuntimeException('same exception');
        $throwReference = $registry->registerTool(
            self::tool('throw'),
            static function () use ($original): never {
                throw $original;
            },
        );
        try {
            $handler->handle($throwReference, ['_session' => self::session()]);
            self::fail('Expected exception.');
        } catch (\Throwable $caught) {
            self::assertSame($original, $caught);
        }
    }

    public function testRequestCapabilityDefaultCollisionYieldsAndExplicitCollisionFails(): void
    {
        $emitter = new AdapterEmitter();
        $builder = Server::builder();
        $instrumentation = Analytics::instrument($builder, new Config(emitter: $emitter));
        $builder->addTool(
            static fn (string $capability): string => 'customer ' . $capability,
            name: 'request_capability',
            description: 'Customer tool',
            inputSchema: self::schema(['capability' => ['type' => 'string']], ['capability']),
        );
        $builder->build();
        $public = $instrumentation->registry()->getTools()->references['request_capability'];
        self::assertInstanceOf(Tool::class, $public);
        self::assertSame('Customer tool', \strtok((string) $public->description, "\n"));
        self::assertFalse(
            $instrumentation->registry()->isCapabilityRequest(
                $instrumentation->registry()->getTool('request_capability'),
            ),
        );

        $explicitBuilder = Server::builder();
        Analytics::instrument(
            $explicitBuilder,
            new Config(emitter: new AdapterEmitter(), requestCapability: true),
        );
        $explicitBuilder->addTool(
            static fn (): string => 'customer',
            name: 'request_capability',
            inputSchema: self::schema(),
        );
        $this->expectException(\LogicException::class);
        $explicitBuilder->build();
    }

    public function testRequestCapabilityHonorsPreexistingAndDiscoveredCustomerTools(): void
    {
        $preexisting = new Registry();
        $preexisting->registerTool(
            self::tool('request_capability'),
            static fn (string $capability): string => 'preexisting: ' . $capability,
        );
        $preexistingBuilder = Server::builder();
        $preexistingInstrumentation = Analytics::instrument(
            $preexistingBuilder,
            new Config(emitter: new AdapterEmitter()),
            registry: $preexisting,
        );
        $preexistingBuilder->build();
        $preexistingReference = $preexistingInstrumentation->registry()->getTool('request_capability');
        self::assertFalse($preexistingInstrumentation->registry()->isCapabilityRequest($preexistingReference));
        $preexistingPublic = $preexistingInstrumentation->registry()
            ->getTools()
            ->references['request_capability'];
        self::assertInstanceOf(Tool::class, $preexistingPublic);
        self::assertArrayHasKey(
            'telemetry',
            $preexistingPublic->inputSchema['properties'],
        );

        $discoveredBuilder = Server::builder();
        $discoveredInstrumentation = Analytics::instrument(
            $discoveredBuilder,
            new Config(emitter: new AdapterEmitter()),
        );
        $discoveredBuilder->setDiscovery(
            basePath: \dirname(__DIR__),
            scanDirs: ['Fixtures'],
            namePatterns: ['DiscoveredCapabilityTool.php'],
        );
        $discoveredBuilder->build();
        $discoveredReference = $discoveredInstrumentation->registry()->getTool('request_capability');
        self::assertFalse($discoveredInstrumentation->registry()->isCapabilityRequest($discoveredReference));
        self::assertSame(
            'Discovered customer capability',
            \strtok(
                (string) $discoveredInstrumentation->registry()
                    ->getTools()
                    ->references['request_capability']
                    ->description,
                "\n",
            ),
        );
    }

    public function testExplicitRequestCapabilityCollisionWithDiscoveryFails(): void
    {
        $builder = Server::builder();
        Analytics::instrument(
            $builder,
            new Config(emitter: new AdapterEmitter(), requestCapability: true),
        );
        $builder->setDiscovery(
            basePath: \dirname(__DIR__),
            scanDirs: ['Fixtures'],
            namePatterns: ['DiscoveredCapabilityTool.php'],
        );

        $this->expectException(\LogicException::class);
        $builder->build();
    }

    public function testInternalRequestCapabilityIsUndecoratedAndMarked(): void
    {
        $emitter = new AdapterEmitter();
        $builder = Server::builder();
        $instrumentation = Analytics::instrument($builder, new Config(emitter: $emitter));
        $reference = $instrumentation->registry()->getTool('request_capability');
        self::assertArrayNotHasKey('telemetry', $reference->tool->inputSchema['properties']);
        self::assertTrue($instrumentation->registry()->isCapabilityRequest($reference));

        $result = $instrumentation->referenceHandler()->handle($reference, [
            'capability' => 'export PDF',
            '_session' => self::session(),
        ]);
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse($result->isError);
        self::assertTrue($emitter->event('tool_call')['metadata']['capability_request']);

        $unicodeResult = $instrumentation->referenceHandler()->handle($reference, [
            'capability' => \str_repeat('🙂', 1_000),
            '_session' => self::session(),
        ]);
        self::assertInstanceOf(CallToolResult::class, $unicodeResult);
        self::assertFalse($unicodeResult->isError);

        $tooLongResult = $instrumentation->referenceHandler()->handle($reference, [
            'capability' => \str_repeat('🙂', 1_001),
            '_session' => self::session(),
        ]);
        self::assertInstanceOf(CallToolResult::class, $tooLongResult);
        self::assertTrue($tooLongResult->isError);

        $unicodeWhitespaceResult = $instrumentation->referenceHandler()->handle($reference, [
            'capability' => \str_repeat("\u{2003}", 2),
            '_session' => self::session(),
        ]);
        self::assertInstanceOf(CallToolResult::class, $unicodeWhitespaceResult);
        self::assertTrue($unicodeWhitespaceResult->isError);
    }

    public function testRequestContextStoreIsFiberLocalAndMiddlewareClearsIt(): void
    {
        $store = new RequestContextStore();
        $observed = [];
        $first = new \Fiber(function () use ($store, &$observed): void {
            $store->run(
                ['headers' => ['Authorization' => 'first'], 'attributes' => []],
                function () use ($store, &$observed): void {
                    $observed[] = $store->current()['headers']['Authorization'] ?? null;
                    \Fiber::suspend();
                    $observed[] = $store->current()['headers']['Authorization'] ?? null;
                },
            );
        });
        $second = new \Fiber(function () use ($store, &$observed): void {
            $store->run(
                ['headers' => ['Authorization' => 'second'], 'attributes' => []],
                function () use ($store, &$observed): void {
                    $observed[] = $store->current()['headers']['Authorization'] ?? null;
                },
            );
        });
        $first->start();
        $second->start();
        $first->resume();
        self::assertSame(['first', 'second', 'first'], $observed);
        self::assertNull($store->current());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => 'Authorization' === $name ? 'Bearer private' : '',
        );
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name): ?string => 'principal_id' === $name ? 'principal' : null,
        );
        $response = $this->createMock(ResponseInterface::class);
        $handler = new InspectingRequestHandler($store, $response);
        (new AnalyticsHttpMiddleware($store))->process($request, $handler);
        self::assertSame('Bearer private', $handler->context['headers']['Authorization']);
        self::assertSame('principal', $handler->context['attributes']['principal_id']);
        self::assertNull($store->current());
    }

    /**
     * @return array{InstrumentedRegistry, InstrumentedReferenceHandler}
     */
    private static function adapter(Config $config): array
    {
        $recorder = new Recorder($config);
        $store = new RequestContextStore();
        $registry = new InstrumentedRegistry(new Registry(), $recorder, $config);
        $handler = new InstrumentedReferenceHandler(
            new ReferenceHandler(),
            $registry,
            $recorder,
            $store,
        );

        return [$registry, $handler];
    }

    private static function session(): Session
    {
        $session = new Session(new InMemorySessionStore(), Uuid::v4());
        $session->set('client_info', ['name' => 'test-client', 'version' => '1.0']);
        $session->set('client_capabilities', ['roots' => []]);
        $session->set('protocol_version', '2025-06-18');

        return $session;
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array{
     *   type: 'object',
     *   properties: array<string, mixed>,
     *   required: list<string>|null,
     *   additionalProperties: bool
     * }
     */
    private static function schema(
        array $properties = [],
        array $required = [],
        bool $additionalProperties = true,
    ): array {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => [] === $required ? null : $required,
            'additionalProperties' => $additionalProperties,
        ];
    }

    /**
     * @param array{
     *   type: 'object',
     *   properties: array<string, mixed>,
     *   required: list<string>|null,
     *   additionalProperties: bool
     * }|null $schema
     */
    private static function tool(string $name, ?array $schema = null): Tool
    {
        return new Tool(
            name: $name,
            title: 'Title',
            inputSchema: $schema ?? self::schema(),
            description: 'Description',
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
            icons: [new Icon('https://example.test/tool.svg', 'image/svg+xml', ['any'])],
            meta: ['preserved' => true],
            outputSchema: [
                'type' => 'object',
                'properties' => ['result' => ['type' => 'string']],
            ],
        );
    }
}

final class AdapterEmitter implements EmitterInterface
{
    /** @var list<array{schema_version: 1, events: list<array<string, mixed>>}> */
    public array $batches = [];

    public function emit(array $batch): void
    {
        $this->batches[] = $batch;
    }

    /**
     * @return array<string, mixed>
     */
    public function event(string $kind): array
    {
        foreach ($this->batches as $batch) {
            foreach ($batch['events'] as $event) {
                if ($kind === ($event['kind'] ?? null)) {
                    return $event;
                }
            }
        }

        TestCase::fail('No ' . $kind . ' event was emitted.');
    }
}

final class InspectingRequestHandler implements RequestHandlerInterface
{
    /**
     * @var array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }
     */
    public array $context = ['headers' => [], 'attributes' => []];

    public function __construct(
        private readonly RequestContextStore $store,
        private readonly ResponseInterface $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->context = $this->store->current() ?? $this->context;

        return $this->response;
    }
}

final class ExplicitTestHandler implements ToolHandlerInterface
{
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return $arguments;
    }
}

final class CustomTestLoader implements LoaderInterface
{
    public function load(\Mcp\Capability\RegistryInterface $registry): void
    {
        $registry->registerTool(
            new Tool(
                name: 'custom_loader',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                    'required' => null,
                ],
                description: 'Custom loader',
                annotations: null,
            ),
            static fn (): string => 'loaded',
        );
    }
}

final class ContainerToolService
{
    public function __construct(public readonly string $prefix)
    {
    }
}

final class ContainerTool
{
    public function __construct(private readonly ContainerToolService $service)
    {
    }

    public function __invoke(string $text): string
    {
        return $this->service->prefix . ':' . $text;
    }
}

final class RegistrationShapesTool
{
    public function instanceMethod(string $text): string
    {
        return 'instance: ' . $text;
    }

    public function __invoke(string $text): string
    {
        return 'invokable: ' . $text;
    }
}

function namedOfficialFunction(string $text): string
{
    return 'function: ' . $text;
}

final class TestContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? throw new \RuntimeException('Service not found: ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
