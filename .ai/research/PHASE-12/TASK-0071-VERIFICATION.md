# TASK-0071 verification — implementation pending CI acceptance

| Criterion | Implementation and tests | Boundary |
|---|---|---|
| AC-1 | Versioned first/last touch, strict pre-conversion time, inclusive bounded lookback, canonical channels, exact net credit conservation and unattributed bucket;40 varied arithmetic cases and reversal-order cases | Descriptive only; no causal uplift. |
| AC-2 | Scoped keyed purchase/refund identities reconciled across all retained admitted observations before report selection; immutable report ledger, semantic duplicate vs conflict, pending unknown purchase, cumulative overrefund/wrong currency/time/subject denial | Report ledger is not a payment provider or operational money ledger. Retained history/source completeness unknown. |
| AC-3 | Mature first-observed cohort LTV with exact rational minor-unit mean and null censored denominator; canonical experiment contact/plan/key/assignment/exposure/witness checks with read-time revocation | Independent witness is synthetic in tests. Default production composition has no verifier and denies linkage. No live experiment result/predicted lifetime value. |
| Privacy | Existing current RBAC/brand/tenant, consent, keyed pseudonym, retention, erasure and envelope-hash gates; stored report excludes raw transaction IDs/contact/name | No new collection/third-party transmission. Default purpose remains disabled. |
| PostgreSQL | Added retained purchase identity replay and late-refund immutable snapshot test under isolated disposable PG namespace | Local infrastructure skip is not PG execution evidence. Actual exact-head PASS evidence appears below. |

Local PHP8.3.6:825 passed/5513 assertions,140 infrastructure skips; focused analytics33 passed/254 assertions. PHPStan/Pint pass via locked vendor tools (Composer executable unavailable). Monetary defensive input validation was added after full regression and receives focused rerun; no product schema migration. Required full PR and resulting-main gates passed as documented below. Guarded TASK0071 acceptance advances Phase12 to66 percent.

## Exact PR and resulting-main evidence

PR475 finalhead `9ad70e489acf11770f99e4e271b8a4dbf1146fea`: Application37144899328 foundation111266719938, integration111267188937, E2E111267188986, PHPfloor111267188999 all success. Integration log explicitly PASS revenue identity/late-refund case in AnalyticsFactsPostgresTest;184 passed/1162 assertions. Security37144899370 all nine success, Continuity37144899322 governance success (publish-default skip expected for PR), Supervisor37145052948 reconcile111267158706 success. Reviews/threads empty; expected-head squash verified. A second acceptance-required PR refresh confirmed the final integration completion.

Resulting main `7a4641c37084cdb3cde27cbc4eff9959a08094eb` tree `142e30dae99e3ef833bcbcb8a54123c249089a25` matches verified PR. Main Application37145257323 foundation111267752853, PHPfloor111268236980 and E2E111268237008 success; integration111268236973 success at final acceptance observation. Security37145257299 all nine success; Continuity37145257319 governance/publish-default success; Release37145257306, Scorecard37145257304, Supervisor37145378845 reconcile111268106511 success. TASK0071 accepted after final integration confirmation; Supervisor log checksout7a4641c; second consolidated refresh justified by dependency consumption.
