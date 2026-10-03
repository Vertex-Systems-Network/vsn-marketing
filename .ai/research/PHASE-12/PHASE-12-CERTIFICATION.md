# Phase 12 analytics certification — acceptance pending

Scope: bounded canonical analytics architecture and synthetic authorized operator workflows. Production purpose approval, source completeness, live source-verifier binding, real effectiveness, causal lift, representative production load/SLO and external report publication are not certified. No Phase13 task is activated.

## Requirements, source and test traceability

| Task | Accepted carrier | Source / tests | Evidence boundary |
| --- | --- | --- | --- |
| TASK0068 dated research | PR472 | TASK-0068-RESEARCH.md; analytics contract; official analytics/privacy/database sources | Research confirms canonical PostgreSQL, current purpose gate and measured evidence requirements. |
| TASK0069 canonical facts/counts/privacy | PR473 | AnalyticsFacts, MetricDefinition, ConsentAnalyticsPrivacy; AnalyticsFactsTest, MetricDefinitionTest, AnalyticsFactsPostgresTest | Envelope/source replay/conflict, exact UTC interval/cutoff, retention/consent/erasure/key rotation, real scope/RBAC,1001 denial; unknown source completeness. |
| TASK0070 behavior reports | PR474 | BehaviorDefinition/BehaviorMetrics; BehaviorMetricsTest, BehaviorSnapshotsTest, PostgreSQL late behavior snapshot case | Ordered funnel, elapsed UTC mature retention bins, censoring/nulls, lifecycle/performance dimensions; first observed is not proven acquisition. |
| TASK0071 attribution/revenue/LTV | PR475 | RevenueDefinition/RevenueMetrics; RevenueMetricsTest, RevenueSnapshotsTest, PostgreSQL late refund/identity case | Versioned descriptive credit, exact integer/rational money, duplicate/conflicting identities, pending/overrefund/currency/time rejection; observed LTV only. Experiment witness fixtures do not certify production outcomes. |
| TASK0072 dashboard/schedule/anomaly/AI | PR476 | AnalyticsController/operator page; ScheduledAnalyticsReports, AnalyticsInsights, AnalyticsExplanationGateway; AnalyticsOperatorTest, ScheduledReportsTest, AnalyticsInsightsTest, AnalyticsExplanationGatewayTest; PostgreSQL competing schedule workers; mobile Playwright flow | All six report model HTTP displays; frozen daily UTC schedule, token lease/retry/owner authority; descriptive sample-z, exact allowlisted measured facts, forged numbers/commands/scope/references/live route denied. Live AI remains disabled. |
| TASK0073 source quality | PR477 head8d91dc6cc5968a3b1b2860652249960f4011b9fb; maincbd83fdc931a2da16dfca21c3479cdadfce1564a | AnalyticsQuality/source-verifier port; AnalyticsQualityTest; PostgreSQL competing reconciliation; keyboard quality creation | Lag/late/duplicate/conflict/unprojected/unknown totals; independently attested scoped keys/hashes; synthetic manifest test binding only. Immutable replay/rollback, consent/erasure/corruption and migration safety. |
| TASK0074 phase certification | Current certification carrier, gates pending | This matrix; TASK-0074-RESEARCH.md; bounded measurement case in AnalyticsFactsPostgresTest; unknown partial migration DDL test; PostgreSQL analytics browser step | Actual source-bound measurements and browser/database parity must be captured before acceptance. |

Prior accepted task exact-head/main run IDs, tree equality, adversarial/local and real PostgreSQL evidence are retained in TASK0069–0073 verification files. They are historical task acceptance evidence, not fresh certification passes. TASK0073 acceptance used a final terminal check of its last integration job; all resulting-main gates passed.

## Certification gates and acceptance criteria

| Criterion | Verification | Current status |
| --- | --- | --- |
| AC1 | Matrix above and dated research, implementation and task verification packs cover all seven tasks, privacy and reconciliation. | Prepared; final gate acceptance pending. |
| AC2 PostgreSQL | Full integration suite on PostgreSQL18/Redis8; fact/schedule/reconciliation contention, immutable behavior and refund cases. | Current carrier actual run pending. |
| AC2 measured bounded scale/SLO | Thirty raw samples:5 invocations x2 operations x3 event cardinalities10/100/1000; actual canonical projection, exact counts, unknown totals, replay and1001 refusal. | Capture pending; no measurement result invented. |
| AC2 browser accessibility | Existing guarded authorized browser flow on PostgreSQL18: actual login/brand scope, mobile375px reflow, names/headings/labels/status, keyboard report/quality creation, schedule disable and disabled live explanation; anonymous401. Existing SQLite smoke remains separate. | Current PostgreSQL browser run pending. Selected checks are not blanket WCAG conformance. |
| AC2 explanation evaluation | Good exact measured count and enum inference accepted; five adversarial fabricated number/command/scope/reference/tool cases denied and charged once each; live route denied; consent-invalidated flashed explanations withheld. | Full exact-head rerun required. No live-model quality claim. |
| AC3 | Exact-head application foundation, PHP8.3 floor, PostgreSQL integration/browser, SQLite E2E, security, continuity, Supervisor; reviewed expected-head merge; resulting-main same tree and full application/security/continuity/release/scorecard/Supervisor gates. | Pending. Acceptance flags remain false. |

## Predefined measurement interpretation

Budgets were recorded before capture in TASK0074 research: each service operation <=30,000ms, observed Laravel QueryExecuted events <=50*events+200 and incremental PHP peak allocation <=128MiB. Query events exclude PDO transaction control statements; cumulative driver-reported query time is recorded separately. Latency includes service authorization and canonical privacy/lineage checks plus persistence, excludes fixture setup and browser/HTTP transport. Five-sample nearest-rank p95/p99 both equal the observed maximum; they do not estimate production tails. Replays are correctness checks outside the fresh-request latency samples.

One synthetic consented contact/workspace/brand is intentionally bounded and reproducible. The hard1000-event contract is exercised with actual canonical admission;1001 must fail without a truncated persisted result. CPU/PHP/PostgreSQL/runtime and exact source/file hashes accompany raw results. PHP incremental allocation is not total process RSS. Shared CI scheduling, real multi-tenant cardinality, provider/network latency, scheduler saturation, monetary effectiveness and infrastructure cost remain excluded. No unmeasured production SLO/capacity claim is allowed.

Artifacts: the integration job emits PHASE12_MEASUREMENT JSON and uploads phase12-analytics-<exactSHA>. Actual immutable run/artifact and raw file hashes will be appended after terminal success. Product purpose/source-verifier/live explanation defaults remain unchanged. Rollback refuses dropping nonempty evidence, unknown partial DDL is explicitly tested, and no live schema is migrated by this task.

## Local pre-CI verification
PHP8.3.6 full regression845 passed/5706 assertions,143 explicitly skipped infrastructure cases. Focused analytics52 passed/441 assertions includes unknown partial migration DDL refusal. Pint passed. New PostgreSQL measurement case is skipped locally and is not claimed as executed; local Chromium download remains unusable. Actual CI is required for measurement/browser acceptance.
