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
3. bump `.ai/parallel/CONTROL.yaml` instruction revision when behavior changes materially;
4. recompute its deterministic instruction fingerprint;
5. copy the same revision/fingerprint into README;
6. pass `python tools/ai_parallel.py validate`.

## README progress synchronization

README progress synchronization is mandatory durable state, not optional dashboard polish.

- Every PR that changes `.ai/state/CURRENT-STATE.yaml` MUST update `README.md` in the same PR.
- The README machine marker `AI_PROGRESS_SNAPSHOT` MUST mirror canonical `roadmap_percent`, `phase_percent`, current phase, active task, current milestone, and milestone status from `CURRENT-STATE.yaml`.
- Human-readable README progress, phase/task labels and progress bars MUST be refreshed from canonical repository state; agents may not invent or manually estimate percentages.
- CI/status-only interactions that make no repository state write do not create artificial README commits. The next durable state-changing milestone performs the required sync.
- `README.md` is an approved self-reconciliation surface so a terminal state+README reconciliation does not create an infinite protected-main observation loop.
- `python tools/supervisor_contract.py validate` fails closed when the README snapshot drifts, and PR-event validation fails when a durable state change omits README.
- The README churn guard applies only to non-durable prose/dashboard churn; it MUST NOT suppress this required progress sync.

## Strict AI execution order and change-aware CI

The following order is **mandatory** for every AI/Supervisor development interaction. It is not advisory and may not be reordered for convenience:

1. recover interrupted transactions and validate canonical repository state;
2. read `CURRENT-STATE.yaml`, `LAST-CHECKPOINT.md`, the active task, this control plane, and the AI-Native Plan;
3. confirm the exact active task/milestone and current protected/integration branch head before any write;
4. classify the intended change with the repository change-aware CI policy;
5. create/use only the registered branch/workstream allowed for that milestone;
6. execute one logical milestone and only its approved write scope;
7. run the local/fast checks required by that change class;
8. open/update the scoped PR and use the exact standalone `CI-Mode: full` marker whenever a certification, release/promotion, security-sensitive acceptance, or explicit exact-head contract requires the full Application + Security gate set even if the file diff is control-only;
9. wait for the required exact-head gates; never replace a required gate with Runner benchmarking;
10. merge only the verified exact head, then re-read repository state before any next write;
11. perform successor registration/task transition only as a separate guarded milestone unless repository safety requires an atomic coupled repair.

Deviation is permitted only for a demonstrated security/correctness/release blocker. The Supervisor must keep that exception bounded, preserve exact-head evidence, and record why the normal order could not safely be followed.

Change-aware CI is fail-closed and implemented by `tools/ci_change_policy.py`:

- changes limited to `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` are classified `control-only` unless explicitly forced full;
- any unknown, product, test, dependency, tool, Docker, configuration, migration, route, or workflow path defaults to full Application + Security CI;
- the exact standalone PR marker `CI-Mode: full` overrides a control-only classification and forces the heavy gate set;
- required check contexts are skipped only at **job level**, never by workflow-level PR path filtering, so GitHub still reports the required check;
- AI Continuity validates the classifier and its negative/positive tests on every governed PR/push;
- Runner sizing/cache/concurrency/architecture/toolchain benchmarking is not a required CI gate and stays deferred in the persistent Runner backlog until explicit coordinated batch activation.

The AI must follow the canonical plan/order above even when chat context suggests a shortcut. Repository state and these machine-validated instructions override conversational momentum.

## Durable AI Engineering Supervisor contract

This contract is mandatory, cross-phase, and stricter than conversational context. Repository/runtime evidence always outranks chat memory.

### Resume source of truth

On every start, `continue`, resume, interruption, connector/tool failure, or message-delivery timeout:

1. read `.ai/state/CURRENT-STATE.yaml` and `.ai/state/LAST-CHECKPOINT.md`;
2. resolve the exact current default/protected `main` SHA;
3. reconcile open Issues;
4. reconcile open PRs/MRs;
5. re-read deterministic task/research claims, `.ai/coordination/OPEN-WORK-QUEUE.yaml`, and `.ai/runner/RUNNER-BENCHMARK.yaml`;
6. read large historical checkpoints/archives only for a specific evidence conflict;
7. never repeat a write/merge/runtime action merely because a prior response was not delivered.

Compact state is a resume index and never overrides live repository/runtime truth.

### Non-recursive protected-main observation

`observed_main_sha` is the exact protected-main **snapshot-basis anchor** used when the durable state was computed. It is not a promise that the field must equal the future protected-main HEAD after the state itself is merged.

