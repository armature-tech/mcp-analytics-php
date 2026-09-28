# Changelog

## Unreleased

- Built-in secret redaction now catches AWS secret access keys written after
  their name (`AWS_SECRET_ACCESS_KEY=…`, `aws_secret_access_key = …`,
  `SecretAccessKey: …`) and replaces them with
  `[redacted:aws-secret-access-key]`, per the updated shared contract. Only
  the access key ID was caught before.
- The per-tool telemetry description hint now also points agents at
  `request_capability` when it is enabled for the configuration, so agents
  are told when no tool fits. Descriptions in configurations where
  `request_capability` is disabled keep the current, byte-identical hint.
  The hint is still idempotent: a description already carrying any
  recognized hint, in full or in part, is left unchanged. A new 1024
  UTF-8-byte length guard now keeps the SDK from ever growing a
  description past that limit: it appends the full hint when it fits,
  falls back to just the telemetry sentence when only that fits (logging a
  one-time warning per tool), and otherwise leaves the description
  unchanged (also with a one-time warning); the description itself is
  never truncated and telemetry collection is unaffected either way.

## 0.1.0

- Initial PHP SDK for the official `mcp/sdk` 0.7.x server.
- Registry and reference-handler instrumentation for manual, explicit,
  discovered, and custom-loader tools.
- Injected, customer-owned, and capture-off scrub telemetry modes.
- Schema-version-1 events, actor/session identity, privacy controls, bounded
  queueing, retries, and in-body ingest rejection handling.
- Stdio and Streamable HTTP support with PSR-15 request attribution.
- SDK-owned `request_capability` tool and collision policy.
