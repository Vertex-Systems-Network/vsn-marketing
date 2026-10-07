# Workspace 5-Hour Continuous Development Batch

Status: **ACTIVE DEFAULT FOR MUTATING WORKSPACE RESUMES**

This contract exists to keep repository development moving for one Workspace session without repeatedly returning to the user for ordinary in-scope repository decisions.

## Runtime envelope

- Target runtime: **300 minutes / the available Workspace credit window**, whichever ends first.
- A mutating `start`, `continue`, numeric next-action selection, or explicitly scoped development request starts or resumes this continuous batch mode unless the request says otherwise.
- A generic mutating `start`, `continue`, or `resume` with no narrower scope defaults to **maximum safe roadmap-frontier progress for the entire available Workspace credit window**, not one task, one PR, or one phase. After a task or phase is certified, automatically transition to the next canonical dependency-ready task/phase and continue while credit remains. Crossing a phase boundary is routine when the roadmap already defines the successor and no separate human/external authority is required. If research/registration is required and the canonical roadmap already defines that work, perform it automatically; never invent undeclared roadmap work.
- URL-only repository entry remains read-only and does not start a mutating batch.
- Repository evidence outranks chat memory. Every fresh session or recovery still performs the normal compact-state/main/Issues/PRs/queue/Runner reconciliation before writes.
- The batch objective is the highest-priority accepted repository work path consistent with the user's request and canonical state. For generic start/continue/resume, the objective is the **safe ready frontier of the canonical roadmap for the remaining credit window**. Do not ask the user to choose again when repository evidence already determines the next safe action, and do not treat task/PR/phase completion as a handoff boundary while another safe canonical successor exists.
- **A single user turn may contain multiple substantial implementation slices, PR/CI/merge cycles, guarded task transitions, and phase transitions.** “One substantial batch” is a sizing rule for each coherent work slice, never a stop/handoff rule for the Workspace turn. After each slice, immediately loop back through live-truth reconciliation and continue while safe canonical work and credit remain.

## No-reconfirmation rule

Once a mutating batch has been started, the Supervisor MUST NOT request repeated consent for ordinary repository work already inside the batch scope.

Without another user confirmation, the Supervisor may:

- create/synchronize scoped development branches;
- inspect code, issues, PRs, reviews, CI, logs, commits, state, and repository documentation;
- edit implementation, tests, control-plane files, docs, and README inside the authorized repository scope;
- open/update/close a stale duplicate PR when repository evidence proves the authoritative replacement;
- diagnose same-scope test/CI failures, patch the root cause, update the same carrier, and rerun required checks;
- resolve ordinary merge conflicts from canonical repository evidence;
- review and merge a verified current-head PR when required checks/review are green and the merge is already inside the batch objective;
- perform guarded task transitions and automatically continue to the next dependency-ready task when that next task is inside the declared batch objective;
- update durable checkpoints, progress mirrors, queues, and coordination evidence required by the work.

The Supervisor MUST NOT turn a validator failure, normal CI/test/lint/type failure, formatting failure, stale branch, duplicate PR, merge conflict, dependency conflict, missing progress mirror, state/journal drift, transient connector/tool failure, or reversible same-scope implementation mistake into a user confirmation request. Diagnose, repair, use documented fallback/recovery, and continue.

## Authority that is NOT implied

Continuous mode does not create authority for production/provider side effects, secret disclosure/rotation, billing/payment actions, destructive data operations, destructive migrations, branch-protection weakening, release/deployment authority, legal/compliance owner approval, or another external system action whose repository contract requires current explicit authorization.

When such an action is needed:

1. continue every independent safe repository task that does not require that authority;
2. persist the exact blocker and next safe action;
3. quarantine the blocked action and continue on the next safe canonical roadmap-frontier item; end the batch only when no safe independent frontier work remains and the blocked human-only action is the sole remaining path;
4. at the final handoff, report the one concrete human-only requirement as evidence; do not interrupt the active batch to ask for confirmation or a generic `continue`.

Never weaken security, tests, permissions, tenant isolation, required checks, migration safety, or audit controls to avoid a blocker.

## Blocker and ambiguity handling

Inside an active generic continuous batch, the Supervisor resolves decisions instead of handing them back to the user:

- **Repository or technical blocker:** diagnose from code, logs, tests, CI, history, and canonical contracts; apply the smallest safe root-cause repair; rerun the required checks; continue.
- **Ambiguous but safe implementation choice:** choose deterministically using canonical plan priority, existing architecture/contracts, least privilege, smallest reversible change, and test evidence. Do not ask the user to pick between mechanically resolvable options.
- **Human-only external authority:** quarantine only that action, record the exact missing authority/input, and continue every other safe dependency-ready roadmap-frontier item. Do not request confirmation while safe repository work remains.
- **Tool/connector/path failure:** use an available safe fallback path, recover durable state, and continue. A transient tool failure is not a user-decision boundary.
- **Safety/security/correctness conflict:** fail closed on the affected action, preserve evidence, and continue independent safe work. End only when no safe canonical work remains.

