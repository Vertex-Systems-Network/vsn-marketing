# AI-Native Parallel Plan — TASK-0017 Pilot

Status: **active** — TASK-0017 is the canonical active task; worker slots are admitted only by the Supervisor from `main`.

Branch creation baseline: `bc821953b69dea2ac58eb1e3dbe41699a0dc111b`  
Supervisor: `supervisor-main`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The branches below were created before this plan was written.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0017-RESEARCH-QA | TASK-0017 provider research + QA evidence | **OPEN** | — | `awaiting_agent` | `agent/task-0017-research-qa` | squash | merge latest main before resume |
| 20 | WS-0017-BREVO | Brevo reference connector | **OPEN** | — | `awaiting_agent` | `agent/task-0017-brevo` | squash | merge latest main before resume |
| 20 | WS-0017-GMAIL | Gmail reference connector | **OPEN** | — | `awaiting_agent` | `agent/task-0017-gmail` | squash | merge latest main before resume |
| 20 | WS-0017-SES | Amazon SES reference connector | **OPEN** | — | `awaiting_agent` | `agent/task-0017-ses` | squash | merge latest main before resume |
| 30 | WS-0017-CONTRACT-MATRIX | Cross-connector sandbox and negative contract matrix | **OPEN** | — | `awaiting_agent` | `agent/task-0017-contract-matrix` | squash | merge latest main before resume |
| 40 | WS-0017-SUPERVISOR-INTEGRATION | Shared integration, merge coordination and final acceptance | `occupied` | `supervisor-main` | `assigned_waiting_for_dependencies` | `supervisor/task-0017-integration` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Merge order

1. Group 10 research/QA establishes current provider evidence.
2. Group 20 SES, Brevo, and Gmail may develop in parallel after research readiness.
3. Group 30 contract matrix integrates provider-neutral negative/sandbox coverage.
4. Group 40 Supervisor integration handles shared contracts/config/migrations/state and final acceptance.
5. Every merge is serialized by the Supervisor and forces latest-main synchronization on all remaining active branches.

## New agent onboarding

Every new agent starts from `main` and is admitted only by the Supervisor.

```bash
python tools/ai_parallel.py onboarding-check --branch main
python tools/ai_parallel.py onboard --agent <agent-name> --agent-start-branch main
```

If an open slot exists, the tool marks it occupied, records the agent and start status, and refreshes this table. If no open worker slot exists, the exact result is:

`Go Home Come Back Next Time`

The rejected agent receives no assignment and starts no work.
