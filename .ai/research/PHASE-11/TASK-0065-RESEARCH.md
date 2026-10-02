# TASK-0065 — bounded optimization proposal research (2026-10-02)

## Current primary sources

- [NIST AI RMF Core](https://airc.nist.gov/airmf-resources/airmf/5-sec-core/): roles for human-AI oversight, documented risks and independent measurement should be defined and monitored. **Acceptance impact:** the proposal author, deterministic evaluator and human reviewer have distinct authorities; a model output cannot state its own approval or factual success.
- [NIST Generative AI Profile](https://nvlpubs.nist.gov/nistpubs/ai/NIST.AI.600-1.pdf): generative output needs documented review, monitoring and proportionate human oversight. **Acceptance impact:** preserve source/context/version/risk and a reversible review record; avoid autonomous campaign execution.
- [OpenAI Evals API reference](https://platform.openai.com/docs/api-reference/evals): evaluation criteria and data-source schemas are explicit and a run evaluates a specified model/output. **Plan confirmation:** use server-computed validation against frozen candidate and binding; no model-supplied `passed` boolean is trusted.
- [Eppo experiment diagnostics](https://docs.geteppo.com/experiment-analysis/diagnostics/): quality checks on experiment data precede a decision. **Downstream gate:** TASK-0065 may approve an offline candidate, but cannot claim winner/lift until TASK-0066's prespecified analysis and data-quality checks.

## Existing implementation and decision

PHASE-10 `AiAgentRuntime` confines routes to offline adapters and bounded gateway budgets; `AiAgentOutputPolicy` validates source IDs and typed output. `AiPromptPromotionGate` and `AiCreativeReviewGate` already reject self review. TASK-0064 pins a workspace-scoped campaign matrix and records exposure/outcome quarantine, without a production eligibility or exposure adapter.

Add a typed, persisted optimization proposal with binding/matrix hash, exact candidate matrix, context/source/prompt/trace hashes, uncertainty/risk and bounded cost evidence. Require an independently verified upstream AI receipt before accepting model provenance; absent verifier denies. A deterministic evaluator checks source membership, exact candidate shape, unchanged control/plan and canonical workspace references. A human reviewer with AI and campaign approval permissions must differ from the proposer and may promote only the exact evaluated hash into an **offline reviewed candidate**. Rollback marks it reverted, preserving evidence. Neither review nor rollback changes an active experiment, campaign schedule or provider route. No statistical winner is asserted.

Classification: confirms planned TASK-0065 scope, strengthens AC-2 and AC-3 with exact report/hash and budget provenance. Live model integration and campaign activation remain separate authority and evidence gates.
