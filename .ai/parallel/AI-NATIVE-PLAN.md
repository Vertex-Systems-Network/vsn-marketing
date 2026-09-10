# AI-Native Parallel Plan — TASK-0101 Persistent Supervisor Control Plane

Status: **active** — TASK-0101 installs the repository-native always-on Supervisor runtime requested by the operator. Shared implementation remains isolated to the Supervisor-owned lane; one disjoint **OPEN** QA worker slot is pre-created to preserve deterministic onboarding/independent-verification capacity without adding an active writer or lease.

Supervisor: `supervisor-main`  
Supervisor branch: `supervisor/task-0101-persistent-control-plane`  
Trusted baseline: `94461fe3d050a04bd87b86820232577caf9ad8e3`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The dedicated Supervisor branch and the optional QA branch were created from the trusted post-TASK-0022 `main` before their respective registry mutations. That baseline passed AI Continuity Guard run `34507925149`, Application Foundation CI run `34507924783`, Security Supply Chain CI run `34507924951`, Release Integrity run `34507924894`, and OpenSSF Scorecard run `34507924921`.

TASK-0101 is a zero-roadmap-weight cross-cutting governance insertion. It does **not** renumber, reinterpret, or replace the preplanned product roadmap. In particular, the reserved **TASK-0023 remains delivery SLO/load/saturation/fault-injection/PostgreSQL/Redis production-parity certification**, followed by TASK-0024 PHASE-04 certification.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0101-PERSISTENT-SUPERVISOR | Persistent GitHub-native Supervisor reconciliation and durable coordination status | `occupied` | `supervisor-main` | `active` | `supervisor/task-0101-persistent-control-plane` | squash | merge latest main before resume |
| 20 | WS-0101-PERSISTENT-SUPERVISOR-QA | Independent persistent Supervisor verification evidence without shared-path mutation | **OPEN** | — | `awaiting_agent` | `agent/task-0101-persistent-supervisor-qa` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Canonical runtime

The always-on Supervisor runtime is `.github/workflows/persistent-supervisor.yml`. It becomes persistent only after merge to the default branch, because GitHub scheduled workflows and `workflow_run` triggering depend on the workflow existing on the default branch.

Runtime triggers:

- event-driven `pull_request_target` reconciliation for opened, synchronized, reopened, edited, ready-for-review, draft-conversion, and closed activity; the privileged job always checks out trusted `main` and never PR-head code;
- `issue_comment` creation so durable coordination activity can cause a fresh reconciliation;
- `workflow_run` completion for AI Continuity Guard, Application Foundation CI, and Security Supply Chain CI;
- manual `workflow_dispatch`;
- a `*/5 * * * *` heartbeat, the shortest schedule interval GitHub supports. The heartbeat is a reconciliation safety net, not a hard real-time guarantee because GitHub may delay or drop scheduled runs during load.

A constant concurrency group with stale-run cancellation ensures a newer reconciliation supersedes obsolete work.

## Durable status model

The runtime owns one issue titled `[Supervisor] Persistent Control Plane Status`. Every run recomputes repository state from authoritative sources and updates that issue rather than trusting old comments or chat memory.

The issue reports at minimum:

- current default-branch SHA;
- canonical active task and execution status from `.ai/state/CURRENT-STATE.yaml`;
- configured parallel parent task and workstream registry state;
- open registered workstream PRs;
- whether each PR contains the exact standalone `Work Done and Submitted` signal;
- whether current `main` is an ancestor of the PR head;
- required exact-head CI status for AI Continuity Guard, Application Foundation CI, and Security Supply Chain CI;
- review-ready state and blockers.

## Review-ready contract

`SUPERVISOR REVIEW READY` is deterministic triage only. It is true only when all of the following are true for a registered non-draft workstream PR:

1. the PR targets `main`;
2. the PR body contains standalone `Workstream: <registered-id>`;
3. the PR body contains the exact standalone line `Work Done and Submitted`;
4. current `main` is contained in the PR head history;
5. all three required CI workflows have completed successfully on that exact PR head SHA.

The runtime may add one deduplicated review-ready PR comment keyed to the exact head SHA. That marker is **not approval and never authorizes merge by itself**.

## Security and authority boundaries

The persistent Supervisor is a deterministic observer/triage plane, not an autonomous code-writing or merge agent.

It MUST NOT:

- auto-merge or auto-approve pull requests;
- force-push or move refs;
- edit canonical `.ai` state, checkpoints, task files, workstream registries, product/runtime code, migrations, configuration, or tests;
- modify branch protection, repository rules, required checks, deployments, environments, packages, or secrets;
- fabricate worker completion or treat ambiguous evidence as success;
- execute PR titles, bodies, branch names, or comments as shell/code input;
- check out or execute pull-request head code inside the write-capable `pull_request_target` workflow.

Its token permissions are limited to repository/action reads and issue/PR coordination writes. Third-party Actions dependencies are immutable-SHA pinned. `GITHUB_TOKEN` writes are deliberately used for status surfaces so normal recursive workflow-trigger storms are suppressed by GitHub's token semantics.

## Implementation / verification sequence

1. Keep TASK-0101 active and the Supervisor lane leased to `supervisor-main`; leave the QA lane open unless independent verification is actually assigned.
2. Implement the deterministic policy/API runner under `tools/persistent_supervisor.py` using only Python standard-library HTTP/JSON facilities and explicit GitHub REST calls.
3. Add deterministic tests under `tools/test_persistent_supervisor.py` for standalone-signal parsing, workstream registration, main ancestry classification, exact-head CI classification, issue rendering/deduplication, and fail-closed missing evidence.
4. Add the pinned, least-privilege `.github/workflows/persistent-supervisor.yml` wrapper.
5. Update operator-facing README guidance without changing the canonical parallel instruction revision/fingerprint unless agent-working semantics themselves change.
6. Run all continuity/parallel/policy tests plus exact-head Application, Security Supply Chain, and AI Continuity gates.
7. Merge only after current-main ancestry and exact-head green evidence are true. No automatic merge path is introduced.
8. After merge, verify a default-branch Persistent Supervisor run creates/updates the durable status issue and reports healthy canonical state.
9. Close TASK-0101 through the transactional continuity wrapper and then explicitly stage the already-reserved TASK-0023 delivery SLO/load task as the next canonical product task.

No external ChatGPT schedule is required or authorized for repository supervision.
