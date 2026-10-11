# TASK-0092 — Provider-Like Typed Tool/Queue Failure Regression

Status: **staged synthetic red-team AC-2/AC-1 candidate; not certified, no task closure**.

The canonical typed-tool executor already uses a trusted registry and typed schemas, separate authorization and exact actor/argument-bound approval, idempotent execution, and a reversible-write postcondition/rollback contract. This test stages an adversarial failure fixture that imitates a queue/provider exception **without contacting any actual provider**.

Test the safety contract:

- Self-stated `model-self-approved` cannot authorize a reversible write.
- A provider-like handler exception containing a fake sensitive credential never appears in returned error text, audit evidence, or a successful execution result. An attempted local rollback occurs.
- If the simulated rollback also throws with a second fake secret, the executor must report only the generic `AI tool rollback requires operator recovery.` condition. It **must not** record provider recovery, a verified external side effect, or a successful rollback.
- The audit stream remains workspace-scoped and contains only normalized and bounded failure information. No real secrets are used in fixtures.

This demonstrates deterministic error redaction and operator escalation in a self-contained stub; it does not certify durable production provider retries, queue re-delivery, partial remote effects, fraud/legal compliance, global stop timing, multi-worker rate limit, or real recovery success. Those remain separately scoped tasks and human/provider evidence requirements. Require full exact-head and resulting-main CI before any promotion.

No production sending, publishing, campaign billing, provider action, external rollback, or auto-promotion is authorized.
