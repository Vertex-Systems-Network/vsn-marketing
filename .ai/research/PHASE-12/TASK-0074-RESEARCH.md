# TASK-0074 Research Pack

- researched_at: 2026-10-03T20:55:00Z
- task: TASK-0074
- phase: PHASE-12
- scope: bounded analytics certification; research prepared while TASK0073 resulting-main gates finish
- researcher: Supervisor

## Sources
| Source | Type/version | Accessed | Why authoritative / impact |
| --- | --- | --- | --- |
| https://www.w3.org/TR/WCAG22/ | W3C WCAG 2.2 Recommendation | 2026-10-03 | Keyboard operation, programmatic names/status and reflow inform the existing mobile operator browser assertions. Selected assertions are not a complete WCAG conformance audit. |
| https://www.postgresql.org/docs/18/monitoring-stats.html | PostgreSQL18 upstream | 2026-10-03 | Database activity and timing must be measured; query counts and durations accompany application latency samples. |
| https://www.postgresql.org/docs/18/transaction-iso.html | PostgreSQL18 upstream | 2026-10-03 | Actual canonical-database execution and contention are required; SQLite skips do not establish PostgreSQL parity. |
| https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference | Official GA collection reference | 2026-10-03 | Receipt is not processed validity or source completeness. Certification retains unknown totals and independently verified checkpoints. |

## Current external reality and reference workflow
Keep definitions, UTC period/cutoff, freshness, missing/late/conflict diagnostics and immutable references visible. Existing selected accessibility tests cover keyboard report/quality creation and mobile reflow; run the same authorized flow against PostgreSQL18. Existing AI explanation tests must retain exact measured-value allowlists and default-disabled live routes.

## Security/privacy findings
Certification must cover current RBAC/tenant/brand membership, purpose, consent, retention, keyed identity, erasure, source checkpoint forgery, immutable replay and adversarial explanation input. No new collection, live provider binding or external delivery is introduced. Default purpose and source verifier remain disabled/unbound.

## API/platform constraints
Use the existing full application integration job's disposable PostgreSQL18 and Redis8 services. Run the existing guarded browser router only with APP_ENV=testing and a database ending _test; persistent production databases are never touched. Product certification measurements are acceptance work, not a runner sizing/cache/concurrency optimization batch.

## Performance/reliability findings and predefined regression budgets
Measure actual count snapshot generation and source-quality reconciliation at 10, 100 and 1000 canonical projected events; five fresh invocations of each per cardinality, same one-contact workspace. Record every wall-clock latency, application-issued query count, cumulative query duration and incremental PHP peak allocation. Nearest-rank p50/p95/p99 are descriptive of these five samples (p95/p99 equal the maximum); they are not production tail estimates. Predefine each operation <=30,000ms, <=50*n+200 queries and <=128MiB incremental peak allocation. Keep source completeness unknown, verify exact counts and replay fingerprints, and reject observation1001 without persisting a partial checkpoint. Setup time is recorded separately and excluded from request budgets.

The one-contact synthetic profile exercises the implementation's hard event bound, not a realistic multi-tenant production load. Capture PHP/PostgreSQL versions, exact source and relevant file hashes, CPU model/count and host load. CI shared resources, network/provider latency, operational scheduler queue saturation, money-effective throughput, multi-tenant production volumes and infrastructure cost remain unmeasured. No production SLO or capacity claim follows. Existing PostgreSQL worker contention and interrupted transaction tests provide bounded reliability evidence.

## Conflicts with current assumptions
The current browser job is SQLite-only. Add the guarded analytics browser flow to the existing PostgreSQL integration environment before certification. Existing local infrastructure skips and prior generic E2E successes cannot be relabeled as this evidence.

## Required roadmap extensions
None: TASK0074 already requires source/test/run traceability, measured bounded scale/SLO, PostgreSQL browser parity and explicit exclusion of unmeasured production claims.

## Rejected options
No invented production latency/uptime, extrapolation to unlimited events, new analytic store, live verifier, automated publication, runner optimization, blanket WCAG certification or silent acceptance of skipped database tests.

## Decision impact
CONFIRMS_PLAN: measured bounded offline certification, exact-head/main gates and current privacy/adversarial evidence. NO_PRODUCT_IMPACT: no new provider/runtime/production activation.

## Freshness risks
Future live source bindings, privacy purpose approval and production deployments need their own dated review and representative measurement.
