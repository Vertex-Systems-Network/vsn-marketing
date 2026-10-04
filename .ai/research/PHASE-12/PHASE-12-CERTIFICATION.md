# Phase 12 analytics certification — accepted

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
| TASK0074 phase certification | PR478 head618cea2783f385a814e6bfa9a78f722bc0ff1eab; main33475aceb1d8c1f57d3c4f7ad107349d1e856c96 | This matrix; TASK-0074-RESEARCH.md; bounded measurement case in AnalyticsFactsPostgresTest; unknown partial migration DDL test; PostgreSQL analytics browser step | Source-bound PR/main measurements and browser/database parity passed; production limits remain unmeasured. |

Prior accepted task exact-head/main run IDs, tree equality, adversarial/local and real PostgreSQL evidence are retained in TASK0069–0073 verification files. They are historical task acceptance evidence, not fresh certification passes. TASK0073 acceptance used a final terminal check of its last integration job; all resulting-main gates passed.

## Certification gates and acceptance criteria

| Criterion | Verification | Current status |
| --- | --- | --- |
| AC1 | Matrix above and dated research, implementation and task verification packs cover all seven tasks, privacy and reconciliation. | Accepted. |
| AC2 PostgreSQL | Full integration suite on PostgreSQL18/Redis8; fact/schedule/reconciliation contention, immutable behavior and refund cases. | PR and main each187 passed/2498 assertions. |
| AC2 measured bounded scale/SLO | Thirty raw samples:5 invocations x2 operations x3 event cardinalities10/100/1000; actual canonical projection, exact counts, unknown totals, replay and1001 refusal. | PR and main raw JSON digests/source/file hashes independently verified; predefined bounds pass. |
| AC2 browser accessibility | Guarded authorized browser flow on PostgreSQL18: actual login/brand scope, mobile375px reflow, names/headings/labels/status, keyboard report/quality creation, schedule disable and disabled live explanation; anonymous401. Existing SQLite smoke remains separate. | PR and main each2 browser tests passed. Selected checks are not blanket WCAG conformance. |
| AC2 explanation evaluation | Good exact measured count and enum inference accepted; five adversarial fabricated number/command/scope/reference/tool cases denied and charged once each; live route denied; consent-invalidated flashed explanations withheld. | Full exact-head and main foundation regressions passed. No live-model quality claim. |
| AC3 | Exact-head application foundation, PHP8.3 floor, PostgreSQL integration/browser, SQLite E2E, security, continuity, Supervisor; reviewed expected-head merge; resulting-main same tree and full application/security/continuity/release/scorecard/Supervisor gates. | Accepted by guarded TASK0074 transition after all terminal jobs. |

## Predefined measurement interpretation

Budgets were recorded before capture in TASK0074 research: each service operation <=30,000ms, observed Laravel QueryExecuted events <=50*events+200 and incremental PHP peak allocation <=128MiB. Query events exclude PDO transaction control statements; cumulative driver-reported query time is recorded separately. Latency includes service authorization and canonical privacy/lineage checks plus persistence, excludes fixture setup and browser/HTTP transport. Five-sample nearest-rank p95/p99 both equal the observed maximum; they do not estimate production tails. Replays are correctness checks outside the fresh-request latency samples.

One synthetic consented contact/workspace/brand is intentionally bounded and reproducible. The hard1000-event contract is exercised with actual canonical admission;1001 must fail without a truncated persisted result. CPU/PHP/PostgreSQL/runtime and exact source/file hashes accompany raw results. PHP incremental allocation is not total process RSS. Shared CI scheduling, real multi-tenant cardinality, provider/network latency, scheduler saturation, monetary effectiveness and infrastructure cost remain excluded. No unmeasured production SLO/capacity claim is allowed.

Artifacts: the integration job emits PHASE12_MEASUREMENT JSON and uploads phase12-analytics-<exactSHA>; the actual run, digest and results are recorded below. Product purpose/source-verifier/live explanation defaults remain unchanged. Rollback refuses dropping nonempty evidence, unknown partial DDL is explicitly tested, and no live schema is migrated by this task.

## Local pre-CI verification
PHP8.3.6 full regression845 passed/5706 assertions,143 explicitly skipped infrastructure cases. Focused analytics52 passed/441 assertions includes unknown partial migration DDL refusal. Pint passed. New PostgreSQL measurement case is skipped locally and is not claimed as executed; local Chromium download remains unusable. Actual CI is required for measurement/browser acceptance.

## PostgreSQL18 bounded measurements — actual run

