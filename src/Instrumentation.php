<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Adapter\Official\AnalyticsHttpMiddleware;
use Armature\McpAnalytics\Adapter\Official\InstrumentedReferenceHandler;
use Armature\McpAnalytics\Adapter\Official\InstrumentedRegistry;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use Armature\McpAnalytics\Contract\FeedbackInstructions;

final class Instrumentation
{
    private readonly AnalyticsHttpMiddleware $httpMiddleware;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly InstrumentedRegistry $registry,
        private readonly InstrumentedReferenceHandler $referenceHandler,
        RequestContextStore $requestContextStore,
        private readonly bool $feedbackInstructions = false,
    ) {
        $this->httpMiddleware = new AnalyticsHttpMiddleware(
            $requestContextStore,
            $this->feedbackInstructions ? $this->servesFeedbackInstructions(...) : null,
        );
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

    /**
     * For servers that answer HTTP without httpMiddleware(), such as
     * symfony/mcp-bundle: pass the raw JSON-RPC request and response bodies
     * of a POST to the MCP endpoint. Returns the response body with the
     * send_feedback sentence added to the server instructions when the
     * request is Claude Code's `initialize` or `server/discover` call, or
     * null to send the response unchanged.
     */
    public function withFeedbackInstructions(string $requestBody, string $responseBody): ?string
    {
        if (!FeedbackInstructions::applies($requestBody) || !$this->servesFeedbackInstructions()) {
            return null;
        }

        return FeedbackInstructions::rewrite($requestBody, $responseBody);
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

    private function servesFeedbackInstructions(): bool
    {
        return $this->feedbackInstructions && $this->registry->servesSendFeedback();
    }
}
