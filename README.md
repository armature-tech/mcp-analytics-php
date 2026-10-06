# Armature MCP Analytics for PHP

Understand which MCP tools agents use, what users are trying to accomplish,
and where calls fail—without building an observability pipeline.

[Armature](https://armature.tech) ·
[TypeScript SDK](https://github.com/armature-tech/mcp-analytics) ·
[Python SDK](https://github.com/armature-tech/mcp-analytics-python) ·
[Go SDK](https://github.com/armature-tech/mcp-analytics-go) ·
[Agent install](SKILL.md)

This package integrates with the official experimental PHP MCP SDK,
`mcp/sdk` 0.7.x, on PHP 8.1 and newer.

## Install

```bash
composer require armature/mcp-analytics
```

Create a server in the Armature dashboard, then copy **both** generated
environment values:

```bash
export ANALYTICS_INGEST_API_KEY="..."
export ANALYTICS_INGEST_URL="https://app.armature.tech/api/mcp-analytics/ingest"
```

EU accounts must use:

```bash
export ANALYTICS_INGEST_URL="https://eu.armature.tech/api/mcp-analytics/ingest"
```

Optional only for US: `ANALYTICS_INGEST_URL` can be omitted because the SDK
defaults to the US endpoint. Required for EU: the URL is required and must be:
`https://eu.armature.tech/api/mcp-analytics/ingest`. Never commit a real ingest
key.

## Instrument a stdio server

Call `Analytics::instrument()` before adding or discovering tools:

```php
<?php

declare(strict_types=1);

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require __DIR__.'/vendor/autoload.php';

$builder = Server::builder()
    ->setServerInfo('Customer MCP', '1.0.0');

$analytics = Analytics::instrument(
    builder: $builder,
    config: Config::fromEnvironment(),
);

$builder->addTool(
    handler: static fn (string $customerId): array => [
        'customer_id' => $customerId,
        'status' => 'active',
    ],
    name: 'lookup_customer',
    description: 'Look up a customer.',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'customerId' => ['type' => 'string'],
        ],
        'required' => ['customerId'],
    ],
);

$server = $builder->build();

try {
    $server->run(new StdioTransport());
} finally {
    $analytics->close();
}
```

The instrumentation covers manual tools, explicit `Builder::add(...)`
definitions, custom loaders, and attribute discovery because it decorates the
official registry after definitions are finalized.

The SDK also adds a `send_feedback` tool ([details](#feedback-tool-send_feedback)).
To turn it off:

```php
$analytics = Analytics::instrument(
    builder: $builder,
    config: Config::fromEnvironment(sendFeedback: false),
);
```

`mcp/sdk` 0.7 accepts closures, class/method pairs, and invokable class strings
(not invokable object instances). Adapt a bare named function or invokable
object with `Closure::fromCallable(...)` before passing it to `addTool()`.

## Streamable HTTP

Add the analytics PSR-15 middleware after authentication middleware and keep
the official transport defaults. If the application does not already provide
PSR-17 factories and a PSR-7 server-request creator, install one implementation:

```bash
composer require nyholm/psr7 nyholm/psr7-server
```

For example:

```php
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))
    ->fromGlobals();

$transport = new StreamableHttpTransport(
    request: $request,
    responseFactory: $factory,
    streamFactory: $factory,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        $authenticationMiddleware,
        $analytics->httpMiddleware(),
    ],
);

try {
    $response = $server->run($transport);
} finally {
    $analytics->flush();
}
```

The middleware retains only the request headers and scalar authentication
attributes needed for attribution. It never reads the request body or changes
the request/response.

Use awaited delivery for PHP-FPM, Lambda, and other request-scoped runtimes.
This is the default. Deferred delivery is accepted only with an explicit
`SchedulerInterface` that guarantees the task will run:

```php
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\DeliveryMode;

$config = new Config(
    delivery: DeliveryMode::Deferred,
    scheduler: $hostLifecycleScheduler,
);
```

The SDK does not call `fastcgi_finish_request()` and does not pretend work will
survive after a request unless the host provides that guarantee.

## How telemetry reaches tools

For a normal tool, the public input schema receives an optional top-level
`telemetry` object:

```json
{
  "telemetry": {
    "user_intent": "Check whether the customer's last payment succeeded",
    "call_purpose": "The payment lookup tool provides the requested status"
  }
}
```

The SDK removes this field before the customer handler runs. Both fields are
optional. Their parameter descriptions ask for `call_purpose` on each call and
`user_intent` only on the first call after a new user message. The SDK adds no
text to tool descriptions.

Pass a PSR-3 logger as `logger: $logger` to route configuration warnings
through the application's logging stack. Without one, the SDK uses the PHP
error log. Warning context never includes tool arguments or credentials.

There are three ownership modes:

| Mode | Public schema | Customer handler | Armature export |
| --- | --- | --- | --- |
| Injected | SDK adds optional telemetry | Telemetry removed | Captured |
| Owned | Customer already declares telemetry | Untouched | Not captured |
| Scrub | Capture disabled; public schema unchanged | Cached telemetry removed | Not captured |

Scrub mode uses a private validation schema so stale clients can still send
their cached telemetry field even when a strict customer schema has
`additionalProperties: false`.

Disable all conversation-derived capture while keeping safe handler cleanup
with:

```php
$config = new Config(captureTelemetry: false);
```

## Compatibility with earlier telemetry fields

Tools advertise `call_purpose` as a short public description of the action. It uses only the visible request and the tool function. Both `user_intent` and `call_purpose` use generic terms for names, document titles, teams, filters and other tool argument values. The SDK continues to accept `agent_thinking` and `context` from cached clients. `call_purpose` takes precedence, including an explicit empty string. Events keep the existing `agent_thinking` and `context` metadata keys so stored analytics remain compatible.

`user_frustration` and its older spelling `frustration_level` are no longer advertised or exported. A cached client that still sends them has them removed with the rest of `telemetry`; no event, `emit` call or `onError` batch carries them.

Earlier releases appended a telemetry hint, and optionally a `request_capability` sentence, to every tool description. The SDK now removes that exact trailing hint when it finds one, so descriptions registered through an older wrapper come out clean. Customer prose that quotes a hint is kept.

The telemetry field map accepts `call_purpose` and the previous `agent_thinking` key. A `user_frustration` key is accepted and ignored. Explicit telemetry takes precedence over mapped arguments. Refresh the MCP connection after upgrading so the client loads the new tool schemas.

## Privacy and delivery

Before transmission, the SDK:

1. traverses values within a bounded budget;
2. removes MCP image/audio/blob payloads and large base64 strings;
3. redacts high-confidence credentials and sensitive field names;
4. applies an optional field-level `redact` callback;
5. applies an optional whole-event `redactEvent` callback;
6. serializes and truncates previews on valid UTF-8 boundaries.

Inputs and result previews are bounded to 8 KiB; script source is bounded to
32 KiB. The in-memory queue holds at most 1,000 candidates and emits batches of
20. The network client uses a five-second timeout and at most two attempts,
separated by 100 ms. Only connection failures, timeouts, HTTP 429, and HTTP 5xx
are retried.

Analytics delivery and callbacks never fail a customer tool. Use `onError`
only for payload-free operational diagnostics. For cross-language API parity,
the second callback argument is the already finalized, sanitized batch; do not
log or re-export it:

```php
$config = new Config(
    onError: static function (Throwable $error, array $batch): void {
        // Log the safe error code/class. Do not log the batch.
    },
);
```

SDK delivery failures use `DeliveryError`, whose public diagnostics are
`errorCode`, `status`, `retryable`, `attempts`, and `causeClass`. The original
exception object and message are deliberately not chained into it.

## Actor identity

Only a SHA-256 actor id is sent by default. Seed precedence is:

1. configured `actorIdentifier` (also emits a bounded identity event);
2. configured `actorId`;
3. authenticated principal request attributes;
4. the Authorization header;
5. `anonymous`.

```php
$config = new Config(
    actorIdentifier: static fn (array $context): ?string =>
        $context['attributes']['principal_id'] ?? null,
);
```

Do not use telemetry as an authentication or authorization boundary.

## Feedback tool (send_feedback)

When a delivery path is configured, the SDK adds an uninstrumented feedback
tool, `send_feedback` (named `request_capability` in earlier releases). Agents
call it when the server's tools cannot do what the user asked. Calls are
recorded as `tool_call` events with `metadata.capability_request: true`.

It is on by default. Turn it off with `sendFeedback: false`:

```php
$config = Config::fromEnvironment(sendFeedback: false);
// or
$config = new Config(apiKey: $key, sendFeedback: false);
```

The earlier `requestCapability` setting is still accepted as a deprecated
alias; when both are set, `sendFeedback` wins.

The tool declares the annotations app directories such as ChatGPT's require:
`readOnlyHint: false` (it records an analytics event), `destructiveHint: false`
(it changes no user data) and `openWorldHint: false` (it contacts no one), plus
`idempotentHint: false` and the title "Send feedback".

With the default setting, a customer tool already named `send_feedback` wins
and the SDK skips its own. With `sendFeedback: true`, that collision throws
during `build()` so the configuration cannot silently drift.

No other tool's description mentions `send_feedback`. If the server is listed
in a connector directory and keeps the tool, mention it in the listing as a
feedback tool.

`descriptionLengthLogLevel` is deprecated. It is still accepted and ignored:
nothing is appended to descriptions, so there is no length notice.

## Existing custom registry, handler, or container

The official builder has setters but no corresponding getters. If the
application already uses custom instances, pass the same instances when
instrumenting:

```php
$analytics = Analytics::instrument(
    builder: $builder,
    config: $config,
    container: $container,
    registry: $registry,
    referenceHandler: $referenceHandler,
);
```

Supplying a custom registry makes `mcp/sdk` 0.7 load builder loaders eagerly
during `build()`. Armature also reads and re-registers tools already present
in a supplied registry during `Analytics::instrument()` so they cannot bypass
the wrapper. Those operations may therefore change lazy loading to eager
loading. Loader failures remain visible and are not hidden.

## Low-level recorder

Framework adapters can use `Recorder` without importing the official MCP SDK:

```php
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Recorder;

$recorder = new Recorder(new Config());

$result = $recorder->instrumentToolCall(
    name: 'lookup_customer',
    arguments: $arguments,
    handler: static fn (mixed $cleanArguments): mixed =>
        lookupCustomer($cleanArguments),
    sessionId: $sessionId,
);

$recorder->close();
```

Set `requestId` only for a genuine idempotency key. Never pass a connection-
local JSON-RPC counter; the SDK mints a unique per-call id automatically.

## Verify

Run the package checks:

```bash
composer check
```

Against a running Streamable HTTP server, the language-independent doctor can
verify the MCP schema and ingest authentication:

```bash
npx @armature-tech/mcp-analytics doctor --url http://localhost:3000/mcp
```

Use `--skip-ingest` for an offline schema check and `--json` for
machine-readable output.

## Troubleshooting

- **No events arrive:** confirm `ANALYTICS_INGEST_API_KEY` is present in the
  server process and that `ANALYTICS_INGEST_URL` matches the Armature region.
  With no API key or custom emitter, the recorder is intentionally a no-op and
  `send_feedback` is not registered.
- **Delivery reports an error:** inspect only the safe `DeliveryError` fields
  in `onError`; do not log its batch argument. HTTP 401/403 errors are not
  retried. HTTP 429, HTTP 5xx, timeouts, and connection failures receive one
  retry.
- **Discovery runs earlier than before:** a supplied custom registry forces
  official `mcp/sdk` 0.7 loaders to finalize during `build()`. Fix the loader
  failure itself; the wrapper deliberately does not hide it.
- **Composer rejects the dependency:** this release supports PHP 8.1+ and
  `mcp/sdk >=0.7.0 <0.8.0`. Upgrade or constrain the application explicitly;
  do not bypass Composer's platform or version checks.
- **Streamable HTTP cannot find a response factory:** keep the application's
  existing PSR-17 implementation or install the Nyholm packages shown above,
  then pass the factories explicitly.

## License

Apache-2.0.
