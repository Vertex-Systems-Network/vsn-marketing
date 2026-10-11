# TASK-0091 — Bounded Offline Canary / Holdout / Scoring / Rollback Certification

Task: TASK-0091 | Phase: PHASE-15 | Scope: **OFFLINE, deterministic and fail-closed**.

## Verified protected-main baseline

The latest implementation carrier, PR #563 (exact head `d18f1a2eb6c693ea9b44fbc6d5a9132d4605e985`), merged into protected `main` as `d5030edd11c2c2293be8563f3e74c2aedd13bb94`. On both heads, required Application Foundation, PHP 8.3 floor, PostgreSQL infrastructure integration, browser E2E, Security Supply Chain, and AI Continuity/Governance checks passed; the resulting main additionally passed Release Integrity and Scorecard. This is **repository CI**, not production telemetry.

## Acceptance mapping — bounded contracts, not real provider evidence

| Criterion | Code and adverse-case evidence | Certified behavior and limits |
| --- | --- | --- |
| **AC-1 — frozen, consent-safe canary and holdout denominators** | PRs #541-#543 establish independently sourced read-only cohorts, durable receipts and manifest joins; PRs #556-#559 add total/holdout reconciliation, independent frozen per-arm assignment readback, exposure/consent order, and immutable arm commitments with migration recovery. Unit and feature tests reject cross-tenant, stale, mismatched, exposed-holdout, poisoned and missing/tampered evidence. | A positive *offline review candidate* requires compatible frozen cohort/plan/snapshot, current scoped consent and consistent assignment/exposure denominators. No real enrollment or independent provider signature is inferred. |
| **AC-2 — deterministic independently scored promotion gating** | PRs #546-#550 bind human decision readback, current permissions and tamper/replay protection. PR #562 binds `BoundedAutonomyOfflineCanaryScore` to a canonical, ordered, SHA256-checked outcome manifest; the fixed-horizon/multiplicity-aware `ExperimentStatistics` rejects weak or low-trust outcomes. Adversarial unit/feature fixtures exercise forged/changed manifest, consent, source count and decision states. | AI narratives and unverified engagement **cannot self-promote**. Scoring emits operator-only advice; promotion/execution authority stays `false`. A content checksum is not itself external source authentication. |
| **AC-3 — no false success for rollback of provider side effects** | PRs #544-#545 add append-only rollback incidents and PostgreSQL replay races; #551/#552 require dual independently sourced attempts, aggregate state and canonical expected-operation inventory; #553-#555 bound observation/attempt/evaluation times; #560 prevents stale positive ledger replay over a newer hold; #561 binds aggregate outcome/callback/cost manifest; #563 rejects aggregate non-effect snapshots predating a later provider readback capture, with positive and negative regression fixtures. | Unknown, applied/irreversible, duplicate/late, stale, costly, mismatched or unavailable provider evidence holds for human review. The implementation **never claims a performed rollback, refund, retry or proven external non-effect**. |

## Formal transition requirements

- Complete the official `tools/ai_txn.py transition` with AC-1, AC-2 and AC-3 explicitly recorded; activate TASK-0092 only after the transaction and validation succeed.
- Keep TASK-0091 **in progress** until the certification carrier itself passes full exact-head Foundation, PHP floor, PostgreSQL, browser E2E, Security, Governance, and protected resulting-main verification. The certification carrier must declare `CI-Mode: full`.
- The task's completion is **limited to the offline deterministic acceptance criteria above**. There is no production-provider identity verification, real campaign enrollment, delivery/spend/click/conversion uplift measurement, live publishing, billing, actual rollback/refund, release or external authorization.
- Denying adapters stay default; all `execution_authorized`, `promotion_authorized`, `rollback_performed`, `refund_authorized` and `retry_authorized` values remain `false`. Real effectful activation is a separate human/provider authority boundary, not inherited from this task.
- TASK-0092's red-team and incident monitoring starts as **READY**, not completed; it must validate prompt-injection, misuse, quota/stop races, replay, exfiltration, tenant and metric attacks before phase certification.

This document maps already merged, green implementation evidence; it does not change authorization, test thresholds, scoring formulas or provider adapters.
