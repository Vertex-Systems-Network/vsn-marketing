# AI-Native Parallel Plan — TASK-0018 Certification

Status: **active on merge** — five worker leases plus the Supervisor coordination lane are registered on the TASK-0018 activation head. The leases become canonical only when this activation PR merges; every worker must synchronize the resulting latest `main` before its first write.

Supervisor: `supervisor-main`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

All six lanes have disjoint writable scopes. Worker branches were pre-created from certified main `4caa654426397473d21d382a8e4d3bcf43057546`; after this activation merges, each worker branch must be fast-forwarded/synchronized to the resulting `main` before work begins.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0018-EVIDENCE-MAP | PHASE-03 acceptance evidence map | `occupied` | `agent-evidence-map-01` | `leased_ready_to_start` | `agent/task-0018-evidence-map` | squash | merge latest main before resume |
| 20 | WS-0018-CONNECTOR-CERT | Connector contract, webhook, quota and reconciliation certification | `occupied` | `agent-connector-cert-01` | `leased_ready_to_start` | `agent/task-0018-connector-cert` | squash | merge latest main before resume |
| 20 | WS-0018-ISOLATION | Cross-workspace provider isolation certification | `occupied` | `agent-isolation-01` | `leased_ready_to_start` | `agent/task-0018-isolation` | squash | merge latest main before resume |
| 20 | WS-0018-NEUTRALITY | Provider-neutral architecture regression certification | `occupied` | `agent-neutrality-01` | `leased_ready_to_start` | `agent/task-0018-neutrality` | squash | merge latest main before resume |
| 20 | WS-0018-SECURITY-CERT | Security and supply-chain evidence certification | `occupied` | `agent-security-cert-01` | `leased_ready_to_start` | `agent/task-0018-security-cert` | squash | merge latest main before resume |
| 40 | WS-0018-SUPERVISOR-CERTIFICATION | PHASE-03 final certification, merge coordination and canonical closeout | `occupied` | `supervisor-main` | `coordination_in_progress_pending_worker_merges` | `supervisor/task-0018-certification` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Conflict boundaries

- Evidence map writes only `.ai/audits/PHASE-03/TASK-0018-EVIDENCE.md`.
- Neutrality writes only `tests/Architecture/Providers/Phase03/**`.
- Isolation writes only `tests/Integration/Providers/Phase03/**`.
- Connector certification writes only `tests/Feature/Providers/Certification/Connectors/**`.
- Security certification writes only `.ai/audits/PHASE-03/TASK-0018-SECURITY.md`.
- Supervisor alone owns canonical `.ai/state/**`, `.ai/tasks/**`, `.ai/roadmap/**`, and `.ai/parallel/**` closeout.

Five workers may execute concurrently while the Supervisor coordinates integration, exactly matching the configured default maximum of six writable lanes. No worker may write before its branch contains the latest merged `main`. Every merged worker lane forces the remaining worker branches to synchronize latest `main` before resuming or submitting.
