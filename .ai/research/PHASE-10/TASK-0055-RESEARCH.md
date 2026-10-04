# TASK-0055 Research Pack — Provider-neutral AI gateway

- researched_at: 2026-10-01T08:35:00+00:00
- task: TASK-0055
- phase: PHASE-10
- researcher: Codex Supervisor
- source baseline: protected main `ce87aca5a4950e65463da91d66a60b052b7b4df1`

## Sources

| Source | Type | Version/date | Accessed | Decision |
|---|---|---|---|
| [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [rate limits](https://developers.openai.com/api/docs/guides/rate-limits), [data controls](https://developers.openai.com/api/docs/guides/your-data) | First-party API | Current pages | 2026-10-01 | Distinguish refusal, incomplete output and schema result; never assume account limits or data retention. |
| [Claude tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview), [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs) | First-party API | Current pages | 2026-10-01 | Normalize tool requests as proposals and separately validate outputs. |
| [Gemini structured outputs](https://ai.google.dev/gemini-api/docs/structured-output), [function calling](https://ai.google.dev/gemini-api/docs/function-calling) | First-party API | Current pages | 2026-10-01 | Schema subset and semantic validation must be adapter-aware; output is not execution authority. |
| [OWASP AI Agent Security](https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html) | Security guidance | Current page | 2026-10-01 | Least privilege, output validation and independent action policy. |

## Current external reality and workflow

API envelopes, model identifiers, prices, limits and retention differ and change. A request declares task class, required capabilities, schema versions, context hash, risk tier, workspace/region policy and budgets. Server-owned route registry filters first, then ranks eligible candidates. Adapter errors cannot alter policy or permit incompatible fallback. Response carries route/version, usage and status without logging raw context. No live provider is configured by this task by default.

## Security/privacy and API constraints

Unknown capability, region, data class, route status, missing credential reference or unsupported schema fails closed. A route cannot inherit data policy or workspace from model output. An adapter does not execute model-requested tools. Fallback re-runs the same eligibility and budget checks. Enforce request, retry and total spend bounds before each invocation. Secrets are resolved only within an enabled provider adapter at runtime.

## Performance/reliability

Budget reservation must be atomic across workers with reconciliation of estimated versus actual usage, including timeout/cancellation. Shared circuit-break state must bound retries. Unit tests may use in-memory fakes, but a production route must remain disabled until durable budget/telemetry storage and account-specific evaluation are proven. No numeric latency, throughput or cost limit is inferred from documentation alone.

## Conflicts, extensions, rejected options and decision impact

The existing AI gateway document is a contract rather than a deployed implementation. `CONFIRMS_PLAN`: TASK-0055 route and fallback design. `NEW_ACCEPTANCE_CRITERION`: durable concurrent budget reservation and explicit incomplete/refusal normalization before activating live routes. `BLOCKER` for production route: no credential, account region/retention, durable spend ledger or representative measurements. Reject hardcoded vendor dispatch, prompt-selected models, cross-region fallback and an in-process-only budget guard. Implement the safe route selection/envelope first and keep network execution fail-closed.

## Freshness risks

Recheck first-party models, prices, rates, retention and credential terms when a live adapter is proposed. TASK-0054 research captures the comparative rubric; this pack narrows the TASK-0055 implementation boundary.
