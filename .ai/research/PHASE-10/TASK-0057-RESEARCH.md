# TASK-0057 Research — structured proposals and typed tools

- researched_at: 2026-10-01T18:25:00Z
- baseline: abe7df21c195c3d5843555f70c5c1c6f16bd5850
- task: TASK-0057; phase: PHASE-10

## Sources revalidated this session

| Official source | Accessed | Implementation impact |
|---|---|---|
| https://developers.openai.com/api/docs/guides/structured-outputs | 2026-10-01 | Schema conformance does not replace semantic validation. Refusal/incomplete/cancellation are not executable proposals. |
| https://ai.google.dev/gemini-api/docs/function-calling | 2026-10-01 | Tool suggestions are structured requests; the application owns execution. |
| https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html | 2026-10-01 | Narrow allowlists, independent authorization, immutable approval binding, isolated memory and auditable bounded execution. |

## Decision and threat model

CONFIRMS_PLAN: deterministic schema and semantic checks before releasing proposals. Use a deliberately restricted, fail-closed schema dialect; unsupported schema keywords are rejected rather than approximated. No arbitrary code, URL or shell tool. Server-selected known references bind semantic IDs to workspace. Tool definitions pin argument and result schema versions, canonical effect/minimum risk, permission and a handler. Missing handler, unknown registry entry, risk escalation or absent approval denies execution. Approval is verified by an injected server service, never by a model boolean. All calls use durable core idempotency and audit. Replays bind the original arguments and scope, and cannot substitute a new result. No external/production tool is enabled by this task.

## Reliability, market workflow and limits

Return explicit denied/validated/executed envelopes rather than exposing freeform provider output. Store hashes and references in audit. Unknown usage retains gateway reservations. Model confidence never grants permission. Existing providers differ in schema support: adapters must normalize terminal outcomes; no live model quality or provider latency is inferred from offline contract tests. Review before execute is the first operational workflow.

## Rejected choices / freshness

Reject prompt-based approvals, model-controlled permissions, permissive JSON parsing, unknown reference IDs and generic execute tools. Revalidate provider/tool APIs at concrete adapter activation. No new vendor, dependency or architecture boundary introduced.
