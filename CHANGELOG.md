# Changelog

## Unreleased

- Add `descriptionLengthLogLevel` (`none`, `debug`, `info` or `warning`, default `warning`) to set the level of the one-time notice for a tool description too long for the full telemetry hint. Without a PSR-3 logger, only warnings reach the PHP error log.
- Word `request_capability` so agents call it. Its description now says it records the request, changes no data and contacts no one, and applies when the agent sends the user to an app, a website or a manual step. The hint's last sentence is now "Call request_capability before you tell the user something can't be done here or has to be done elsewhere." A description ending with the previous sentence is upgraded. The full hint is 45 bytes longer (299). In Claude Code, against tools that send users to their app, Sonnet 5 called it for 38 of 42 unsupported requests, up from 16, and never on supported ones.
- Advertise optional `call_purpose` using the visible task and tool action. Keep `user_intent` and `user_frustration` on the first call after each user message.
- Accept legacy `agent_thinking` and `context` inputs. Keep existing event metadata names. Prefer `call_purpose`, including an explicit empty string.
- Support `call_purpose` in telemetry field maps. Replace exact old SDK description suffixes while preserving customer prose and the UTF-8 byte limit.
- Ask for capability summaries in English, with generic actions and roles, for user requests in every language.

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
