# TASK-0093 — PHASE-15 Offline Certification Matrix

Status: **prepared locally, not certified**. TASK-0092 has not passed its exact-head/protected-main gates or canonical transition; TASK-0093 remains planned. This matrix grants no production authority.

| Dependency | Source and adverse-case evidence | Accepted scope and unresolved gate |
| --- | --- | --- |
| TASK-0088 research | [Research](TASK-0088-RESEARCH.md), [acceptance](TASK-0088-ACCEPTANCE.md) | Threat and authority boundaries were established; research is not runtime proof. |
| TASK-0089 bounded loop | [Acceptance](TASK-0089-ACCEPTANCE.md) | Typed offline goal/plan/propose/observe/evaluate contracts; no live execution. Protected-main evidence recorded there. |
| TASK-0090 safety controls | [Certification](TASK-0090-CERTIFICATION.md), [acceptance matrix](TASK-0090-ACCEPTANCE-MATRIX.md) | Offline budget, approval, quota, rate and stop admission; no real spend/send. Protected-main evidence recorded there. |
| TASK-0091 canary and rollback | [Certification](TASK-0091-CERTIFICATION.md), [acceptance matrix](TASK-0091-ACCEPTANCE-MATRIX.md) | Offline frozen holdout, independently scored advice and unknown-effect rollback hold; no provider outcome or promotion. Protected-main evidence recorded there. |
| TASK-0092 red-team and incident | [Local certification](TASK-0092-CERTIFICATION.md), [adversarial matrix](TASK-0092-ACCEPTANCE-MATRIX.md) | Local PHP/PostgreSQL/browser/frontend/governance gates pass. Exact-head PR and resulting-main checks plus canonical AC transition remain pending. Synthetic labels do not attest to executed attacks; independent production telemetry is absent. |

## Phase gates

1. Verify TASK-0092's exact PR head and protected resulting-main Application Foundation, PHP 8.3 floor, PostgreSQL integration, browser E2E, Security, AI Governance, release integrity and scorecard outcomes. Record the protected main SHA and transition TASK-0092 through `tools/ai_txn.py` with AC-1/2/3.
2. Recheck every task source against its accepted revision; verify no default adapter gains send, publish, ad spend, provider retry, rollback, refund or promotion authority. Any unknown remote effect remains on human hold.
3. Submit TASK-0093 certification with standalone `CI-Mode: full`; require exact-head and resulting-main gates for the phase carrier. Only then record AC-1/2/3 and PHASE-15 completion through the transactional continuity tool. Stop without activating PHASE-16.

The local gate result for TASK-0092 is a prerequisite, not a replacement for GitHub CI. No production incident, external effect, live campaign, recovered provider operation or business uplift is inferred from synthetic fixtures.
