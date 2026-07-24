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
    "agent_thinking": "The payment lookup tool provides the requested status",
    "user_frustration": "low"
  }
}
```

The SDK removes this field before the customer handler runs. All fields are
optional. Agents should include `agent_thinking` on each call and include
`user_intent`/`user_frustration` only on the first call after a new user
message.

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

## Requesting missing capabilities

When a delivery path is configured, the SDK adds an uninstrumented
`request_capability` tool. Agents can use it to report unmet demand when no
existing tool can complete the request.

Set `requestCapability: false` to disable it. With the default setting, a
customer tool of the same name wins. With `requestCapability: true`, a
collision throws during `build()` so the configuration cannot silently drift.

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
  `request_capability` is not registered.
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
