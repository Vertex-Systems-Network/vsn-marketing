# TASK-0090 — Offline final-admission review boundary

Status: STAGED only; no exact-head or resulting-main certification yet. This document does not mark any acceptance criterion complete.

## Independent safeguards

The final review service uses the authenticated tenant/actor's immutable preview, exact offline quota reservation, global and workspace stop evidence, fresh policy revision, and separately sourced latest human approval. It reads rows in the **global stop → workspace quota → reservation** lock order shared with allocation and emergency-stop reconciliation. It returns explicit operator-readable reasons and never makes a provider call.

### Adversarial cases

- Global stop absent or active, workspace stop active, expired policy or changed version: fail closed
- Reservation absent, actor/tenant/brand/snapshot mismatch, held-by-stop or unknown external outcome: fail closed
- Resource cost, token, volume or attempt mismatch, budget counters over ceiling: fail closed
- Approval absent/revoked/rejected, permission withdrawn, self-approval, changed audience/content/destination/time/cost: hold
- Forged tool effect, incomplete stage machine, unknown model-supplied fields or over-limit estimate: reject
- Even matching approval and reservations: **offline_final_review_passed** only; `execution_authorized=false`, `promotion_authorized=false` and `external_outcome_verified=false`

## Remaining before TASK-0090 acceptance

This review is **not** an atomic production last-side-effect authorization; an approval revocation may be appended after the read, and there is no provider adapter, external outcome reconciler or irreversible-effect execution path here. Future real side-effect authority would require independent atomic claim/recheck of approved policy and stop at the same transaction boundary as a provider-intent outbox, last-minute revocation handling, certified provider receipts and explicit production activation governance. Nothing in this task should silently turn on delivery, publishing, spending, billing or promotion.

CI requires task-focused SQLite and PostgreSQL tests, full exact-head Foundation, PHP floor, E2E, Security and governance, and protected-main gates. Any unknown result remains held.
