<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Contract\SchemaPlanner;
use Armature\McpAnalytics\Contract\SendFeedback;
use Armature\McpAnalytics\Recorder;
use Armature\McpAnalytics\TelemetryMode;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

final class InstrumentedRegistry implements RegistryInterface
{
    private const RESERVED_NAME_MESSAGE = 'Tool name "send_feedback" is reserved while sendFeedback is explicitly enabled.';

    /** @var array<string, Tool> */
    private array $publicTools = [];

    /** @var array<int, TelemetryMode> */
    private array $modesByReferenceId = [];

    /** @var array<string, TelemetryMode> */
    private array $modesByName = [];

    /** @var array<int, true> */
    private array $capabilityReferenceIds = [];

    private bool $sendFeedbackPending = false;
    private readonly SchemaPlanner $schemaPlanner;

    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly Recorder $recorder,
        private readonly Config $config,
        ?SchemaPlanner $schemaPlanner = null,
    ) {
        $this->schemaPlanner = $schemaPlanner ?? new SchemaPlanner($this->config->logger);
        $this->adoptExistingTools();
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        if (
            SendFeedback::TOOL_NAME === $tool->name
            && isset($this->publicTools[$tool->name])
            && $this->isCurrentCapabilityTool($tool->name)
        ) {
            if ($this->config->sendFeedbackExplicit()) {
                throw new \LogicException(self::RESERVED_NAME_MESSAGE);
            }
            // On by default only: the customer's tool replaces the SDK's.
            $current = $this->inner->getTool($tool->name);
            unset($this->capabilityReferenceIds[\spl_object_id($current)]);
        }

        $plan = $this->schemaPlanner->plan(
            $tool->name,
            $tool->inputSchema,
            $tool->description,
            $this->config,
        );
        $publicSchema = TelemetryMode::Scrub === $plan->mode ? $tool->inputSchema : $plan->inputSchema;
        $executionSchema = TelemetryMode::Scrub === $plan->mode
            ? SchemaPlanner::scrubValidationSchema($tool->inputSchema)
            : $plan->inputSchema;
        $description = TelemetryMode::Injected === $plan->mode ? $plan->description : $tool->description;
        $publicTool = TelemetryMode::Injected === $plan->mode
            ? self::copyTool($tool, $publicSchema, $description)
            : $tool;
        $executionTool = self::copyTool($tool, $executionSchema, $description);
        $reference = $this->inner->registerTool($executionTool, $handler);

        $this->publicTools[$tool->name] = $publicTool;
        $this->modesByReferenceId[\spl_object_id($reference)] = $plan->mode;
        $this->modesByName[$tool->name] = $plan->mode;
        $this->recorder->setToolTelemetryMode($tool->name, $plan->mode);

        return $reference;
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        if ($this->inner->hasTool($name)) {
            $reference = $this->inner->getTool($name);
            unset(
                $this->modesByReferenceId[\spl_object_id($reference)],
                $this->capabilityReferenceIds[\spl_object_id($reference)],
            );
        }
        unset($this->publicTools[$name], $this->modesByName[$name]);
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        $this->ensureSendFeedback();

        return $this->inner->hasTools();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->ensureSendFeedback();
        $page = $this->inner->getTools($limit, $cursor);
        $tools = [];
        foreach ($page->references as $key => $item) {
            $tools[$key] = $item instanceof Tool
                ? ($this->publicTools[$item->name] ?? $item)
                : $item;
        }

        return new Page($tools, $page->nextCursor);
    }

    public function getTool(string $name): ToolReference
    {
        $this->ensureSendFeedback();

        return $this->inner->getTool($name);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(
        string $uri,
        bool $includeTemplates = true,
    ): ResourceReference|ResourceTemplateReference {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }

    public function telemetryMode(ToolReference $reference): TelemetryMode
    {
        return $this->modesByReferenceId[\spl_object_id($reference)]
            ?? $this->modesByName[$reference->tool->name]
            ?? TelemetryMode::Injected;
    }

    /**
     * Queue the SDK-owned send_feedback tool. It is on by default whenever a
     * delivery path is configured and off with sendFeedback: false. On by
     * default, a customer tool already named send_feedback wins; explicitly
     * enabled, that collision throws. No other tool's description mentions
     * it.
     */
    public function registerSendFeedback(): void
    {
        if (!$this->config->sendFeedbackEnabled()) {
            return;
        }
        $this->sendFeedbackPending = true;
    }

    /**
     * @deprecated Use registerSendFeedback()
     */
    public function registerRequestCapability(): void
    {
        $this->registerSendFeedback();
    }

    private function ensureSendFeedback(): void
    {
        if (!$this->sendFeedbackPending) {
            return;
        }
        if ($this->inner->hasTool(SendFeedback::TOOL_NAME)) {
            if ($this->config->sendFeedbackExplicit()) {
                // Stay pending so every later lookup reports the collision too.
                throw new \LogicException(self::RESERVED_NAME_MESSAGE);
            }
            $this->sendFeedbackPending = false;

            return;
        }
        $this->sendFeedbackPending = false;

        $annotations = SendFeedback::annotations();
        $tool = new Tool(
            name: SendFeedback::TOOL_NAME,
            title: null,
            inputSchema: SendFeedback::inputSchema(),
            description: SendFeedback::DESCRIPTION,
            annotations: new ToolAnnotations(
                title: $annotations['title'],
                readOnlyHint: $annotations['readOnlyHint'],
                destructiveHint: $annotations['destructiveHint'],
                idempotentHint: $annotations['idempotentHint'],
                openWorldHint: $annotations['openWorldHint'],
            ),
        );
        $handler = static function (string $capability): CallToolResult {
            if (!SendFeedback::isValidCapability($capability)) {
                return CallToolResult::error([
                    new TextContent(SendFeedback::INVALID_CAPABILITY_MESSAGE),
                ]);
            }

            return CallToolResult::success([
                new TextContent(SendFeedback::ACKNOWLEDGEMENT),
            ]);
        };
        $reference = $this->inner->registerTool($tool, $handler);
        $this->publicTools[$tool->name] = $tool;
        $this->modesByReferenceId[\spl_object_id($reference)] = TelemetryMode::Injected;
        $this->modesByName[$tool->name] = TelemetryMode::Injected;
        $this->capabilityReferenceIds[\spl_object_id($reference)] = true;
        $this->recorder->setToolTelemetryMode($tool->name, TelemetryMode::Injected);
    }

    public function isCapabilityRequest(ToolReference $reference): bool
    {
        return isset($this->capabilityReferenceIds[\spl_object_id($reference)]);
    }

    private function isCurrentCapabilityTool(string $name): bool
    {
        if (!$this->inner->hasTool($name)) {
            return false;
        }

        return $this->isCapabilityRequest($this->inner->getTool($name));
    }

    private function adoptExistingTools(): void
    {
        foreach ($this->inner->getTools()->references as $item) {
            if (!$item instanceof Tool || isset($this->publicTools[$item->name])) {
                continue;
            }
            $reference = $this->inner->getTool($item->name);
            $this->registerTool($item, $reference->handler);
        }
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    private static function copyTool(
        Tool $tool,
        array $inputSchema,
        ?string $description,
    ): Tool {
        return new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: self::toolInputSchema($inputSchema),
            description: $description,
            annotations: $tool->annotations,
            icons: $tool->icons,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }

    /**
     * @param array<string, mixed> $inputSchema
     *
     * @return array{
     *   type: 'object',
     *   properties: array<string, mixed>,
     *   required: array<string>
     * }
     */
    private static function toolInputSchema(array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        $required = $inputSchema['required'] ?? null;

        $normalized = $inputSchema;
        $normalized['type'] = 'object';
        $normalized['properties'] = \is_array($properties) ? $properties : [];
        $normalized['required'] = \is_array($required)
            ? \array_values(\array_filter($required, \is_string(...)))
            : [];

        return $normalized;
    }
}
