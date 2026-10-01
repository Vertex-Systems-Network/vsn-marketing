# TASK-0060 red-team certification

Status: accepted after full exact-head and resulting-main gates.

AC-1 and AC-2: versioned22-case corpus and outage/quarantine regressions drive actual context/runtime/gateway. Poisoned retrieval, foreign scope, hallucinated references, disclosure patterns, tools/approvals, self-modification and loops fail closed. Existing independent tool/creative/database tests stay intact. Unknown eval expected outcome now fails closed. Sources and limits: `.ai/architecture/PHASE-10-RED-TEAM.md`.

Local:42 AI tests/544 assertions;774 backend tests/4663 assertions with136 local infrastructure skips. PHPStan/Pint/policy/history/context/continuity pass. PR460 exact head `bc9b62c62409a953693b0973ba55fc1d10e4544a`: Application36938586952 (foundation110624853347, E2E110625649307, PHPfloor110625649323, integration110625649438), Continuity36938586888, Security36938586866 passed. Logs verify180 integration tests/1137 assertions,7 browser smoke tests,35 frontend tests and4 architecture tests/2718 assertions.

Resulting main `c2653b56f96c56cd9fc6973cf5c2a6a41b964b20`: Application36939266860 (foundation110626703139, E2E110627447541, integration110627447556, PHPfloor110627447586), Continuity36939266779, Security36939266826, Release36939266728, Scorecard36939266807 passed.

Evidence proves named deterministic server-boundary cases. It does not prove real-model universal injection resistance, complete prompt secrecy, semantic truth, provider privacy controls, or any live side effect. No real credential, attack endpoint or provider was used.
