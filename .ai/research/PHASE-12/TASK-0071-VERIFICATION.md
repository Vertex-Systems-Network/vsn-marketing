# TASK-0071 verification — implementation pending CI acceptance

| Criterion | Implementation and tests | Boundary |
|---|---|---|
| AC-1 | Versioned first/last touch, strict pre-conversion time, inclusive bounded lookback, canonical channels, exact net credit conservation and unattributed bucket;40 varied arithmetic cases and reversal-order cases | Descriptive only; no causal uplift. |
| AC-2 | Scoped keyed purchase/refund identities reconciled across all retained admitted observations before report selection; immutable report ledger, semantic duplicate vs conflict, pending unknown purchase, cumulative overrefund/wrong currency/time/subject denial | Report ledger is not a payment provider or operational money ledger. Retained history/source completeness unknown. |
| AC-3 | Mature first-observed cohort LTV with exact rational minor-unit mean and null censored denominator; canonical experiment contact/plan/key/assignment/exposure/witness checks with read-time revocation | Independent witness is synthetic in tests. Default production composition has no verifier and denies linkage. No live experiment result/predicted lifetime value. |
| Privacy | Existing current RBAC/brand/tenant, consent, keyed pseudonym, retention, erasure and envelope-hash gates; stored report excludes raw transaction IDs/contact/name | No new collection/third-party transmission. Default purpose remains disabled. |
| PostgreSQL | Added retained purchase identity replay and late-refund immutable snapshot test under isolated disposable PG namespace | Local infrastructure skip is not PG execution evidence. Exact-head run still required. |

Local PHP8.3.6:825 passed/5513 assertions,140 infrastructure skips; focused analytics33 passed/254 assertions. PHPStan/Pint pass via locked vendor tools (Composer executable unavailable). Monetary defensive input validation was added after full regression and receives focused rerun; no product schema migration. Required full PR and resulting-main gates remain pending. Phase12 remains48 percent until acceptance.
