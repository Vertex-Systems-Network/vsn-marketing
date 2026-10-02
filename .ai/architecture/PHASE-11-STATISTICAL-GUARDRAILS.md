# Phase 11 statistical guardrails (TASK-0066)

## Evidence boundary

This service analyzes **the presence of an admitted campaign experiment outcome event per assigned unit** in the offline contract ledger. The event admission checks assignment and exposure provenance; it does not verify revenue, conversions, causal production impact, or a live provider receipt. All reports carry `evidence_kind=offline_admitted_outcome_event` and `publication_authorized=false`. `offline_signal` is a diagnostic interval result, never a winner or authorization to ship a treatment.

## Prespecification and scope

A workspace-scoped binding and active experiment must have identical plan hash, allocation, unit kind, control, and holdout. The author records a canonical SHA-256 plan before any assignment; a different actor with approval permission approves the exact hash before assignments. One plan is allowed per binding. The frozen JSON includes the binary outcome, unit, allocation, alpha, power assumption, baseline rate, absolute minimum detectable difference, UTC horizon, one fixed look, Bonferroni comparison count, and a sample size floor. A database unique key prevents concurrent duplicate plans. Reads reconstruct and hash-check the stored plan and recheck the experiment and tenant scope.

The power floor is a normal approximation for two independent proportions, using the larger of baseline and assumed alternative variance. It is a planning assumption, not observed power. Alpha is 0.01 or 0.05; power assumption is 0.8 or 0.9. The service rejects caller-supplied future observation times; the report is pending until the frozen horizon. Rows timestamped after that horizon are counted as a quality fault and excluded from arm counts, so a later read cannot silently improve the estimate.

## Diagnostics and inference

Assignment and exposure counts are checked against the frozen allocation with Pearson's chi-square survival probability if all expected cells are at least five. SRM at p < 0.001, missing exposure, crossovers, quarantined outcomes, invalid arm/hash, or late evidence returns `invalid` with no effect estimate. The analysis uses a distinct admitted assignment per arm as its numerator and all assigned units as the denominator. It needs the prespecified sample floor per nonholdout arm. Every treatment versus control gets a conservative difference interval formed from two Wilson intervals, each at the Bonferroni adjusted tail (`alpha/(4*comparisons)` for the normal quantile). If an interval crosses zero, result is `inconclusive`; otherwise it is only `offline_signal`. A small sample is `inconclusive` without effect estimates. Holdout units never receive exposure or outcome; holdout receives an assignment allocation diagnostic but is not a comparison.

The result is deliberately unsuitable for repeated live peeking or automated allocation changes. There is no live conversion verifier, sequential inference, provider enrollment, automatic winner selection, or promotion of a treatment. A production outcome metric requires independent instrumentation, source validation, consent checks, and a new reviewed analysis contract.

## Verification

Numerical reference cases cover normal quantile, Wilson interval, one- and two-degree-of-freedom chi-square survival; state cases cover horizon, sample shortfall, null interval, strong offline event difference, SRM, exposure missingness, crossover, quarantine and count integrity. Feature cases cover pre-assignment registration, independent approval, tenant scope, frozen hash tampering, late assignment and missing exposure. Exact-head and resulting-main CI are required before TASK-0066 acceptance.
