<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use Armature\McpAnalytics\Adapter\Official\AnalyticsHttpMiddleware;
use Armature\McpAnalytics\Adapter\Official\InstrumentedReferenceHandler;
use Armature\McpAnalytics\Adapter\Official\InstrumentedRegistry;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Contract\SchemaPlanner;
use Armature\McpAnalytics\Contract\SendFeedback;
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
use Psr\Log\LoggerInterface;
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
            new Config(emitter: $emitter, sendFeedback: false),
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
        self::assertSame(SchemaPlanner::telemetryJsonSchema(), $public->inputSchema['properties']['telemetry']);
        self::assertSame(
            ['user_intent', 'call_purpose'],
            \array_keys($public->inputSchema['properties']['telemetry']['properties']),
        );
        self::assertSame('Weather lookup', $public->description);
        self::assertSame(
            ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
            $public->outputSchema,
        );
    }

    public function testNoToolDescriptionIsModifiedWithOrWithoutSendFeedback(): void
    {
        $long = \str_repeat('a', 2_000);
        foreach ([
            'send_feedback on by default' => new Config(emitter: new AdapterEmitter()),
            'send_feedback off' => new Config(emitter: new AdapterEmitter(), sendFeedback: false),
            'send_feedback explicitly on' => new Config(emitter: new AdapterEmitter(), sendFeedback: true),
        ] as $label => $config) {
            $logger = $this->createMock(LoggerInterface::class);
            foreach (['debug', 'info', 'notice', 'warning', 'log'] as $method) {
                $logger->expects(self::never())->method($method);
            }
            $builder = Server::builder()->setServerInfo('descriptions', '1.0.0');
            $instrumentation = Analytics::instrument($builder, new Config(
                emitter: $config->emitter,
                sendFeedback: $config->sendFeedback,
                logger: $logger,
                descriptionLengthLogLevel: 'info',
            ));
            $builder->addTool(
                static fn (string $city): string => $city,
                name: 'weather',
                description: 'Weather lookup',
                inputSchema: self::schema(['city' => ['type' => 'string']], ['city']),
            );
            $builder->addTool(
                static fn (string $city): string => $city,
                name: 'long_tool',
                description: $long,
                inputSchema: self::schema(['city' => ['type' => 'string']], ['city']),
            );
            $builder->add(self::tool('explicit'), new ExplicitTestHandler());
            $builder->addLoader(new CustomTestLoader());
            $builder->setDiscovery(
                basePath: \dirname(__DIR__),
                scanDirs: ['Fixtures'],
                namePatterns: ['DiscoveredTool.php'],
            );
            $builder->build();

            $tools = $instrumentation->registry()->getTools()->references;
            $expected = [
                'weather' => 'Weather lookup',
                'long_tool' => $long,
                'explicit' => 'Description',
                'custom_loader' => 'Custom loader',
                'discovered_echo' => 'Discovered echo',
            ];
            foreach ($expected as $name => $description) {
                $tool = $tools[$name] ?? null;
                self::assertInstanceOf(Tool::class, $tool, $label . ': ' . $name);
                self::assertSame($description, $tool->description, $label . ': ' . $name);
                self::assertArrayHasKey('telemetry', $tool->inputSchema['properties'], $label . ': ' . $name);
            }
            foreach ($tools as $name => $tool) {
                self::assertInstanceOf(Tool::class, $tool);
                if ('send_feedback' === $name) {
                    continue;
                }
                self::assertStringNotContainsString('send_feedback', (string) $tool->description, $label . ': ' . $name);
                self::assertStringNotContainsString('request_capability', (string) $tool->description, $label . ': ' . $name);
                self::assertStringNotContainsString('telemetry.', (string) $tool->description, $label . ': ' . $name);
            }
            self::assertSame(false !== $config->sendFeedback, isset($tools['send_feedback']), $label);
            self::assertArrayNotHasKey('request_capability', $tools, $label);
        }
    }

    public function testToolWithoutDescriptionStaysWithoutOne(): void
    {
        [$registry] = self::adapter(new Config(emitter: new AdapterEmitter(), sendFeedback: true));
        $registry->registerTool(
            new Tool(
                name: 'undescribed',
                title: null,
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
                description: null,
                annotations: null,
            ),
            static fn (): string => 'ok',
        );
        $public = $registry->getTools()->references['undescribed'];

        self::assertInstanceOf(Tool::class, $public);
        self::assertNull($public->description);
        self::assertArrayNotHasKey('description', \json_decode((string) \json_encode($public), true));
        self::assertArrayHasKey('telemetry', $public->inputSchema['properties']);
    }

    public function testOldSdkHintSuffixesAreRemovedFromRegisteredDescriptions(): void
    {
        [$registry] = self::adapter(new Config(emitter: new AdapterEmitter(), sendFeedback: true));
        $hint = 'Include telemetry.call_purpose with a short description of this action. Include telemetry.user_intent and telemetry.user_frustration only on the first tool call after each new user message.';
        $capability = 'Call request_capability before you tell the user something can\'t be done here or has to be done elsewhere.';
        foreach ([
            'with_capability' => ["Weather lookup\n\n" . $hint . ' ' . $capability, 'Weather lookup'],
            'telemetry_only' => ["Weather lookup\n\n" . $hint, 'Weather lookup'],
            'hint_only' => [$hint, ''],
            'quoted' => ['Quotes "' . $hint . '" in prose.', 'Quotes "' . $hint . '" in prose.'],
        ] as $name => [$registered, $expected]) {
            $registry->registerTool(
                new Tool(
                    name: $name,
                    title: null,
                    inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
                    description: $registered,
                    annotations: null,
                ),
                static fn (): string => 'ok',
            );
            $public = $registry->getTools()->references[$name];
            self::assertInstanceOf(Tool::class, $public);
            self::assertSame($expected, $public->description, $name);
            self::assertSame($expected, $registry->getTool($name)->tool->description, $name);
        }
    }

    public function testSendFeedbackIsOnByDefaultAndCanBeDisabled(): void
    {
        foreach ([
            'default' => [new Config(emitter: new AdapterEmitter()), true],
            'sendFeedback false' => [new Config(emitter: new AdapterEmitter(), sendFeedback: false), false],
            'deprecated requestCapability false' => [new Config(emitter: new AdapterEmitter(), requestCapability: false), false],
            'new key wins: false over true' => [new Config(emitter: new AdapterEmitter(), requestCapability: true, sendFeedback: false), false],
            'new key wins: true over false' => [new Config(emitter: new AdapterEmitter(), requestCapability: false, sendFeedback: true), true],
            'explicitly on' => [new Config(emitter: new AdapterEmitter(), sendFeedback: true), true],
            'no delivery path' => [new Config(sendFeedback: true), false],
            'analytics disabled' => [new Config(enabled: false, emitter: new AdapterEmitter()), false],
        ] as $label => [$config, $exposed]) {
            $builder = Server::builder()->setServerInfo('feedback', '1.0.0');
            $instrumentation = Analytics::instrument($builder, $config);
            $builder->addTool(
                static fn (string $city): string => $city,
                name: 'weather',
                description: 'Weather lookup',
                inputSchema: self::schema(['city' => ['type' => 'string']], ['city']),
            );
            $builder->build();

            $tools = $instrumentation->registry()->getTools()->references;
            self::assertSame($exposed, isset($tools['send_feedback']), $label);
            self::assertSame($exposed, $instrumentation->registry()->hasTool('send_feedback'), $label);
            self::assertArrayNotHasKey('request_capability', $tools, $label);
            self::assertFalse($instrumentation->registry()->hasTool('request_capability'), $label);
            $weather = $tools['weather'];
            self::assertInstanceOf(Tool::class, $weather);
            self::assertSame('Weather lookup', $weather->description, $label);
        }
    }

    public function testSendFeedbackDefinition(): void
    {
        $builder = Server::builder()->setServerInfo('feedback-definition', '1.0.0');
        $instrumentation = Analytics::instrument($builder, new Config(emitter: new AdapterEmitter()));
        $builder->build();

        $feedbackTool = $instrumentation->registry()->getTools()->references['send_feedback'];
        self::assertInstanceOf(Tool::class, $feedbackTool);
        self::assertSame(SendFeedback::TOOL_NAME, $feedbackTool->name);
        self::assertNull($feedbackTool->title);
        self::assertSame(
            'Call this before you tell the user that these tools can\'t do what they asked. It records the request so the developers of this server can add it. It changes no data and contacts no one. Then answer the user as usual.',
            $feedbackTool->description,
        );
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'capability' => [
                        'type' => 'string',
                        'description' => 'One English sentence describing the missing capability needed for the user\'s task. Translate the summary into English even when the user writes in another language. Describe generic actions and roles. Omit names, contacts, IDs, credentials and all tool argument values.',
                        'minLength' => 1,
                        'maxLength' => 1000,
                    ],
                ],
                'required' => ['capability'],
                'additionalProperties' => false,
            ],
            $feedbackTool->inputSchema,
        );
        self::assertSame(
            [
                'title' => 'Send feedback',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
            \json_decode((string) \json_encode($feedbackTool), true)['annotations'],
        );
        self::assertSame(SendFeedback::annotations(), \json_decode((string) \json_encode($feedbackTool), true)['annotations']);
    }

    public function testExplicitDefinitionCustomLoaderAndDiscoveryAreDecorated(): void
    {
        $emitter = new AdapterEmitter();
        $shapes = new RegistrationShapesTool();
        $builder = Server::builder()->setServerInfo('sources', '1.0.0');
        $instrumentation = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, sendFeedback: false),
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
            new Config(emitter: $emitter, sendFeedback: false),
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
            new Config(emitter: $injectedEmitter, sendFeedback: false),
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
                sendFeedback: false,
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
            new Config(captureTelemetry: false, emitter: $scrubEmitter, sendFeedback: false),
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
            new Config(emitter: new AdapterEmitter(), sendFeedback: false),
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
            new Config(emitter: $emitter, sendFeedback: false),
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

    public function testDefaultSendFeedbackYieldsToACustomerTool(): void
    {
        $emitter = new AdapterEmitter();
        $builder = Server::builder();
        $instrumentation = Analytics::instrument($builder, new Config(emitter: $emitter));
        $builder->addTool(
            static fn (string $capability): string => 'customer ' . $capability,
            name: 'send_feedback',
            description: 'Customer tool',
            inputSchema: self::schema(['capability' => ['type' => 'string']], ['capability']),
        );
        $builder->build();
        $public = $instrumentation->registry()->getTools()->references['send_feedback'];
        self::assertInstanceOf(Tool::class, $public);
        self::assertSame('Customer tool', $public->description);
        self::assertArrayHasKey('telemetry', $public->inputSchema['properties']);
        $reference = $instrumentation->registry()->getTool('send_feedback');
        self::assertFalse($instrumentation->registry()->isCapabilityRequest($reference));
        self::assertSame('customer x', $instrumentation->referenceHandler()->handle($reference, [
            'capability' => 'x',
            '_session' => self::session(),
        ]));
        self::assertArrayNotHasKey('capability_request', $emitter->event('tool_call')['metadata']);
    }

    public function testDefaultSendFeedbackYieldsToALaterCustomerRegistration(): void
    {
        [$registry] = self::adapter(new Config(emitter: new AdapterEmitter()));
        $registry->registerSendFeedback();
        self::assertTrue($registry->isCapabilityRequest($registry->getTool('send_feedback')));

        $registry->registerTool(self::tool('send_feedback'), static fn (): string => 'customer');
        self::assertFalse($registry->isCapabilityRequest($registry->getTool('send_feedback')));
        $public = $registry->getTools()->references['send_feedback'];
        self::assertInstanceOf(Tool::class, $public);
        self::assertSame('Description', $public->description);
    }

    public function testCustomerToolNamedRequestCapabilityIsOrdinary(): void
    {
        $builder = Server::builder();
        $instrumentation = Analytics::instrument($builder, new Config(emitter: new AdapterEmitter(), sendFeedback: true));
        $builder->addTool(
            static fn (string $capability): string => 'customer ' . $capability,
            name: 'request_capability',
            description: 'Customer tool',
            inputSchema: self::schema(['capability' => ['type' => 'string']], ['capability']),
        );
        $builder->build();
        $registry = $instrumentation->registry();
        self::assertFalse($registry->isCapabilityRequest($registry->getTool('request_capability')));
        self::assertTrue($registry->isCapabilityRequest($registry->getTool('send_feedback')));
    }

    public function testExplicitSendFeedbackCollisionFails(): void
    {
        $builder = Server::builder();
        Analytics::instrument(
            $builder,
            new Config(emitter: new AdapterEmitter(), sendFeedback: true),
        );
        $builder->addTool(
            static fn (): string => 'customer',
            name: 'send_feedback',
            inputSchema: self::schema(),
        );
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Tool name "send_feedback" is reserved while sendFeedback is explicitly enabled.');
        $builder->build();
    }

    public function testExplicitSendFeedbackCollisionFailsOnEveryLookup(): void
    {
        [$registry] = self::adapter(new Config(emitter: new AdapterEmitter(), sendFeedback: true));
        $registry->registerTool(self::tool('send_feedback'), static fn (): string => 'customer');
        $registry->registerSendFeedback();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $registry->getTools();
                self::fail('An explicit send_feedback collision must fail on every lookup.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('send_feedback', $exception->getMessage());
            }
        }
    }

    public function testDeprecatedExplicitRequestCapabilityCollisionFails(): void
    {
        $builder = Server::builder();
        Analytics::instrument(
            $builder,
            new Config(emitter: new AdapterEmitter(), requestCapability: true),
        );
        $builder->addTool(
            static fn (): string => 'customer',
            name: 'send_feedback',
            inputSchema: self::schema(),
        );
        $this->expectException(\LogicException::class);
        $builder->build();
    }

    public function testExplicitSendFeedbackRejectsALaterCustomerRegistration(): void
    {
        [$registry] = self::adapter(new Config(emitter: new AdapterEmitter(), sendFeedback: true));
        $registry->registerSendFeedback();
        self::assertTrue($registry->isCapabilityRequest($registry->getTool('send_feedback')));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('send_feedback');
        $registry->registerTool(self::tool('send_feedback'), static fn (): string => 'customer');
    }

    public function testDefaultSendFeedbackYieldsToPreexistingAndDiscoveredCustomerTools(): void
    {
        $preexisting = new Registry();
        $preexisting->registerTool(
            self::tool('send_feedback'),
            static fn (string $capability): string => 'preexisting: ' . $capability,
        );
        $preexistingBuilder = Server::builder();
        $preexistingInstrumentation = Analytics::instrument(
            $preexistingBuilder,
            new Config(emitter: new AdapterEmitter()),
            registry: $preexisting,
        );
        $preexistingBuilder->build();
        $preexistingReference = $preexistingInstrumentation->registry()->getTool('send_feedback');
        self::assertFalse($preexistingInstrumentation->registry()->isCapabilityRequest($preexistingReference));
        $preexistingPublic = $preexistingInstrumentation->registry()
            ->getTools()
            ->references['send_feedback'];
        self::assertInstanceOf(Tool::class, $preexistingPublic);
        self::assertSame('Description', $preexistingPublic->description);
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
        $discoveredReference = $discoveredInstrumentation->registry()->getTool('send_feedback');
        self::assertFalse($discoveredInstrumentation->registry()->isCapabilityRequest($discoveredReference));
        $discoveredPublic = $discoveredInstrumentation->registry()
            ->getTools()
            ->references['send_feedback'];
        self::assertInstanceOf(Tool::class, $discoveredPublic);
        self::assertSame('Discovered customer feedback tool', $discoveredPublic->description);
    }

    public function testExplicitSendFeedbackCollisionWithDiscoveryFails(): void
    {
        $builder = Server::builder();
        Analytics::instrument(
            $builder,
            new Config(emitter: new AdapterEmitter(), sendFeedback: true),
        );
        $builder->setDiscovery(
            basePath: \dirname(__DIR__),
            scanDirs: ['Fixtures'],
            namePatterns: ['DiscoveredCapabilityTool.php'],
        );

        $this->expectException(\LogicException::class);
        $builder->build();
    }

    public function testSendFeedbackIsUndecoratedAndCallsAreMarked(): void
    {
        $emitter = new AdapterEmitter();
        $builder = Server::builder();
        $instrumentation = Analytics::instrument($builder, new Config(emitter: $emitter));
        $reference = $instrumentation->registry()->getTool('send_feedback');
        self::assertArrayNotHasKey('telemetry', $reference->tool->inputSchema['properties']);
        self::assertTrue($instrumentation->registry()->isCapabilityRequest($reference));

        $result = $instrumentation->referenceHandler()->handle($reference, [
            'capability' => 'export PDF',
            '_session' => self::session(),
        ]);
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse($result->isError);
        self::assertSame('Capability request acknowledged.', $result->content[0]->text ?? null);
        $event = $emitter->event('tool_call');
        self::assertSame('send_feedback', $event['metadata']['tool_name']);
        self::assertTrue($event['metadata']['capability_request']);

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
