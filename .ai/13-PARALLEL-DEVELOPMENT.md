# Parallel Development Control Plane

## Purpose

VSN may accelerate one canonical active task by decomposing it into conflict-safe workstreams. Parallel work never activates a later roadmap task. The repository remains the source of truth.

## Roles

- The agent operating the main-repository context is the **Supervisor**.
- Protected `main` is integration state, not a scratch branch.
- The Supervisor creates all declared worker branches plus its own `supervisor/...` branch before any planning/code mutation for a new parallel cycle.
- The Supervisor owns shared/global files, reviews every registered workstream PR, decides merge order, and performs merge/broadcast/synchronization duties.
- Workers own only their registered workstream write paths.

## Branch-first invariant

Before a parallel cycle starts, every branch in `.ai/parallel/WORKSTREAMS.yaml` MUST already exist from the recorded main baseline. Planning documents may describe only branches that already exist. `python tools/ai_parallel.py validate-remote-branches` fails closed when a declared branch is missing.

## Workstream isolation

Writable work requires all of the following:

1. parent task is the canonical active task;
2. registered workstream and branch;
3. dedicated worktree;
4. assigned agent;
5. exclusive lease;
6. dependency readiness;
7. current instruction revision;
8. disjoint write paths;
9. latest `main` is an ancestor of the branch before resume/submission.

Workers may not mutate paths in `.ai/parallel/SHARED-PATHS.yaml`. Shared contract, migration, dependency, workflow, route/config, global state, or architecture mutations are Supervisor-controlled integration changes.

## Session startup triage

Every development session and every resumed writable cycle MUST triage repository work in this order before starting new implementation:

1. **Issues first:** inspect all open issues relevant to the active task, including governance/broadcast issues and newly reported defects. Resolve or explicitly classify actionable blockers before starting new feature work.
2. **Merge requests / pull requests second:** inspect every open PR/MR, submitted workstream PR, failing check, requested change, and merge conflict. Review, repair, synchronize, and merge eligible current-main green work before starting optional new implementation.
3. **New implementation third:** only after issue and PR/MR triage may the agent start or resume planned development work.

A submitted workstream PR remains a Supervisor interrupt and has priority over optional Supervisor module work. Triage must never bypass branch, lease, dependency, continuity, review, or CI gates.

## End-of-cycle README progress synchronization

At the end of every completed work cycle, merged workstream, or final development pass, the Supervisor MUST update the top-level `README.md` in the same integration/closeout flow.

The README update MUST include:

- a current overall roadmap progress percentage and text progress bar;
- the current phase percentage and progress bar when a phase is active;
- a **module-wise progress table** showing each active/completed module or capability, its status, evidence/PR where practical, and progress percentage;
- the active task, last completed task, current blockers, and next work when materially changed;
- only verified/merged/certified progress; draft, failing, unmerged, or merely planned work MUST NOT inflate completion percentages.

Canonical `.ai/` state remains authoritative. README is the required human-readable execution snapshot and must be synchronized after work finalization.

## Completion and Supervisor interrupt

A completed registered non-draft workstream PR must contain both standalone lines:

`Workstream: <WORKSTREAM-ID>`

`Work Done and Submitted`

That signal is a durable submission interrupt. The Supervisor pauses optional own-module work, reviews the PR, verifies branch ownership/current-main ancestry/tests/review threads, merges only approved green work, synchronizes its own branch, then resumes.

## Merge broadcast and resume barrier

After every registered workstream merge, the Supervisor posts this exact alert to broadcast issue #43 and remaining open workstream PRs:

`New changes have been merged — please merge these changes into your branch first, then resume your own work.`

Every alerted agent must merge/pull latest `main`, run `python tools/ai_parallel.py sync-check`, rerun affected fast checks, and only then resume writable work.

## New Agent Onboarding

A new agent is admission-controlled and must start from `main`.

1. The new agent checks out protected `main`; it does not begin on a worker branch.
2. The Supervisor checks the AI-Native Plan / machine workstream registry for an open non-Supervisor slot.
3. If a slot exists, the Supervisor immediately assigns the new agent to the pre-created branch, marks the slot occupied, records the agent identity and start status, and refreshes the AI-Native Plan.
4. Assignment order is deterministic: lowest merge group, then workstream ID.
5. If every worker slot is occupied, onboarding stops immediately and the exact response is:

`Go Home Come Back Next Time`

No branch assignment, lease, code mutation, or task work may occur after that rejection.

Commands:

```bash
python tools/ai_parallel.py onboarding-check --branch main
python tools/ai_parallel.py onboard --agent <agent-name> --agent-start-branch main
```

Dynamic agent/slot assignments are orchestration state. They do not by themselves change canonical working instructions or force a README instruction-revision bump.

## Capacity

- default concurrent writable workers: 6
- scale target after evidence: 10
- hard cap: 12

Increasing concurrency must not weaken path isolation, CI, task dependencies, or Supervisor merge serialization.

## README instruction synchronization

When canonical agent-working behavior changes, the same PR must:

1. update the relevant canonical instruction source;
2. review/update the top-level README agent section;
3. bump `.ai/parallel/CONTROL.yaml` instruction revision when behavior materially changes;
4. recompute its deterministic instruction fingerprint;
5. copy the same revision/fingerprint into README;
6. pass `python tools/ai_parallel.py validate`.

## Merge strategy

Registered workstream PRs target `main` and default to squash merge. Merge groups express ordering constraints. Independent lanes in the same group may develop in parallel but are merged one at a time; after each merge, all remaining active lanes synchronize latest `main` before continuing.