PR478 head `618cea2783f385a814e6bfa9a78f722bc0ff1eab`, Application run [37162980153](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37162980153), integration job111320499676: infrastructure suite and raw artifact upload completed successfully. Thirty raw samples, five per operation/cardinality, PHP8.5.11, PostgreSQL18.6, Laravel13.34.0, Linux runner with four logical CPUs and AMD EPYC 9V74. The source-bound [artifact](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37162980153/artifacts/11288368162) has archive digest `sha256:0b44ea51b1a2eb123d0c411d53dcf941c7a8d79e1d91ed269d4b43799717aebc`; raw JSON digest `sha256:bf8b0b144b4128f516a7310566e4d4c4245e8cc2563fcb6b15cb69e9094923d0`. It records each sample, relevant file SHA256 values, environment, setup times and budgets.

| Operation | Canonical events | p50 ms | Observed p95/p99 ms | Max observed SQL query events | Max driver query time ms | Max incremental PHP peak MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Count snapshot | 10 | 43.70 | 47.39 | 67 | 40.24 | 0 |
| Source quality | 10 | 102.03 | 109.40 | 151 | 93.77 | 0 |
| Count snapshot | 100 | 361.77 | 374.01 | 607 | 319.79 | 0 |
| Source quality | 100 | 953.18 | 986.60 | 1,411 | 843.43 | 0 |
| Count snapshot | 1,000 | 3,521.84 | 3,557.01 | 6,007 | 3,070.86 | 2 |
| Source quality | 1,000 | 9,307.21 | 9,460.92 | 14,011 | 8,167.65 | 2 |

Setup for the three incremental cardinalities was 147ms,1,258ms and12,516ms and is excluded from operation latency. All 30 fresh operations passed the predefined <=30,000ms, <=50*n+200 observed SQL query events and <=128MiB incremental allocation budgets. Replay was verified outside fresh-request timing. The 1,001st canonical event made both operations refuse without persisting a partial snapshot/checkpoint. Query count and latency scale linearly in this fixture; the 1,000-event quality check's 14,011 SQL events and 9.46s observed maximum are a concrete optimization consideration before production use. Five samples do not establish production tail latency; zero incremental allocation can reflect allocator reuse and is not total process RSS.

## Verified PR carrier

PR [#478](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/478) final head `618cea2783f385a814e6bfa9a78f722bc0ff1eab`: Application [37162980153](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37162980153) foundation111320084928, PHPfloor111320499698, SQLite E2E111320499787 and PostgreSQL integration111320499676 all pass. PostgreSQL integration187 tests/2498 assertions includes 30 raw samples, 1001 refusal and competing-worker tests; its guarded PostgreSQL browser step passed two Playwright tests. Security [37162980177](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37162980177) all required jobs passed, Continuity [37162980182](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37162980182) governance passed, and the cancelled Supervisor attempt was rerun successfully as job111321562837. PR reviews and inline review comments were empty. Expected-head squash merged to protected main `33475aceb1d8c1f57d3c4f7ad107349d1e856c96`; both commits have tree `02b019fbef935d04f66ad86bfb56abbbf8fb8a79`.

Two earlier heads failed and were repaired on this same PR: the clean CI runner lacked `storage/app` for the JSON artifact, then Pint required importing the filesystem facade. Their red checks are not counted as acceptance. The successful final head establishes the result.

## Protected-main certification and scope

Main `33475aceb1d8c1f57d3c4f7ad107349d1e856c96` has the same reviewed tree. Application [37163508080](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508080) foundation111321651835, PHPfloor111322122937, SQLite E2E111322122921 and PostgreSQL integration111322122888 all passed. Integration187 tests/2498 assertions and the two guarded PostgreSQL browser tests passed. Security [37163508105](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508105), Continuity [37163508090](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508090), Release [37163508110](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508110), Scorecard [37163508076](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508076) and Supervisor reconcile111323075173 all succeeded. A superseded reconcile attempt was cancelled; later same-main reconcile jobs succeeded. A final dependency-consumption check was needed after integration completed.

Main's independent [30-sample artifact](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/37163508080/artifacts/11289055473) has ZIP digest `sha256:098ef7a3c1645a3cbf121be6312d2da3999e5afba7b35dfb861524c1b87b76f8` and raw JSON digest `sha256:d9b44d72159cb74f4b12da2ea802f81722e753432f1e38e414db580f56277427`. Source SHA, six relevant file hashes, all thirty raw samples, five-sample nearest-rank percentiles and every predefined bound were independently verified against main's identical tree. At 1,000 canonical events, main p95 was 3,998.61ms / 6,007 observed SQL events for count and 10,292.76ms / 14,011 observed SQL events for quality; the 1,001st was rejected with no partial report.

Guarded terminal transition completed TASK0074 and PHASE-12: phase100%, deterministic roadmap82%. No successor task is registered; PHASE13–16 remain planned IDs and require explicit staging. Production purpose/source binding, provider truth, real-world causal effectiveness, representative production load/SLO and deployability remain separate evidence obligations.
