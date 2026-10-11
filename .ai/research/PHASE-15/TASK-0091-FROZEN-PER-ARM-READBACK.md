# TASK-0091 — Frozen Per-Arm Denominator Readback Before Offline Operator Review

Status: **staged, not certified**. Does not enable publishing, sending, spending, production promotion or third-party actions.

## Risk

The durable receipt currently stores total assigned and holdout assigned; these totals alone cannot detect a reassignment of treatment/control counts that preserves both numbers. A trusted-looking scored outcome manifest can therefore describe a statistically favorable but differently allocated cohort.

## Independent source binding

- The frozen cohort reviewer now exposes its validated integer assignment count per arm, sorted by variant.
- The deterministic offline score carries its independently validated per-arm assignment map.
- For an otherwise positive scored candidate, the joined operator review requires a *fresh* independent frozen cohort source, revalidates consent, freeze, SRM, no exposed holdout, exact tenant and plan, and matches its exact assignment manifest digest and durable total/holdout receipt.
- The scored and frozen per-arm assignment maps must match exactly after canonical sorting. Missing source holds with `independent_cohort_arm_readback_unavailable`; source mismatch holds with `independent_cohort_arm_readback_unverified`; any treatment/control swap holds with `cohort_outcome_arm_denominator_mismatch`.

The existing independent outcome source still has to match the outcome join manifest. Synthetic source fixtures may produce a read-only candidate but never grant any external execution or promotion authority.

## Regression

A full feature fixture demonstrates a positive statistical signal for a frozen 5000/5000/1000 cohort still requires independent source readback. It then changes scored assignment to 4999/5001/1000 without changing overall assigned or holdout totals and requires the join to hold. All effects remain explicitly unauthorized.

This is a **readback contract**, not proof of production source signatures. Actual trusted provider/assignment adapters remain deny-by-default unless explicitly installed with independent attestation. TASK-0091 remains IN_PROGRESS pending exact-head, resulting-main gates and holistic acceptance.
