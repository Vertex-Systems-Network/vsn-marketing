# AI Execution Resilience and Timeout-Avoidance Standard

Status: **persistent operational standard**

Applies to: Supervisor, worker agents, control-only changes, research, implementation, certification, release/promotion work, and any chat/tool-driven repository session.

## Purpose

Reduce message/tool delivery timeouts and accidental duplicate repository operations without reducing correctness, security, verification, or protected-branch governance.

This standard optimizes how work is sliced and resumed. It does **not** weaken required tests, exact-head gates, security scans, branch protection, research gates, review requirements, or task dependencies.

## 1. One logical milestone per interaction by default

A single user interaction should normally complete at most one logical milestone.

Examples of one milestone:

- inspect current state and prepare one scoped code/docs change plus PR;
- diagnose and fix one failing gate on the current exact head;
- verify an already-running PR gate set and merge when green;
- verify post-merge protected-main evidence and reconcile the checkpoint;
- register one successor task;
- perform one guarded task/phase transition;
- run one bounded research or certification acceptance step.

Do not chain registration -> implementation -> repeated CI polling -> merge -> post-merge polling -> next-task activation in one interaction merely because each individual step is available.

Exception: tightly coupled safety/correctness cleanup may stay in the same interaction when stopping between the steps would leave the repository in an unsafe or internally inconsistent state.

## 2. External CI is a durable boundary, not a polling loop

When GitHub Actions or another external gate has started:

1. record the exact branch/PR/head SHA and required gate names;
2. perform an initial status read;
3. perform at most one additional status refresh in the same interaction unless:
   - a gate changed state and needs diagnosis;
   - a failure needs logs and a bounded fix;
   - the remaining checks are already completing and one final read is enough to close the milestone;
4. if required external checks are still running, end at the durable repository checkpoint instead of tight-polling;
5. on the next `continue`, re-read the actual GitHub state and resume from that evidence.

Never weaken, skip, cancel, or replace a required gate merely to avoid a timeout.

## 3. Repository state is the recovery source

Conversation state is never authoritative for resumption.

Canonical recovery precedence is:

1. `.ai/state/CURRENT-STATE.yaml` for the current phase, active task, execution status, progress, blockers, and exact next action;
2. `.ai/state/LAST-CHECKPOINT.md` for the last accepted milestone, exact-head/gate evidence, and resume handoff;
3. `.ai/state/EXECUTION-JOURNAL.jsonl` for append-only transition/history verification;
4. current GitHub branch/PR/exact-SHA and CI state for live external execution evidence.

If static plan/header prose conflicts with those canonical sources, do not resume from the stale prose. Resume from canonical state plus current GitHub evidence, then reconcile the descriptive plan separately.

Before repeating any write after a timeout, interrupted response, reconnect, or `continue`:

- read protected `main` or the relevant integration branch head;
- read the active PR/branch exact head;
- inspect current task/state/checkpoint when the operation affects canonical AI state;
- confirm whether the intended create/update/merge already happened;
- only then decide whether a write is still necessary.

Never blindly replay:

- branch creation;
- file creation/update;
- PR creation;
- merge;
- issue close/comment that changes workflow state;
- task transition;
- promotion;
- release/tag action.

Idempotent APIs are useful but are not a substitute for state verification.

## 4. Batch reads; minimize operational chatter

Prefer one grouped read for related repository facts instead of many serial calls.

Examples:

- fetch state + checkpoint + task + open PRs together;
- fetch all required gate statuses together;
- fetch related control files together before editing.

Avoid repeated reads that cannot change the next decision.

Do not fetch large logs unless a failing/ambiguous gate requires diagnosis.

## 5. Checkpoint at milestone boundaries

Every substantial milestone must leave enough durable evidence to resume safely.

Use the repository's existing canonical mechanisms as applicable:

- branch and exact commit SHA;
- PR number and exact head SHA;
- `.ai/state/CURRENT-STATE.yaml`;
- `.ai/state/LAST-CHECKPOINT.md`;
- append-only execution journal;
- task contract/status;
- workstream/lease registry;
- Runner benchmark backlog;
- issue-based Supervisor status.

Do not mutate canonical state merely to record that a CI job is still running. The branch/PR/SHA is already durable evidence for that waiting boundary.

## 6. Compact end-of-interaction handoff

Repository development responses should end with a compact operational handoff containing:

- Repository
- Active task / phase
- Milestone completed in this interaction
- Branch / PR / exact SHA when relevant
- Gate state: green / failed / running
- Exact next action
- Progress bar or progress percentage when canonical progress is available

This handoff is informational. Repository state remains authoritative.

## 7. Resume rule after a message-delivery timeout

If a user receives a message-delivery timeout and sends `continue`:

1. assume the previous response may have failed after some tool actions succeeded;
2. do not assume either success or failure from the missing message;
3. verify repository state first;
4. continue from the latest durable state;
5. do not duplicate a completed merge/write;
6. report any already-completed milestone succinctly before starting the next one.

## 8. Strict plan following and change-aware CI

The repository uses change-aware CI to reduce unnecessary hosted-runner work without weakening required checks.

Mandatory order:

1. recover and validate canonical state;
2. read current task/plan/checkpoint and exact repository head;
3. classify the exact change set;
4. execute one approved milestone;
5. run the change-class checks;
6. require the exact-head PR gates selected by policy;
7. merge only that verified head;
8. re-read repository state before any successor registration/transition.

`tools/ci_change_policy.py` fails closed. Pure `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` diffs may skip heavy Application/Security jobs at job level while required check contexts remain reported. Any other/unknown path runs full Application + Security CI.

A standalone `CI-Mode: full` line in the PR body forces full heavy gates for control-only certification, release/promotion, security-sensitive acceptance, or any task contract that explicitly requires those gates. Agents MUST add this marker when the milestone requires full exact-head gates despite a control-only diff.

Non-required post-merge Release Integrity and push-triggered OpenSSF Scorecard may use control-only path filters; scheduled/manual Scorecard and manual Release Integrity remain available. Workflow/dependency/tool/security changes never qualify as control-only.

Runner benchmarking remains a separate deferred optimization batch. Normal CI may still execute on GitHub-hosted runners when the change class requires it; that does not activate an RBT item.

## 9. Runner benchmark integration

Runner sizing, cache, concurrency, architecture, toolchain-performance and benchmark-environment work remains governed by `docs/benchmarks/RUNNER-TASK-BENCHMARK-BACKLOG.md`.

Execution-resilience rules do not activate the Runner batch.

Runner work remains deferred unless:

- the coordinated Runner batch is explicitly activated; or
- the existing documented blocker-escalation exception applies to a security, correctness, exact-head gate, or release blocker.

CI timeout avoidance must not be used as justification to change runner size, disable jobs, lower audit thresholds, reduce scanners, or bypass benchmark governance.

## 10. Security and correctness precedence

Timeout optimization is subordinate to repository safety.

A security/correctness/release blocker may require extra tool calls in the same interaction for diagnosis and bounded remediation. Even then:

- scope the investigation to the blocker;
- avoid unrelated progress;
- preserve exact-head evidence;
- do not weaken required checks;
- leave a durable checkpoint after the bounded fix.

## 11. Success criteria

This standard is working when:

- long sessions no longer rely on tight CI polling;
- `continue` safely resumes from GitHub state;
- duplicate branches/PRs/merges are avoided after delivery failures;
- each interaction ends at a meaningful, resumable milestone;
- required security/application/governance gates remain unchanged;
- Runner optimization remains separately governed;
- future AI-Native plans retain this standard across task and phase transitions.