On every resume, compare the live protected-main SHA with the anchor:

- exact equality is current;
- if live main is a descendant and the entire anchor-to-main diff is limited to approved durable reconciliation surfaces (`CURRENT-STATE.yaml`, `LAST-CHECKPOINT.md`, rolling/archived execution journal files, coordination queue, Runner Benchmark, and the README progress snapshot), classify it as `self_reconciliation_descendant` and **do not create another state-only reconciliation PR**;
- if the anchor is not an ancestor, or any product/task/tool/workflow/instruction/other material path changed, classify it as material/conflicting drift and reconcile that evidence before new writable work.

This single-hop rule prevents recursive “update observed SHA -> merge -> SHA changed again” loops while still failing closed on real repository drift. `python tools/supervisor_contract.py validate-main-observation --current-main <sha>` is the machine check.

### One turn, one logical milestone

One user `continue`/resume turn normally advances exactly one bounded milestone: one PR reconciliation, one coherent persisted implementation, one exact-head verify/merge decision, or one post-merge durable reconciliation. Do not chain audit -> multiple implementations -> repeated polling -> merge -> post-merge audit -> unrelated next task.

### Next-action interactive option contract

Every Supervisor development handoff MUST expose the next valid repository actions so the user can advance development without reconstructing the command from prose.

- After repository evidence is reconciled, present **1 to 3** currently valid next-action options. The canonical/recommended action MUST come from the live accepted work path and `exact_next_safe_action` / `exact_next_action`; it may not be invented from stale chat context, but it is not permanently bound to option number 1.
- Each option has a short human label and a deterministic request payload. When the host surface supports clickable action buttons or suggestion controls, render the option as a click target whose selection initiates that exact request as a new user turn.
- When two or more valid options exist, assign the visible `1`/`2`/`3` numbers in a freshly shuffled order for each handoff. Randomization affects presentation only; it MUST NOT change action validity, priority, safety, or authorization.
- If the previously selected action identity and its visible number are known and at least two valid options exist, that same action MUST NOT reuse the same number on the next handoff. This anti-repeat rule takes precedence over an otherwise repeated shuffle. With only one valid option, number reuse is unavoidable and allowed.
- Mark the canonical action as **Recommended** (or equivalent) rather than forcing it to a fixed ordinal. Option-number mappings are ephemeral UI state and never become repository/runtime authority.
- When the user's message is only this repository's canonical GitHub URL, treat it as a read-only entry request: perform the normal resume/reconciliation reads, make no repository/runtime mutation from the URL alone, and return the shuffled valid next-action options. A later numeric selection starts a separately revalidated turn.
- A click/selection is a **request to resume**, not reusable execution authority. On selection, the Supervisor MUST rerun the normal resume order (compact state -> exact main -> Issues -> PRs -> claims/queue -> Runner Benchmark) and revalidate the selected action before any write, merge, provider, production, destructive, deployment, or other privileged action.
- If the selected option became stale, blocked, merged, or unsafe, do not execute the stale payload. Reconcile repository truth and return the newly valid next-action options.
- Never offer an option that bypasses an accepted actionable Issue/PR, jumps to a successor before the guarded transition, weakens security/CI, silently promotes deferred Runner work, or implies consumed/expired authorization is still valid.
- During `VERIFYING` or `WAITING_EXTERNAL`, prefer an option to re-check the exact accepted head and merge only if the required gates/review are green; do not offer unrelated implementation as the primary action.
- After a terminal milestone with no active accepted work path, the primary option may expose the separately authorized successor registration/transition when canonical state permits it.
- If the host cannot render interactive controls, fall back to numbered one-line action commands that the user can send unchanged. The absence of UI-button support must never hide the exact next valid action.

### Remote-call and timeout budget

Batch related reads. Read only evidence required by the active milestone. Perform at most one consolidated CI/status refresh per milestone by default. Tight polling and repeated unchanged reads are forbidden. A second refresh is allowed only after a material security/merge/incident/provider state transition and the exception must be recorded on a durable PR/Issue surface.

Before final exact-head CI observation, persist the milestone as `VERIFYING` or `WAITING_EXTERNAL`. If CI remains running, do not create a source/state-only commit merely to record pending CI; record run IDs externally when possible and end the milestone.

### Issues and PRs first — hard gate

New development is forbidden while an accepted actionable open Issue or PR is bypassed. An Issue already represented by an accepted PR is one work path; finish/review/fix that PR instead of duplicating work.

Required order before new development:

`Compact State -> Exact Main -> Open Issues -> Open PRs -> Claims/Queue -> Runner Benchmark -> New Work`

