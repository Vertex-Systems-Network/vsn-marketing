# Workspace 5-Hour Continuous Development Batch

Status: **ACTIVE DEFAULT FOR MUTATING WORKSPACE RESUMES**

This contract exists to keep repository development moving for one Workspace session without repeatedly returning to the user for ordinary in-scope repository decisions.

## Runtime envelope

- Target runtime: **300 minutes / the available Workspace credit window**, whichever ends first.
- A mutating `start`, `continue`, numeric next-action selection, or explicitly scoped development request starts or resumes this continuous batch mode unless the request says otherwise.
- URL-only repository entry remains read-only and does not start a mutating batch.
- Repository evidence outranks chat memory. Every fresh session or recovery still performs the normal compact-state/main/Issues/PRs/queue/Runner reconciliation before writes.
- The batch objective is the highest-priority accepted repository work path consistent with the user's request and canonical state. Do not ask the user to choose again when repository evidence already determines the next safe action.

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

The Supervisor MUST NOT turn a normal CI failure, formatting failure, stale branch, duplicate PR, merge conflict, missing progress mirror, or same-scope test failure into a user confirmation request. Diagnose and continue.

## Authority that is NOT implied

Continuous mode does not create authority for production/provider side effects, secret disclosure/rotation, billing/payment actions, destructive data operations, destructive migrations, branch-protection weakening, release/deployment authority, legal/compliance owner approval, or another external system action whose repository contract requires current explicit authorization.

When such an action is needed:

1. continue every independent safe repository task that does not require that authority;
2. persist the exact blocker and next safe action;
3. stop only when the blocker is the sole remaining path inside the batch objective;
4. report the one concrete human-only requirement instead of repeatedly asking broad consent questions.

Never weaken security, tests, permissions, tenant isolation, required checks, migration safety, or audit controls to avoid a blocker.

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
9. **Post-merge continuation.** Re-read live state and continue directly to the next dependency-ready action within the batch objective.
10. **Checkpoint.** Persist a checkpoint at material boundaries, before context/tool exhaustion, at a hard external wait, or when the Workspace credit window ends.

Do not emit a user-facing next-action handoff at every internal task/PR/CI boundary. Next-action options are for the final batch handoff or a genuine hard stop.

## CI and external waits

- Tight polling remains forbidden.
- A failed required check is work, not a stop condition: diagnose and repair the same scope.
- A running external check is not automatically a batch stop. Continue independent dependency-ready work that does not consume the pending artifact.
- If no safe independent work exists, persist `WAITING_EXTERNAL` with exact run/head evidence. Re-observe only within the repository's bounded refresh policy.
- Never create a state-only commit just to narrate pending CI.

## Duplicate/stale work policy

When two PRs cover the same accepted work:

- compare base lineage, changed files, current head, CI evidence, and canonical state;
- choose the clean/current authoritative carrier deterministically;
- mark the stale carrier superseded/close it when safe and supported by evidence;
- continue on the authoritative carrier;
- do not ask the user to choose between mechanically distinguishable duplicates.

## Scope chaining

Continuous mode may cross internal milestones and dependent tasks **only when they are part of the same declared batch objective**.

- A phase-closure batch may automatically advance across dependency-ready tasks in that phase.
- A task-only batch stops when that task is certified/merged.
- Do not cross into a later phase, deployment/release, or unrelated roadmap work unless the starting batch objective includes that boundary and repository policy permits it.
- Research-first and guarded-transition requirements still apply; satisfy them automatically when possible rather than asking for routine permission.

## Stop conditions

The batch ends only when one of these is true:

1. the declared batch objective is complete and durably reconciled;
2. the 300-minute / Workspace credit window is exhausted;
3. a genuine human-only external authority/input is the sole remaining path;
4. a safety/security/correctness conflict cannot be resolved from repository evidence;
5. the host/tooling makes further repository execution impossible after recovery attempts.

Normal development friction is not a stop condition.

## End-of-batch handoff

At the end, provide one compact report with:

- repository name;
- exact resulting `main` and active PR/head if any;
- work completed in this batch;
- unresolved blocker(s), if any;
- exact next safe action;
- current module/phase progress and overall roadmap progress from canonical state;
- 1–3 next-action options only now, unless the objective is fully complete.

Never claim work continued in the background after the Workspace/session ended. Resume from durable repository state on the next session.
