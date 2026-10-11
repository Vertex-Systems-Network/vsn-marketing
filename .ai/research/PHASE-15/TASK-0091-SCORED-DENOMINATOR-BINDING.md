# TASK-0091 — Frozen Cohort to Scored Outcome Denominator Binding

Status: **offline-only candidate; exact-head and resulting-main certification pending**.

## Defect / risk

The authenticated outcome join matched the cohort receipt identity and independent outcome manifest, but did **not** compare the independently scored assignment population against the frozen receipt counts. It was therefore possible for an independently formatted scored source to report 11,000 assigned units for a 1,000-unit frozen cohort and still enter the scoring branch, as long as the outcome manifest identity was accepted. Matching digests is not the same as matching denominator evidence.

## New offline guard

`BoundedAutonomyOfflineCanaryScore` now returns integer `assigned_denominator` and `holdout_denominator` derived from its validated independently sourced `assigned` counts; these fields are null on held/missing source. `BoundedAutonomyOfflineOutcomeJoinReview` checks that both integers match the tenant-bound stored receipt totals **before** returning an operator review candidate, failing closed with `cohort_outcome_denominator_mismatch` if either differs.

Regression fixtures prove a consistent 1,000/100 cohort remains held only by the fixed analysis horizon, inflated 11,000/1,000 outcome population is held for denominator mismatch, and a manipulated 1,000/200 holdout is also held. No provider send, ad spend, publication, automatic promotion or live outcome attestation is enabled.

## Scope limitation and next acceptance step

This is a **total + holdout** hardening, not complete per-arm population attestation. The current durable receipt persists only total assigned and holdout assigned; it does **not** persist or sign the entire per-arm denominator vector. Control/treatment reallocation with the same total and holdout cannot be independently disproved solely by these two columns. A full per-arm frozen-denominator provenance source or a backward-compatible, tested durable schema extension is still required before treating synthetic positive statistics as fully independently bound. TASK-0091 AC-1/2/3 therefore remain **NOT DONE**.

All source adapters remain deny-by-default, and synthetic tests are not evidence of actual external provider transactions.
