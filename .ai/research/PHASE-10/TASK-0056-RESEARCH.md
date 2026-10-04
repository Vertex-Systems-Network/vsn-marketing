# TASK-0056 Research Pack — isolated AI context and memory

- researched_at: 2026-10-01T16:10:00+00:00
- task: TASK-0056; phase: PHASE-10
- source baseline: protected main `6164490d180e2c83da2983fdd093d0e3ed8220cf`

## Current sources and decision impact

| Source | Accessed | Decision |
|---|---|---|
| [OpenAI API data controls](https://developers.openai.com/api/docs/guides/your-data) | 2026-10-01 | Retention and residency vary by account and endpoint. Do not infer a universal zero-retention guarantee; keep provider routing closed until account-specific policy is verified. |
| [Claude API retention](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention) | 2026-10-01 | Do not confuse a platform/cache feature with permission to send customer memory; explicit scope and expiry remain required. |
| [Gemini data logging policy](https://ai.google.dev/gemini-api/docs/logs-policy), [ZDR](https://ai.google.dev/gemini-api/docs/zdr) | 2026-10-01 | Region and privacy capability must be verified at route activation, not inferred from a model name. |
| [OWASP prompt injection prevention](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html) | 2026-10-01 | Retrieved text is untrusted data. Its contents cannot override instructions, grant tool access or select a provider. |

## Boundary and implementation decision

Assemble a deterministic context manifest from authenticated `TenantContext`, server-authorized source records and their revision, permission, provenance and expiry. A source must match workspace and optional brand/customer/run scope exactly. Do not accept model-supplied source IDs or permission fields. Mark untrusted text as data; redact credentials and prohibited contact fields before any adapter envelope. Enforce item/byte limits and expiry; reject stale, deleted, unpermitted or cross-scope sources. Bind the manifest hash to the gateway request, preserving only references and hashes in telemetry.

Memory storage must be workspace keyed, with explicit brand/customer/run scopes, TTL and deletion semantics. A retrieval query cannot broaden scope when a dimension is absent. Prefer a scoped repository contract and deny-by-default runtime binding until real database isolation and adversarial tests pass. No live provider, numeric capacity or privacy certification is claimed by this research.

## Conflicts, extension and freshness

`CONFIRMS_PLAN`: TASK-0056 provenance, redaction and freshness. `NEW_ACCEPTANCE_CRITERION`: absent optional scope must not act as wildcard for customer/run memory. `BLOCKER` for live model dispatch: account-specific retention/residency and permitted field categories are unverified. Recheck provider terms before enabling a route. Reject prompt-based permission filtering and raw secret/PII inclusion, even if a provider offers a retention control.
