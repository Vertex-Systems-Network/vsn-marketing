# TASK-0066 — statistical and data-quality guardrails (2026-10-02)

## Current primary sources and impact

- [Penn State STAT 509 sample size/power](https://online.stat.psu.edu/stat509/Lesson08.html): sample size depends on prespecified alpha, power, expected rate, effect and comparisons. **Acceptance:** record baseline and minimum detectable absolute difference before enrollment; derive a conservative minimum per treatment arm rather than retrofitting a threshold.
- [Eppo diagnostics](https://docs.geteppo.com/experiment-analysis/diagnostics/): assignment SRM uses Pearson chi-square at 0.001, and mixed assignments, missing metric joins and data quality may invalidate results. **Acceptance:** compute assignment and treatment exposure ratio checks, missing exposure, crossovers and quarantined outcomes; any failing condition denies an effect conclusion.
- [Statsig SRM checks](https://docs.statsig.com/stats-engine/methodologies/srm-checks): unexpected unique-unit split can indicate bias. **Acceptance:** compare observed unique assignments with frozen allocation; report p-value and counts without silently correcting the sample.
- [Statsig sequential testing](https://docs.statsig.com/experiments/advanced-setup/sequential-testing): repeated looks at a fixed-horizon test inflate false positives. **Acceptance:** only one deterministic final decision after the frozen horizon; pending previews show diagnostics, never effect or winner.
- [Eppo entry point filtering](https://docs.geteppo.com/experiment-analysis/configuration/filter-assignments-by-entry-point/): assignment may precede exposure. **Acceptance:** assignment denominators and separately witnessed exposure counts are tracked explicitly; no assignment is mislabeled exposure.

## Repository assessment and statistical contract

TASK-0063 has frozen allocation and workspace-scoped assignment/exposure rows. TASK-0064 records admitted/quarantined event references, but has no trusted production conversion verifier. TASK-0065 promotes only offline reviewed drafts. Therefore TASK-0066 can analyze the **binary presence of an admitted outcome event** in offline evidence; it cannot claim a verified sale, marketing conversion or production lift. No live winner is authorized.

Use a versioned plan frozen before the first assignment, separate author and reviewer, primary outcome `admitted_outcome_event`, unit matching the experiment, alpha/power/baseline/MDE/horizon, fixed-horizon stopping, and Bonferroni across all non-control, non-holdout comparisons. At the horizon use assignment counts for denominator, unique admitted-outcome assignments for numerator. Any SRM (p < 0.001), absent exposure for a treatment assignment, crossover, quarantined outcome, duplicate/mismatched identity or insufficient sample blocks a positive signal. Report absolute effect and a conservative Newcombe-style difference interval from Wilson component intervals with union-bound-adjusted normal quantiles; a crossing-zero interval is inconclusive. Holdout remains excluded from treatment effect comparisons and is never treated as exposed.

No preliminary effect estimate is returned before the horizon. The fixed-horizon method is intentionally narrower than a sequential test. Numerics are independently compared to known reference values in tests. Statistical method implementation is local and not a claim of vendor equivalence or field validity. Actual traffic, outcome semantics and baseline calibration remain unproven.

Classification: confirms AC-1 through AC-3 and adds explicit offline-only interpretation. No production conclusion is supported until trusted metric provenance and live population evidence are separately reviewed.
