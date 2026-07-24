---
name: install-armature-mcp-analytics-php
description: >
  Install and verify the armature/mcp-analytics Composer package in an
  official PHP MCP SDK server. Use when adding Armature analytics to a PHP MCP
  server that uses mcp/sdk 0.7.x, including stdio and Streamable HTTP servers.
---

# Install Armature MCP Analytics in a PHP MCP server

Integrate `armature/mcp-analytics` without changing customer tool signatures,
schemas, outputs, error behavior, authentication, or transport security.

## 1. Confirm the server shape

Search for:

- `Mcp\Server`, `Server::builder()`, or `Mcp\Server\Builder`;
- `addTool`, `Builder::add`, MCP attributes, or registry loaders;
- `StdioTransport` or `StreamableHttpTransport`;
- an existing PSR-11 container, custom `RegistryInterface`, or custom
  `ReferenceHandlerInterface`.

This release supports the official `mcp/sdk` 0.7.x package. Stop and explain
the incompatibility if the project uses another PHP MCP implementation. Do not
patch framework internals.

If there are multiple MCP servers, ask which server is in scope.

## 2. Install with the existing Composer workflow

```bash
composer require armature/mcp-analytics
```

Preserve the repository's lock-file policy. The package supports PHP 8.1+ and
pins the official MCP SDK below 0.8.

## 3. Configure the correct Armature region

Add placeholders—not real secrets—to the project's environment documentation:

```dotenv
ANALYTICS_INGEST_API_KEY=
ANALYTICS_INGEST_URL=https://app.armature.tech/api/mcp-analytics/ingest
```

EU accounts require:

```dotenv
ANALYTICS_INGEST_URL=https://eu.armature.tech/api/mcp-analytics/ingest
```

Optional only for US: the URL may be omitted because the SDK defaults to the
US endpoint. Required for EU, and must be:
`https://eu.armature.tech/api/mcp-analytics/ingest`. Never print or commit the
key.

## 4. Instrument before tool registration

```php
use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;

$analytics = Analytics::instrument(
    builder: $builder,
    config: Config::fromEnvironment(),
);
```

Place this before `addTool`, `add`, discovery configuration, and custom
loaders. Always drain on shutdown:

```php
try {
    $server->run($transport);
} finally {
    $analytics->close();
}
```

If the builder already uses a custom container, registry, or reference
handler, pass those same objects to `Analytics::instrument`. The builder has
no getters, so the SDK cannot recover them safely:

```php
$analytics = Analytics::instrument(
    builder: $builder,
    config: $config,
    container: $container,
    registry: $registry,
    referenceHandler: $referenceHandler,
);
```

Do not use reflection to read private builder state.

## 5. Add HTTP middleware when applicable

For Streamable HTTP, retain the official defaults and put analytics after
authentication. Preserve the application's existing PSR-17/PSR-7
implementation. If it has none, install one explicitly:

```bash
composer require nyholm/psr7 nyholm/psr7-server
```

For a Nyholm-based entry point:

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
```

Use the default awaited delivery in PHP-FPM and serverless runtimes. Select
`DeliveryMode::Deferred` only when the host provides a
`SchedulerInterface` that guarantees the closure runs. Do not add
`fastcgi_finish_request()` or an unowned shutdown callback.

## 6. Preserve telemetry ownership

Verify each tool falls into the intended mode:

- injected: SDK adds optional `telemetry`; customer handler never sees it;
- owned: customer already declares top-level `telemetry`; schema and handler
  stay untouched and Armature does not export it;
- scrub: capture is off; public schema stays unchanged and cached telemetry is
  removed before the handler.

Do not rename or take over a customer-owned telemetry field. Use
`telemetryFieldMap` only when the application explicitly wants to export
existing customer fields.

The SDK adds `request_capability` when delivery is configured. Leave it on
unless the user opts out. A customer tool with that name wins by default;
explicit `requestCapability: true` makes a collision a build error.

## 7. Verify with real protocol calls

Run the project's formatting, static analysis, and tests. Then verify:

1. a real `tools/list` response contains optional
   `telemetry.agent_thinking` on an injected tool;
2. a real `tools/call` sends telemetry but the PHP handler receives only its
   original parameters;
3. a mock emitter receives one `session_init` and one `tool_call`;
4. returned values and thrown exceptions retain their original identity;
5. stdio calls share the official process/session id;
6. HTTP calls preserve distinct `Mcp-Session-Id`, client info, authenticated
   principal, and workflow marker values;
7. no Authorization value, binary payload, or known secret appears in emitted
   JSON.

For a running HTTP server also run:

```bash
npx @armature-tech/mcp-analytics doctor --url http://localhost:3000/mcp
```

Use `--skip-ingest` when credentials are unavailable.

## 8. Handoff

Report:

- the builder, transport, and registration paths detected;
- files changed;
- where the key and region URL must be configured;
- whether a custom container/registry/handler was preserved;
- delivery mode and shutdown behavior;
- whether `request_capability` is enabled;
- protocol, schema, handler-cleanup, privacy, and emission checks run;
- the official 0.7 custom-registry constraint: existing tools are adopted
  during instrumentation and builder loaders execute eagerly at `build()`.
