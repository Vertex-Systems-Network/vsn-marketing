# TASK-0067 certification research and plan — 2026-10-02

## Scope and provenance

The source contract is the accepted PHASE-11 task chain and its research packs: TASK-0062 defines the offline boundary; TASK-0063 supplies workspace/brand scoped frozen assignments and witnessed exposures; TASK-0064 binds campaign snapshots and quarantines unverified outcomes; TASK-0065 permits only independently reviewed reversible offline optimization drafts; TASK-0066 freezes a preassignment statistical plan and returns quality-gated admitted-event diagnostics. The canonical `.ai/roadmap/PHASE-11.md`, task files and AGENTS.md require exact PR head and resulting-main application, governance and security evidence. These internal artifacts are the primary certification inputs.

External methodological references already reconciled in TASK-0066 research are Penn State STAT 509 for prespecified power assumptions, Eppo experiment diagnostics for SRM and mixed assignment, and Statsig sequential testing for fixed-horizon peeking. This certification tests the repository's own contract, and does not imply field conversion validity or equivalence to vendor platforms.

## Certification tests

1. Trace source code to tests and merged PRs #465–#469. Record immutable heads, resulting-main runs and review-only boundaries in a matrix. An unverified live metric cannot be reported as an effect.
2. Exercise deterministic sticky assignment replay and witness-only exposure, campaign outcome replay/quarantine, independent preassignment plan review, exact hash integrity, fixed horizon and null/strong numerical cases. Add a single integrated adverse sequence where a valid event replay remains unique, a quarantined mismatch invalidates analysis, and rollback blocks further candidacy.
3. Retain the PostgreSQL fork contention test (`tests/Integration/ExperimentAssignmentPostgresTest.php`), which asserts two competing workers return the same assignment ID and one durable row. Run it through full Application integration CI with PostgreSQL; local SQLite execution skips it and must be reported as such. Verify migration re-entry in the CI integration job.
4. Full PR head and resulting-main gates: Application Foundation's foundation/integration/php-floor/e2e, AI Continuity Guard, Security Supply Chain, Release Integrity, Scorecard and persistent Supervisor. Treat cancellation and pending as unresolved until a successful relevant run is observed. Only then guarded phase closure.

## Release boundary

No production experiment enrollment, provider outcome verifier, live conversion measurement, automated winner, or AI-driven campaign mutation is provided by PHASE-11. A future phase would need independent event semantics, sampling and consent provenance, production monitoring and activation approval. This is an honest offline architecture certification.
