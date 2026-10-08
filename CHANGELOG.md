# Changelog

## Unreleased

- `send_feedback` has a new description: "Use this when the user asks for something these tools can't do. It records the request so the developers of this server can add it. It changes no data and contacts no one." ChatGPT showed a "Suspicious Instruction" warning on the tool call with the previous wording ("Call this before you tell the user that these tools can't do what they asked. … Then answer the user as usual."): it read the description as prescribing when the tool must be called before responding. The new wording says when the tool is useful and no longer orders it against the answer.

- `send_feedback` has a new description that names no other app or website: "Call this before you tell the user that these tools can't do what they asked. It records the request so the developers of this server can add it. It changes no data and contacts no one. Then answer the user as usual." ChatGPT's app review held a server whose `send_feedback` description said to call it when sending the user to an app, a website or a manual step: it read that as telling the model to use another app. Disable the tool with `sendFeedback: false`.
- The SDK no longer adds text to tool descriptions. Exact hint suffixes appended by earlier releases, with or without the `request_capability` sentence, are removed; customer prose that quotes one is kept. A description that was only an SDK hint becomes empty; a tool without a description keeps none. The description-length notices are gone, and `descriptionLengthLogLevel` is deprecated, still accepted and ignored. Anthropic's connector directory review rejected the appended sentences.
- The injected `telemetry` object advertises only `user_intent` and `call_purpose`. `user_frustration` and `frustration_level` sent by cached clients are stripped and never exported, and event metadata no longer carries those keys. A `user_frustration` field map key is accepted and ignored. `SchemaPlanner::USER_FRUSTRATION_DESCRIPTION` is deprecated.
- The SDK-owned `request_capability` tool is renamed `send_feedback`, with the annotation title "Send feedback". It stays on by default whenever a delivery path is configured. **To turn it off, pass `sendFeedback: false`**, for example `Config::fromEnvironment(sendFeedback: false)` (new parameter) or `new Config(..., sendFeedback: false)`. `requestCapability` is a deprecated alias; `sendFeedback` wins when both are set. Its description, `capability` argument and remaining annotations are unchanged, and calls still carry `metadata.capability_request: true`. No alias tool is registered under the old name. By default a customer tool named `send_feedback` wins; with `sendFeedback: true` that collision is a build error. A server listed in a connector directory that keeps it should mention it in the listing as a feedback tool.
- Add `Contract\SendFeedback` (tool name, description, schema, annotations), `Config::sendFeedbackEnabled()`, `Config::sendFeedbackExplicit()` and `InstrumentedRegistry::registerSendFeedback()`. `Config::requestCapabilityEnabled()`, `Config::requestCapabilityExplicit()` and `InstrumentedRegistry::registerRequestCapability()` are deprecated aliases.
- `SchemaPlanner::appendTelemetryHint()` is deprecated and appends nothing; use `SchemaPlanner::removeLegacyHints()`. The hint, length-warning and `MAX_TOOL_DESCRIPTION_LENGTH` constants are deprecated.
- `request_capability` now declares tool annotations: `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: false`, `openWorldHint: false` and the title "Request capability". The ChatGPT app directory holds an app update when a tool lacks explicit `readOnlyHint`, `destructiveHint` and `openWorldHint`.
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
