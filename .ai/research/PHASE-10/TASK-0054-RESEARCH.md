# TASK-0054 Research Pack — AI Gateway and Specialized Marketing Agents

- researched_at: 2026-10-01T08:00:00+00:00
- task: TASK-0054
- phase: PHASE-10
- scope: provider-neutral model routing, structured outputs, tools, privacy, pricing, context, media and agent safety
- researcher: Codex Supervisor
- source baseline: protected main `99eec5f5fc2bcfa0ace8616a432c9e341bc391d7`

## Sources

| Source | Type | Version/date | Accessed | Implementation impact |
|---|---|---|---|---|
| [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs) | First-party API guide | Current page | 2026-10-01 | Schema-constrained results still require domain, authorization and policy validation. |
| [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data) | First-party API guide | Current page | 2026-10-01 | Endpoint-specific retention/data controls need an explicit route policy, not a global assumption. |
| [OpenAI rate limits](https://developers.openai.com/api/docs/guides/rate-limits) and [pricing](https://developers.openai.com/api/docs/pricing) | First-party API guides | Current pages | 2026-10-01 | Limits and prices vary by account/model and change; route budget and capacity need live account evidence. |
| [Claude tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview) and [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs) | First-party API guides | Current pages | 2026-10-01 | Provider tool-call and output envelopes differ; normalize into typed proposal and validated result contracts. |
| [Gemini structured outputs](https://ai.google.dev/gemini-api/docs/structured-output), [function calling](https://ai.google.dev/gemini-api/docs/function-calling) and [pricing](https://ai.google.dev/gemini-api/docs/pricing) | First-party API guides | Structured output page updated 2026-09-23 | 2026-10-01 | JSON Schema support is a subset; syntactic JSON does not prove semantic safety. Tool calls are proposals requiring app execution. |
| [OWASP LLM prompt injection](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html) and [AI Agent Security](https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html) | Security guidance | Current pages | 2026-10-01 | Treat retrieved content/tool responses as untrusted, enforce least privilege and independent action checks. |

## Current external reality

Provider APIs offer structured responses and tool-call interfaces with different schema subsets and envelope semantics. They do not transfer authority to execute a tool. Refusal, truncation, timeout, invalid JSON, schema-conforming but semantically invalid IDs, provider errors and partial usage reports must be separate statuses. Model identifiers, pricing, limits, retention eligibility and media capabilities are mutable; no live account, regional entitlement, rate-limit, billing or representative latency evidence was captured in this research-only carrier.

## Market/reference workflow

The product flow is an operator-authorized marketing intent → registered agent/task class → workspace policy and minimized context manifest → eligible provider route → validated proposal → independent deterministic policy/tool gate → audited candidate → optional human promotion. A model response never proves contact consent, permission, workspace membership, campaign readiness or sending authority.

## Security/privacy findings

- Route selection must bind workspace policy, data classification, region/retention requirements, registered capabilities and budget before provider invocation. Unknown or incompatible routes fail closed.
- Context assembly derives workspace from authenticated server context. Brand and customer data need separate scopes, provenance, expiry, deletion and access controls. Raw secrets, unrelated customer rows, hidden prompt data and sensitive attributes do not enter prompts by default.
- Tool calls are untrusted proposals. Server-owned schemas, registered tool IDs, argument validation, authenticated user/workspace permission, risk tier, approval and idempotency control execution. Retrieved text cannot add tools or weaken policy.
- Provider fallback inherits the original data policy, schema, risk tier, budget and approvals. Cross-region or lower-policy fallback is denied even during an outage.
- Log route ID/version, prompt and schema versions, context manifest hash, normalized usage/cost, trace and validation status. Avoid raw sensitive prompt/response logging.

## API/platform constraints

An adapter must normalize provider differences for structured output, tool calls, usage, finish/refusal/error, cancellation and retryability. Start with a deny-by-default offline route and deterministic fake adapters for contract tests. Live routes require separately provisioned credential references and region/retention evaluation. Media generation requires its own rights, provenance and safety reviews; it is not implied by a text-capable route.

## Performance/reliability findings

Use bounded request time, retry count, total tokens, output size and spend. Circuit breakers and workspace budget reservations must prevent concurrent overspend; reconcile estimated with actual provider usage. Define a repeatable eval fixture and route score by task class: validity, policy violations, task quality, cost and latency distributions, error/refusal and fallback behavior. Candidate routes cannot be promoted on a fabricated benchmark or one response. Live numeric SLOs remain unapproved until representative measurements and account limits exist.

### Reproducible candidate rubric

| Gate | Evidence required before an enabled route | Failure handling |
|---|---|---|
| Capability | Pinned model/API version, schema/tool/media capability contract and recorded provider response | Reject unsupported route; never coerce an unsafe output |
| Data policy | Account-specific retention and processing region, classified fixture manifest, approved credential reference | Deny request/fallback when a requirement is unknown |
| Validity and quality | Versioned task-class golden set, schema and semantic validation, reviewer-rated task utility | Quarantine failed candidates; no self-promotion |
| Safety | Injection, unauthorized tool, cross-workspace and leakage cases with deterministic policy outcomes | Fail closed and audit without recording sensitive payloads |
| Reliability | Repeated seeded calls, timeout/error/refusal/truncation rates and fallback compatibility | Circuit break bounded route; preserve risk and data policy |
| Cost and latency | Per-request normalized usage, quoted list price captured with date, p50/p95 distribution and account limits | Budget reservation; no numeric production SLO until measured |

Route candidates are OpenAI, Anthropic and Google adapters for later account-specific evaluation, not ranked or activated by this pack. The offline adapter is the only eligible development route until every gate has evidence.

## Conflicts with current assumptions

The preplanned AI registries are declarations, not a functioning gateway or proof of active agents. No production provider adapter, credential, billing or media-rights approval is established. Existing `AI-GATEWAY.md` is a contract, not implementation evidence. Phase-09 synthetic no-op RBT-052 must not be reused as an AI provider benchmark.

## Required roadmap extensions

Register TASK-0054 through TASK-0061 in dependency order. TASK-0055 must establish route policy and offline adapter; TASK-0056 isolates context; TASK-0057 owns executable typed tool authorization; TASK-0058 integrates only registered agents; TASK-0059 independently gates media; TASK-0060 red-teams all boundaries; TASK-0061 requires exact-head and resulting-main evidence. Preserve a live-provider evaluation gate before production route activation; it may remain unapproved while offline product architecture is tested.

## Rejected options

- Hardcoding a vendor/model in core journeys or accepting a model-provided workspace ID.
- Treating schema-valid output as authorization, letting the model execute tools directly, or using raw model SQL.
- Transparent fallback across incompatible region/retention/capability policies.
- Publishing cost, latency or provider quality numbers without account-specific runs and raw evidence.

## Decision impact

`CONFIRMS_PLAN`: tasks TASK-0054 through TASK-0061 and existing gateway/context/tool contracts. `NEW_ACCEPTANCE_CRITERION`: offline-by-default route, refusal/truncation semantics, budget reservation and fallback policy equivalence. `BLOCKER` for live-provider certification: credential references, account region/retention and measured usage/latency are absent. This does not block research or deterministic offline implementation. `ADR_REQUIRED` for any contract/architecture change from these canonical boundaries.

## Freshness risks

Provider model IDs, prices, limits, data terms, schema support and media rights must be checked again immediately before implementing or enabling an adapter. The research pack records current docs, not a permanent vendor recommendation or a live route certification.
