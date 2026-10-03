# TASK-0071 Research Pack

- researched_at: 2026-10-03T18:24:00Z
- task: TASK-0071
- phase: PHASE-12
- scope: offline canonical monetary and attribution reports
- researcher: Codex Supervisor

## Sources
| Source | Type | Version/date | Accessed | Authority |
|---|---|---|---|---|
| https://docs.stripe.com/currencies | Official payment semantics | Current retrieved page | 2026-10-03 | Minor-unit integer amounts and explicit currency differences |
| https://docs.stripe.com/api/refunds/create | Official reference | Current retrieved page | 2026-10-03 | Refund references existing charge/payment identity |
| https://experienceleague.adobe.com/en/docs/analytics/analyze/analysis-workspace/attribution/models | Official workflow | Updated2026-09-28 | 2026-10-03 | Attribution model/container/lookback distinction |

## Current external reality and market workflow
Money needs explicit units; currencies cannot be silently added or converted. Refunds reverse an existing purchase. Attribution assigns descriptive credit within disclosed scope/lookback. First/last/linear models have different credit distributions. VSN v1 supports deterministic first and last touch only, rejects other names, conserves integer credit and discloses unattributed value. This is an explicit initial model surface, not removal of future model extensibility.

## Security/privacy and platform constraints
No Stripe connection, payment/refund execution or new collector. Canonical admitted facts and current purpose/retention/RBAC govern all derived calculations. Transaction identifiers are scoped hashed keys; reports exclude raw identifiers and pseudonyms. Only canonical registered touch events with allowlisted channels contribute. No raw free text or claimed experiment assignment can establish causal effect.

## Performance/reliability
Reconcile all retained scoped money facts under the existing1000 fact hard bound before window filtering, so transaction/refund duplicates across different periods cannot silently double count. Report bound and retained-history limits explicitly. Deterministic ledger snapshots preserve prior late-arrival results. Unknown purchase refunds stay unresolved; conflicts cannot invent or overwrite purchase value. Reconciliation compares integer gross minus refunds to net by currency. Observed LTV uses selected first-observed cohort and mature observation horizon, not lifetime prediction.

## Conflicts, extensions, rejected options and impact
CONFIRMS_PLAN: AC1–3 and analytics contract require these guards. No new prerequisite/ADR/module. Reject binary float money, implicit FX, arbitrary currency exponents, source total claims, participation models that inflate credit, guessed causal experiment linkage and silently truncated history. Implement report ledger identity reconciliation within durable immutable snapshots over canonical facts; no new money provider or operational payment ledger.

## Freshness risks
Provider details must be revalidated before an actual adapter. Research benchmarks semantics only; currency support is an explicit bounded VSN registry. Legal approval/default tracking policy is unchanged.