Deferred Runner items, standing governance ledgers, and explicitly authorization-blocked work are not silently promoted into actionable product work.

### Durable state before reporting

Before reporting a meaningful milestone complete, blocked, verifying, or waiting, reconcile compact state, checkpoint, rolling journal, coordination queue when changed, and Runner Benchmark when changed. `CURRENT-STATE.yaml` must record observed main SHA, active Issue/PR/branch, milestone/status, last completed milestone, exact next safe action, pending/blocked runner IDs, blockers, and timeout controls.

Limits are fail-closed: current state <= 12 KiB, checkpoint <= 16 KiB, active rolling journal <= 32 KiB. Historical journal segments are archived under `.ai/state/archive/`; archives are evidence and are not part of the normal resume read path.

### Runner Benchmark authority

`.ai/runner/RUNNER-BENCHMARK.yaml` is the machine-readable registry. Every material remote/container/browser/runtime/full-regression/performance workload records stable ID, source work package, workflow/command, exact source identity requirement, environment/matrix/input/fixture identity, authorization state, security-critical/merge-blocking flags, expected runner time, dedup key, status, and immutable terminal evidence.

Registration never grants execution authority. Safe non-blocking work defaults to the final coordinated batch. Security-critical, exact-head merge-required, migration/auth/secrets/data-safety, current-change integration-safety, and incident/recovery checks remain immediate. Consumed/expired/historical/destructive/provider/production/deployment/release authorization is never inferred or silently reused.

### Drift, security, migration, and public-status rules

At every resume reconcile stale main observations, merged/closed Issues/PRs, queue entries, Runner status and relevant commits since the recorded anchor. A merged item may not remain pending-merge.

Security is fail-closed: never weaken auth/authorization, CSRF/nonces, validation/escaping, required checks, tests, branch protection, secret handling, shared history, or execution authority to get green CI. A timeout or connector failure grants zero additional authority.

Migration changes require explicit review of idempotency, transaction boundaries, apply-success/marker-failure recovery, retries, rollback/restore, destructive recovery, concurrency, partial execution and backup/snapshot requirements. Destructive migration authority remains separate and explicit.

Every durable milestone PR that changes `CURRENT-STATE.yaml` must synchronize the README progress snapshot in the same PR. CI/status-only turns with no repository state write do not fabricate README churn. Other large README prose/dashboard changes remain limited to material public/module lifecycle truth or terminal closeout.

Third-party CI actions remain immutably pinned; credential persistence stays disabled unless reviewed; permissions are least-privilege; dangerous `pull_request_target` use requires separate review; dependency and distributable supply-chain audits remain fail-closed.

## Merge strategy

Registered workstream PRs target `main` and default to squash merge. Merge groups express ordering constraints. Independent lanes in the same group may develop in parallel but are merged one at a time; after each merge, all remaining active lanes synchronize latest `main` before continuing.

## Temporary Week-1 Shipping Mode

When `.ai/parallel/WEEK-1-SHIPPING-PLAN.md` is ACTIVE, `ship/week-1` is the sprint integration branch while `main` remains the protected release boundary.

- Writable implementation is limited to five primary lanes: backend, frontend, delivery, data, and QA/release. Additional agents may review or research read-only work but must not create overlapping writes.
- Grandfathered drain exception: workstreams already registered and occupied for the active task when Shipping Mode was activated may finish without being terminated solely to reach the five-writer target. No new writable slot may be added or reassigned above five during that drain. `TASK-0026` is the activation-time grandfathered task; after its transition, the five-writer shipping cap is hard.
- Sprint feature/workstream PRs target `ship/week-1` unless the Supervisor explicitly marks a change as main-only governance/release work.
- Before submission or resume, a sprint branch must contain the latest `ship/week-1` baseline and pass the `Shipping Fast Gate`.
- A merge/push to `ship/week-1` runs the full Application Foundation and AI Continuity integration wave.
- Only a green `ship/week-1` baseline is promoted to `main`; protected-main required checks and full Security Supply Chain CI remain mandatory there.
- A failed merge wave freezes only the affected dependency chain. Independent lanes may continue when they do not consume the broken contract.
- Serious integration failures require reproduction, a failing regression test, a fix, and rerun evidence before the affected chain resumes.
- Shipping Mode changes check placement, not safety requirements. It must not bypass consent, permission, security, migration, data-integrity, delivery-idempotency, or protected-main gates.

During Shipping Mode, the branch target and sync rules in this section override the default direct-to-`main` merge target above for sprint feature/workstream PRs. New-agent admission still begins from `main`; after assignment, the worker synchronizes the current shipping baseline before writable work.
