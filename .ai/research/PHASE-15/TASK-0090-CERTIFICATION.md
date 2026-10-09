# TASK-0090 — Bounded Offline Autonomy Safety Certification

Task: TASK-0090 | Phase: PHASE-15 | Scope: **OFFLINE ONLY**.

## Exact-head delivery / evidence

| Carrier | Implementation and adversarial evidence |
| --- | --- |
| PR #531 | Strict fail-closed independent safety snapshot, tenant/policy/time checks, budget/volume/token/action/attempt ceilings, workspace/global emergency stop |
| PR #532 | Approval review bound to exact tenant/run/snapshot, immutable audience/content/destination/cost/volume/time; stale, revoked, self-approval and changed plans rejected |
| PR #533 | Durable PostgreSQL global stop/quota tables; serialized tenant-bound offline reservations, replay and two-worker contention tests |
| PR #534 | Durable independent approver authority, latest decision read-back and current role checks |
| PR #535 | No fabricated provider outcomes after stop; held reservations and unknown effects preserve counters pending reconciliation |
| PR #536 | Authenticated human-session, AI_APPROVE-checked approval writes, revoked decision semantics and read-back tests |
| PR #537 | Final offline admission rechecks reservation, quotas, approval role, global/workspace stops under transaction and without emitting external authority |
| PR #538 | Independent per-minute rate authority, atomic rate/window claims and PostgreSQL contention |
| PR #539 | Last-boundary independently configured rate recheck, serialization of approval writes with global stop, foreign org denial, stop-vs-admission PostgreSQL race |

The PR #539 exact head `57a13bc0d03764516424f3d3ffabd7242aac5581` passed Foundation, PHP 8.3 floor, PostgreSQL integration, browser E2E, Security Supply Chain, and AI Continuity/Governance before squash merge to protected main `c8f3ea3dd849565b228fc92b5a1aeaa203a64062`.

## AC-1 / AC-2 / AC-3 mapping

- **AC-1:** Independent daily cost/action/token/volume/attempt quotas and per-minute rate policy are read and locked. Entry and final preflight validate current policy against immutable offline reservations. PostgreSQL multi-worker adversarial tests cover oversubscription and stop racing.
- **AC-2:** Human-only append, permission/role revalidation and immutable exact-run content, audience, destination and bounded cost/volume approval fingerprints reject prompt self-approval, post-approval scope changes and revocation.
- **AC-3:** Global/workspace stops fail closed, transactions serialize approval and reservation reviews; stopped/unknown outcomes hold and retain costs rather than inventing delivery or refunds; cross-organization spoofing and stop races are tested.

## Truthful limits

**No real provider side effect is enabled or certified.** Success of a preflight/approval/claim never sets `execution_authorized=true` or `promotion_authorized=true`; no send, advertising expense, social posting, billing, credentials, autonomous provider action, canary enrollment or production evidence is claimed. The only irreversible outcomes available to these offline tests are unknown, for which no success or refund is inferred. Any live provider integration requires separate execution authorization, consent/suppression/provider eligibility, live outcome reconciliation and canary acceptance.

**Terminal task closure gate:** Verify the resulting protected-main `c8f3ea3dd849565b228fc92b5a1aeaa203a64062` Foundation, Security, Governance, release-integrity and any triggered PHP/PostgreSQL/E2E results; then record AC-1..AC-3 in canonical task registry with deterministic roadmap+README, checkpoint and append-only journal. Only next, activate TASK-0091 for offline canary/holdout contracts without production promotion.
