# TASK-0072 Research Pack

- researched_at: 2026-10-03T18:49:00Z
- task: TASK-0072
- phase: PHASE-12
- scope: scoped operator dashboard, schedules, anomalies and R0 explanations
- researcher: Codex Supervisor

## Sources
| Source | Type | Version/date | Accessed | Authority |
|---|---|---|---|---|
| https://www.w3.org/WAI/tutorials/tables/ | Primary accessibility | Current retrieved page, updated2023-02-16 | 2026-10-03 | Header/caption/scope semantics |
| https://github.com/laravel/docs/blob/13.x/scheduling.md | Upstream framework |13.x current search retrieval |2026-10-03| Scheduler overlap/single-server controls |
| https://www.itl.nist.gov/div898/handbook/pmc/section3/pmc322.htm | Primary statistical guidance | Current retrieved handbook |2026-10-03| Baseline/variability/control-limit distinction |

## Current reality and market workflow
Accessible measured tables need semantic headers/captions, labelled forms and observable loading/error/empty states; responsive overflow must remain keyboard accessible. Reports must disclose model/version, UTC period, cutoff, lineage and unknown source coverage. Scheduling overlap guards alone do not provide durable report idempotency: unique schedule/window identity and transactional snapshot completion remain required.

## Security/privacy and API constraints
Scope from canonical authenticated tenant middleware; no client-supplied actor/workspace can override it. Schedule owner permission/purpose/retention rechecked at execution. No external report delivery. AI remains offline R0 with Phase10 gateway policies: only approved nonpersonal aggregate context, exact snapshot metric references/values, enum inference labels, no operational commands/free-form numbered claims. Default route absent means unavailable, not fabricated AI success.

## Performance/reliability and anomalies
Bounded schedules/due work, durable claim lease, attempt budget and retry/recovery. Local count anomaly v1 uses at least seven comparable prior daily snapshots, sample standard deviation and fixed absolute z-score threshold3. This is a local descriptive signal, not calibrated probability, NIST-chart conformance, source completeness or Six Sigma. Reject stale/invalid/noncomparable/zero-variance baselines; no confident anomaly with insufficient evidence.

## Conflicts/extensions/rejected options/impact
CONFIRMS_PLAN AC1–3, existing first-party UI and Phase10 gateway. No new provider/ADR. Additive safe schedule/run migration under existing review contract. Reject unsafe arbitrary report SQL, source completeness inference, webhook delivery, fluent AI commands and fabricated numbers. PostgreSQL browser/scale certification remains TASK0074; new scheduling PG adversity is tested in this task.

## Freshness risks
Actual external AI or delivery adapter activation requires fresh provider/policy approval. WCAG checks do not establish universal conformance without the scoped manual/browser evidence.
