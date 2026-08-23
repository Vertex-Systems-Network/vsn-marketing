# AI Gateway Contract

The AI Gateway is the only product-layer entry point to model providers.

## Request envelope

A request declares: `agent_id`, `task_class`, `required_capabilities`, `input_schema`, `output_schema`, `context_manifest`, `risk_tier`, `latency_budget`, `cost_budget`, `data_policy`, and `fallback_policy`.

Callers do not select a concrete provider SDK directly.

## Route selection

A route is eligible only when its registered capabilities satisfy every requirement and its workspace/data policy is compatible. Ranking may consider reliability, quality evaluation scores, latency and cost. Ranking weights are configuration, not prompt text.

## Response envelope

Every result records route identity/version, prompt ID/version, schema versions, context manifest hash, normalized usage/cost telemetry, latency, finish status, validation status, and trace ID.

## Fallback

Fallback routes must be capability-compatible. Fallback never relaxes structured-output, tool, privacy, risk, or approval requirements.

## Provider adapters

Concrete AI APIs live behind adapters. Adapters normalize authentication references, requests, streaming, structured outputs, tool-call representations, usage telemetry, rate-limit signals, provider errors and cancellation.

## Security

Raw secrets are not exposed to prompts or agent memory. Provider credentials are resolved by a secret manager at execution time. Sensitive context is minimized/redacted before invocation according to data policy.

## Reliability

Use bounded retries with jitter only for retryable classes. Apply circuit breakers per route/provider. Idempotency must protect side-effectful tool execution independently from model retries.
