# PHASE-11 offline experimentation certification — TASK-0067

This is the evidence ledger for source, tests and GitHub gates. It certifies the bounded offline architecture. It does **not** certify real conversion effectiveness, live traffic safety, a production metric source or treatment enrollment. Those remain separate activation work.

| Task | Accepted carrier | Source and assertion | Evidence boundary |
| --- | --- | --- | --- |
| TASK-0062 | PR [#465](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/465) | `TASK-0062-RESEARCH.md`, primary statistical and product documents | Research acceptance only; no field experiment. |
| TASK-0063 | PR [#466](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/466) | `ExperimentAssignments`, `ExperimentAllocator`, `ExperimentAssignmentsTest`, `ExperimentAllocatorTest`, `ExperimentAssignmentPostgresTest` | Deterministic salted assignment, unique durable layer/unit, witnessed exposure, independent activation and PostgreSQL fork contention. |
| TASK-0064 | PR [#467](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/467) | `CampaignExperiments`, `CampaignExperimentMatrix`, `CampaignExperimentsTest`, `CampaignExperimentMatrixTest` | Frozen campaign snapshot/arm matrix, sticky candidate, admitted or quarantined offline event; rollback denies candidacy. |
| TASK-0065 | PR [#468](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/468) | `OptimizationProposals`, `OptimizationProposal`, `CampaignExperimentsTest`, `OptimizationProposalTest` | Verified receipt, budget, independent evaluator and reviewer, high risk rejection, reviewed draft and revert; no live mutation. |
| TASK-0066 | PR [#469](https://github.com/Vertex-Systems-Network/vsn-marketing/pull/469), exact head `32755a0a51cf97a43e7157a266190efce2d1cf1f`, merged main `e0e3bc8eaac3a123d2e3130d36bc34ce1faaea76` | `ExperimentAnalysisPlan`, `ExperimentStatistics`, `ExperimentAnalysis`, numerical unit and feature tests | Frozen plan, fixed horizon, future timestamp rejection, SRM and contamination blocks, conservative Bonferroni difference intervals; binary admitted-event signal only. |
| TASK-0067 | Certification carrier pending | This matrix and integrated replay/quarantine/rollback feature test | Exact head and resulting-main full CI required before acceptance. |

## TASK-0066 gate record carried into certification

- Exact PR head `32755a0...`: Application Foundation CI [36993001180](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993001180) foundation, integration, php-floor, e2e succeeded; Continuity [36993001186](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993001186), Security [36993001187](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993001187), persistent Supervisor [36993210481](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993210481) succeeded.
- Protected main `e0e3bc8...` tree matched exact verified PR head. Application [36993674598](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993674598) four jobs, Continuity [36993674385](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993674385), Security [36993674370](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993674370), Release Integrity [36993674490](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993674490), Scorecard [36993674384](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993674384), Supervisor [36993860060](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36993860060) succeeded. Local resulting-main suite: 791 passed, 5,248 assertions, 137 infrastructure skips.

## TASK-0067 verification matrix

| Requirement | Test/evidence | Acceptance rule |
| --- | --- | --- |
| Assignment replay, identity isolation and witnessed exposure | `ExperimentAssignmentsTest`; `ExperimentAllocatorTest` | Same subject replays same assignment; foreign scope, key rotation, unsanctioned exposure and holdout exposure denied. |
| PostgreSQL contention | `ExperimentAssignmentPostgresTest` under full Application integration job with `DB_CONNECTION=pgsql`, `RUN_INFRA_INTEGRATION=true` | Forked workers yield one durable assignment and identical ID. A local SQLite skip does not count. |
| Candidate/outcome replay, contamination and rollback | `CampaignExperimentsTest` integrated certification case | Exact event replay has one row, mismatch quarantines and blocks analysis, rollback stops candidacy and report. |
| Independent AI review and reversibility | `CampaignExperimentsTest`, `OptimizationProposalTest` | Proposer/evaluator/reviewer separation, exact hashes, rejected high risk, reverted draft. |
| Statistical adversity and interpretability | `ExperimentStatisticsTest`, `CampaignExperimentsTest` | Normal/Wilson/chi-square numeric references; missing exposure, SRM, crossover, quarantine, future observation and null/short sample have no positive conclusion. |
| Offline scope | Domain/application contract and report keys | `publication_authorized=false`; no verified production outcome source or automatic promotion. |
| Full CI and main | PR and main Actions runs to be appended after gates | All four Application jobs, Continuity, Security, Release Integrity, Scorecard and Supervisor successful for their respective commits. |

The analysis sample-size floor is a planning approximation and the reported interval concerns only the admitted event ledger. Live experiment design, metric validity, sequential monitoring, privacy/retention and production switch are still pending.
