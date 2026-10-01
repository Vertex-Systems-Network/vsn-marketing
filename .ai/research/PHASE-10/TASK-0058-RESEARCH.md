# TASK-0058 Research Pack

- researched_at: 2026-10-01T21:45:00Z
- task: TASK-0058; phase: PHASE-10
- baseline: 2fb294fdd4f9bce718fb436aa5b1b8901426b285
- scope: registered specialized runtime, immutable prompts, evaluation and independent promotion
- researcher: Supervisor

## Sources
| Source | Type/version | Accessed | Implementation impact |
|---|---|---|---|
| https://developers.openai.com/api/docs/guides/prompting | Official API/current | 2026-10-01 | Code-managed versioned prompts avoid dependence on retiring reusable prompt objects. |
| https://developers.openai.com/api/docs/guides/agent-evals | Official API/current | 2026-10-01 | Repeatable datasets and trace grading support candidate comparisons and regressions. |
| https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html | Official security/current | 2026-10-01 | Separate trust domains; impose tool-chain, retry and cost limits; independently bind approval to candidate parameters. |

## Current external reality and workflow
Prompt content is application code. Operators must compare a candidate with pinned dataset/schema/tool/prompt versions before approving; preserve the previous evaluated version for rollback. Server-owned registries hold prompt bytes, hashes and evaluation definitions. Provider prompt IDs and visual workflow products are not prerequisites. Runtime gets authenticated context from the existing assembler, keeps external text in untrusted input and only releases validated proposals.

## Security/privacy and constraints
CONFIRMS_PLAN: registered agent/tools/scopes, immutable prompt and schema versions, independent permission and risk checks. Agent output cannot select a different prompt, modify evaluation thresholds or approve promotion. Workspace, brand, customer and run scopes stay exact; no inherited context across agent runs. Runtime is bounded to a server-selected finite proposal sequence with total cost accounted by the gateway; no recursion, arbitrary tool execution, background retry or self-modification. A retry is a new explicitly bounded step, never an unchecked loop. Side effects still pass through TASK-0057 typed executor.

## Performance/reliability and evaluation
Offline contract fixtures prove policy/schema behavior only. Live semantic quality, model latency, provider cost and production canaries require separately captured provider evidence. A passing synthetic fixture cannot enable a production route or count as measured model quality. Promotion must verify every required pinned suite and independent reviewer plus release mode; offline acceptance is restricted to offline execution. No concrete provider adapter or credentials are added.

## Conflict and decision impact
CONFIRMS_PLAN: implement the runtime and evaluation/promotion mechanism without changing module boundaries. Existing planned agents remain unavailable until they have a supported pinned prompt/schema/evaluation configuration; no blanket declaration that all specialists or live routes are active. No roadmap criterion is removed. Production provider evidence is explicitly pending rather than fabricated.

## Rejected options and freshness
Reject mutable prompts, model supplied approvals/thresholds, unbounded tool/retry loops, global memory, and promoting deterministic contract tests into live quality evidence. Revalidate provider APIs/privacy/price at actual route activation.

## API/platform constraints
No provider account, API scope, SDK, hosted prompt object or new dependency is introduced. The runtime composes the existing bounded provider-neutral gateway and context assembler. Credentials remain external references; region, classification, schema, risk and capabilities retain the gateway boundary. Adapter timeouts and measured provider limits belong to actual activation evidence.

## Market/reference workflow
The operator selects an immutable candidate, runs its pinned corpus, reviews failures and approves an exact candidate/report binding independently. Previous evaluated candidates remain eligible for reviewable rollback. Offline test evidence is visibly separate from live quality evidence.

## Required roadmap extensions
None. The existing runtime/evaluation/promotion acceptance criteria cover these findings. No provider activation or production SLO is inferred.