A blocker is therefore a routing signal, not a default conversation handoff.

## Automatic execution loop

Repeat this loop while Workspace credit remains and the batch objective is not complete:

1. **Recover + validate.** Recover interrupted state, validate continuity, and read compact state.
2. **Resolve live truth.** Resolve exact protected `main`, then reconcile open Issues, open PRs, accepted work queue, claims, and Runner Benchmark.
3. **Choose work automatically.** Select the highest-priority accepted work path. Prefer an existing authoritative PR over creating duplicate work.
4. **Execute a substantial slice.** Implement as much coherent work as safely fits the current dependency boundary.
5. **Run focused checks.** Run local/fast checks required by the changed class.
6. **Carrier management.** Create/update the scoped PR. Avoid micro-PRs and evidence-only churn.
7. **CI failure handling.** If required CI fails, inspect the failed job/step/logs, identify the same-scope root cause, patch it, test it, and rerun. Do not ask the user what to do.
8. **Merge handling.** When exact-head gates/review are green, merge according to repository policy without asking for a second confirmation.
9. **Post-merge continuation.** Re-read live state and continue directly to the next dependency-ready action. If the active phase closes, enter the next canonical dependency-ready phase automatically when no separate authority boundary applies.
10. **Checkpoint.** Persist a checkpoint at material boundaries, before context/tool exhaustion, at a hard external wait, or when the Workspace credit window ends.

Do not emit a user-facing next-action handoff at every internal task/PR/CI boundary. Next-action options are for the final batch handoff or a genuine hard stop.

## CI and external waits

- Tight polling remains forbidden.
- A failed required check is work, not a stop condition: diagnose and repair the same scope.
- A running external check is not automatically a batch stop. Continue independent dependency-ready work that does not consume the pending artifact.
- CI observation uses bounded backoff: up to **4 normal exact-head observations per gate cycle**, and up to **12** when a durable material state-transition exception is recorded. Observations must be state-driven/meaningfully spaced; unchanged rapid rereads remain forbidden.
- If no safe independent work exists, persist `WAITING_EXTERNAL` with exact run/head evidence and use the remaining bounded observation budget before ending the batch. CI waiting never becomes a user confirmation request.
- Never create a state-only commit just to narrate pending CI.

## Duplicate/stale work policy

When two PRs cover the same accepted work:

- compare base lineage, changed files, current head, CI evidence, and canonical state;
- choose the clean/current authoritative carrier deterministically;
- mark the stale carrier superseded/close it when safe and supported by evidence;
- continue on the authoritative carrier;
- do not ask the user to choose between mechanically distinguishable duplicates.

## Scope chaining

Continuous mode may cross internal milestones, dependent tasks, and canonical phase boundaries **when they remain on the same safe roadmap frontier and require no separate authority**.

For a generic `start`/`continue`/`resume`, the declared batch objective is maximum safe canonical roadmap progress until the Workspace credit window is exhausted or no safe ready work remains. An explicit PR-only, task-only, phase-only, audit-only, or other narrower user instruction overrides that default.

- A generic continuous batch automatically advances across dependency-ready tasks and phases while credit remains.
- A phase-only batch stops at that phase boundary; a task-only batch stops when that task is certified/merged.
- Do not cross into deployment/release, production/provider actions, destructive operations, or unrelated/undeclared roadmap work without the separate authority or canonical declaration those actions require.
- Research-first and guarded-transition requirements still apply; satisfy them automatically when possible rather than asking for routine permission.

## Stop conditions

**Conversation return is forbidden merely because one coherent/substantial slice finished.** Before any user-facing handoff, the Supervisor MUST prove that at least one stop condition below is true. If none is true, select the next safe canonical work item and continue in the same turn.

The batch ends only when one of these is true:

1. the explicit narrow batch objective is complete, or for a generic continuous batch the canonical roadmap has no safe dependency-ready work remaining;
2. the 300-minute / available Workspace credit window is exhausted;
3. a genuine human-only external authority/input is the sole remaining path across the safe roadmap frontier;
4. a safety/security/correctness conflict cannot be resolved from repository evidence and no independent safe frontier work remains;
5. all available repository execution paths are unavailable after bounded recovery/fallback attempts, leaving no safe mutation/read path to continue.

Normal development friction, completion of one substantial work slice, a completed task, a completed PR, a completed phase, a stale carrier, a merge conflict, a failed same-scope check, a transient connector failure, or a pending external check is not a stop condition when any independent safe canonical work remains. Prefer alternate available repository/tool paths and continue rather than handing control back to the user.

## End-of-batch handoff

At the end, provide one compact report with:

- repository name;
- exact resulting `main` and active PR/head if any;
- work completed in this batch;
- unresolved blocker(s), if any;
- exact next safe action;
- current module/phase progress and overall roadmap progress from canonical state;
- 1–3 next-action options only now, unless the objective is fully complete.

Never claim work continued in the background after the Workspace/session ended. The contract governs what the agent must do **within each active Workspace turn/session**; once the host ends execution, resume from durable repository state on the next session.
