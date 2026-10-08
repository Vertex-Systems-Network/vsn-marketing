# VSN Organization Next-Action Options Contract

This repository adopts the Vertex Systems Network interactive AI-development handoff standard.

## User-facing handoff

For mutating development intent (`start`, `continue`, `resume`, or an explicitly scoped implementation request), **do not ask the user to choose the next action**. Reconcile repository truth, select the highest-priority safe canonical action deterministically, and execute it under `.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md`. During an active batch, do not emit an intermediate handoff merely because one substantial slice finished, a PR was opened, CI failed/passed, a merge completed, a blocker was recorded, or a dependency-ready task/phase became available.

Interactive/numbered options are reserved for (a) URL-only read-only repository entry, or (b) an explicit user request to see choices. At a genuine terminal hard stop, report the single exact required human action and the canonical resume action; do not ask a broad choice question.

- When options are explicitly allowed by the rule above, always include the canonical/recommended next action, but do not bind it permanently to option 1.
- When two or more valid options exist, reshuffle the visible 1/2/3 numbering on every handoff.
- If the previously selected action identity and number are known, that same action must move to a different visible number on the next handoff. With only one valid action, number reuse is allowed.
- Mark the canonical action as **Recommended**. Numbering is ephemeral presentation state and never changes priority, safety, scope, or authorization.
- A reply containing only an option number starts/resumes the corresponding continuous batch after repository revalidation. Re-read current repository state before the first mutation. If the selected payload became stale, blocked, merged, or unsafe, fail closed on that stale payload, reconcile repository truth, and automatically route to the current canonical safe equivalent/successor when it remains inside the same authorized batch objective. Do not ask the user to select again while such a safe canonical route exists. Only return new options when no safe in-objective route exists or no active continuous batch was authorized. Once revalidated, do not ask again for routine in-scope repository consent at internal PR/CI/task boundaries.
- Prefer substantial product/control batches over micro-options. “Substantial” controls slice size, not turn count: after one substantial slice completes inside continuous mode, automatically start the next safe canonical slice rather than returning options. Do not offer a standalone post-merge reconciliation option when its evidence can safely ride with the next substantial PR; reserve standalone reconciliation for task/phase acceptance, guarded transitions, release/security/recovery, material drift, or no-safe-successor cases.
- Interactive buttons/numbered commands are not part of normal mutating development flow. They may be used only for URL-only read-only entry or when the user explicitly asks for choices.

## URL-only repository entry

When the user's message contains only this repository's canonical GitHub URL (optionally with surrounding whitespace), treat it as a read-only development entry request.

1. Resolve the repository and default/protected branch.
2. Read this repository's durable/current state and governing instructions.
3. Reconcile open Issues first, then open PRs, then any repository-specific coordination/runner state required by local rules.
4. Do **not** create a branch, commit, PR, merge, deployment, provider call, destructive action, or other mutation from the URL alone.
5. Respond with 1 to 3 shuffled valid next-action options and mark the canonical one **Recommended**.
6. The user's subsequent number selection initiates the normal fully revalidated development turn.

## Progress updates are nonterminal

During mutating continuous development, a status/progress update is informational only. It MUST NOT terminate the active batch while the current turn can still execute. “Next: once CI finishes…”, a pending-check summary, a checkpoint summary, or an in-progress task report is not a valid handoff. Continue with state-driven observation, repair, merge, reconciliation, or the next safe canonical slice until a documented stop condition is actually reached.

## Hard-stop presentation

A genuine human-only boundary must not be phrased as a broad confirmation request. Do not ask “Should I continue?”, “May I fix this?”, “Do you want me to retry?”, or equivalent.

If the human-only boundary is the sole remaining path, report the exact blocked action, the exact external authority/input required, why repository evidence cannot safely supply it, and the exact safe resume action after that input exists. If any independent safe roadmap work exists, do not hand off; continue that work.

## Safety and local authority

Repository-specific governance, security, exact-head CI, approval, migration, production/provider, release, and Fast Batch Development rules remain authoritative and may be stricter than this interaction contract. This file never grants execution authority and never permits bypassing an accepted actionable Issue/PR or deferred work boundary.
