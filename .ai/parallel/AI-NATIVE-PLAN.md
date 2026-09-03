# AI-Native Parallel Plan — TASK-0018 Certification

Status: **staged** — six pre-created branches exist from certified main `4caa654426397473d21d382a8e4d3bcf43057546`; worker leases remain empty until TASK-0018 is canonically activated.

Supervisor: `supervisor-main`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The branches below were created from the certified TASK-0017 acceptance main before this plan was written. Worker scopes are intentionally disjoint and avoid all Supervisor-owned shared paths.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0018-EVIDENCE-MAP | PHASE-03 acceptance evidence map | `occupied` | `agent-evidence-map-01` | `assigned_waiting_for_task_activation` | `agent/task-0018-evidence-map` | squash | merge latest main before resume |
| 20 | WS-0018-CONNECTOR-CERT | Connector contract, webhook, quota and reconciliation certification | `occupied` | `agent-connector-cert-01` | `assigned_waiting_for_task_activation` | `agent/task-0018-connector-cert` | squash | merge latest main before resume |
| 20 | WS-0018-ISOLATION | Cross-workspace provider isolation certification | `occupied` | `agent-isolation-01` | `assigned_waiting_for_task_activation` | `agent/task-0018-isolation` | squash | merge latest main before resume |
| 20 | WS-0018-NEUTRALITY | Provider-neutral architecture regression certification | `occupied` | `agent-neutrality-01` | `assigned_waiting_for_task_activation` | `agent/task-0018-neutrality` | squash | merge latest main before resume |
| 20 | WS-0018-SECURITY-CERT | Security and supply-chain evidence certification | `occupied` | `agent-security-cert-01` | `assigned_waiting_for_task_activation` | `agent/task-0018-security-cert` | squash | merge latest main before resume |
| 40 | WS-0018-SUPERVISOR-CERTIFICATION | PHASE-03 final certification, merge coordination and canonical closeout | `occupied` | `supervisor-main` | `assigned_waiting_for_task_activation` | `supervisor/task-0018-certification` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Conflict boundaries

- Evidence map writes only `.ai/audits/PHASE-03/TASK-0018-EVIDENCE.md`.
- Neutrality writes only `tests/Architecture/Providers/Phase03/**`.
- Isolation writes only `tests/Integration/Providers/Phase03/**`.
- Connector certification writes only `tests/Feature/Providers/Certification/Connectors/**`.
- Security certification writes only `.ai/audits/PHASE-03/TASK-0018-SECURITY.md`.
- Supervisor alone owns canonical `.ai/state/**`, `.ai/tasks/**`, `.ai/roadmap/**`, and `.ai/parallel/**` closeout.

At activation the five worker lanes may run concurrently with the Supervisor, exactly matching the configured default maximum of six concurrent writers. Every merged lane forces the remaining branches to synchronize latest `main` before resuming or submitting.
