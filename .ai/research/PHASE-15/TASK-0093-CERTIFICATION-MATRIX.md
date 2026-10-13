# TASK-0093 — PHASE-15 Offline Certification Matrix

Status: **TASK-0093 active; phase certification pending**. TASK-0092 passed exact-head/protected-main gates and its canonical transition. This matrix grants no production authority.

| Dependency | Source and adverse-case evidence | Accepted scope and unresolved gate |
| --- | --- | --- |
| TASK-0088 research | [Research](TASK-0088-RESEARCH.md), [acceptance](TASK-0088-ACCEPTANCE.md) | Threat and authority boundaries were established; research is not runtime proof. |
| TASK-0089 bounded loop | [Acceptance](TASK-0089-ACCEPTANCE.md) | Typed offline goal/plan/propose/observe/evaluate contracts; no live execution. Protected-main evidence recorded there. |
| TASK-0090 safety controls | [Certification](TASK-0090-CERTIFICATION.md), [acceptance matrix](TASK-0090-ACCEPTANCE-MATRIX.md) | Offline budget, approval, quota, rate and stop admission; no real spend/send. Protected-main evidence recorded there. |
| TASK-0091 canary and rollback | [Certification](TASK-0091-CERTIFICATION.md), [acceptance matrix](TASK-0091-ACCEPTANCE-MATRIX.md) | Offline frozen holdout, independently scored advice and unknown-effect rollback hold; no provider outcome or promotion. Protected-main evidence recorded there. |
| TASK-0092 red-team and incident | [Offline certification](TASK-0092-CERTIFICATION.md), [adversarial matrix](TASK-0092-ACCEPTANCE-MATRIX.md) | PR #569 exact head `cdf500d8083a1967b1265fa4720583aebd51b56d` passed Application Foundation, PHP 8.3 floor, PostgreSQL integration, browser E2E, Security and AI Governance; protected resulting main `1b2149863dae2a2d6d73fb1b408f01e17a0ff61c` passed these plus Release Integrity and Scorecard. Canonical AC-1/2/3 transition activated TASK-0093. Synthetic labels do not attest to executed attacks; independent production telemetry is absent. |

## Reproducible source, policy and adverse-path checks

| Task | Implementation and independent boundary | Reproduction / adverse path |
| --- | --- | --- |
| 0088 | `app/Modules/AI/Domain/Contracts/BoundedAutonomyObservationSource.php`; research establishes evidence provenance and scoped authority. | Review [research](TASK-0088-RESEARCH.md) and [acceptance](TASK-0088-ACCEPTANCE.md); research alone grants no execution. |
| 0089 | `app/Modules/AI/Application/BoundedAutonomyOfflineLifecycle.php`, `BoundedAutonomyPreview.php`; default `DenyingBoundedAutonomyObservationSource.php`. | `tests/Unit/AI/BoundedAutonomyOfflineLifecycleTest.php`, `BoundedAutonomyPreviewTest.php`: malformed/foreign or unavailable observation cannot become executable authority. |
| 0090 | `app/Modules/AI/Application/BoundedAutonomyOfflineSafetyGate.php`; database reservation, stop reconciliation, independent approval and denying safety/approval sources. | `tests/Unit/AI/BoundedAutonomyOfflineSafetyGateTest.php`, `tests/Integration/AutonomyOfflineQuotaContentionPostgresTest.php`, `AutonomyOfflineMultiWorkerQuotaStressPostgresTest.php`, `AutonomyOfflineStopAdmissionRacePostgresTest.php`: exhaustion, concurrency, revocation and stop races. |
| 0091 | `app/Modules/AI/Application/BoundedAutonomyOfflineCanaryScore.php`, `BoundedAutonomyOfflineJointRollbackReview.php`; denying cohort/outcome/provider sources. | `tests/Unit/AI/BoundedAutonomyOfflineCanaryScoreTest.php`, `BoundedAutonomyOfflineJointRollbackReviewTest.php`, `tests/Integration/AutonomyRollbackLedgerContentionPostgresTest.php`: frozen holdout, unverified effect, replay and rollback contention. |
| 0092 | `app/Modules/AI/Application/BoundedAutonomyOfflineRedTeamIncidentReview.php`, `Infrastructure/DatabaseBoundedAutonomyOfflineRedTeamAudit.php`; denying red-team evidence source. | `tests/Unit/AI/BoundedAutonomyRedTeamBoundaryTest.php`, `BoundedAutonomyOfflineRedTeamIncidentReviewTest.php`, `tests/Feature/AI/Task0092TypedToolFailureRedTeamTest.php`, `DatabaseBoundedAutonomyOfflineRedTeamAuditTest.php`: goal/context injection, tool failures, foreign scope, false acknowledgement and conservative triage. |

Run focused unit/feature tests with `php artisan test` followed by the paths above; run PostgreSQL integration paths with the configured test database. The recorded task-specific acceptance matrices carry the original case-by-case assertions and previous protected-main receipts. A fresh phase carrier must independently pass the repository's full CI gates.

## Authority boundary

The `DenyingBoundedAutonomy*Source` defaults in `app/Modules/AI/Infrastructure/` remain conservative when evidence is absent. Offline receipts, human review decisions and synthetic incident acknowledgements cannot authenticate a provider, authorize a real send/publish/ad spend, recover an unknown remote effect, execute a rollback, or promote a canary. Those actions require separate provider integration, independent outcome evidence and a new authority review; this phase certifies none of them. The certification matrix is an audit of code and tests, not a live campaign claim.

## Phase gates

1. Recheck every task source against its accepted revision; verify no default adapter gains send, publish, ad spend, provider retry, rollback, refund or promotion authority. Any unknown remote effect remains on human hold.
2. Submit TASK-0093 certification with standalone `CI-Mode: full`; require exact-head and resulting-main gates for the phase carrier. Only then record AC-1/2/3 and PHASE-15 completion through the transactional continuity tool. Stop without activating PHASE-16.

No production incident, external effect, live campaign, recovered provider operation or business uplift is inferred from synthetic fixtures. TASK-0093 AC-3 remains false until its own exact-head and resulting-main certification carrier passes.
