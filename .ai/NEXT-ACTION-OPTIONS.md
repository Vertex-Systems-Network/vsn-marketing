# VSN Organization Next-Action Options Contract

This repository adopts the Vertex Systems Network interactive AI-development handoff standard.

## User-facing handoff

Outside an active 5-hour continuous Workspace batch, expose 1 to 3 currently valid next actions derived from live repository evidence. During an active batch, do not emit an intermediate handoff merely because a PR was opened, CI failed/passed, a validator failed, a tool/connector failed, a merge completed, a blocker was recorded, or a dependency-ready task/phase became available. Continue automatically under `.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md`; a blocked lane is not a handoff while any independent safe canonical work remains.

- Always include the canonical/recommended next action, but do not bind it permanently to option 1.
- When two or more valid options exist, reshuffle the visible 1/2/3 numbering on every handoff.
- If the previously selected action identity and number are known, that same action must move to a different visible number on the next handoff. With only one valid action, number reuse is allowed.
- Mark the canonical action as **Recommended**. Numbering is ephemeral presentation state and never changes priority, safety, scope, or authorization.
- A reply containing only an option number starts/resumes the corresponding continuous batch after repository revalidation. Re-read current repository state before the first mutation. If the selected payload became stale, blocked, merged, or unsafe, fail closed on that stale payload, reconcile repository truth, and automatically route to the current canonical safe equivalent/successor when it remains inside the same authorized batch objective. Do not ask the user to select again while such a safe canonical route exists. Only return new options when no safe in-objective route exists or no active continuous batch was authorized. Once revalidated, do not ask again for routine in-scope repository consent at internal PR/CI/task boundaries.
- Prefer substantial product/control batches over micro-options. Do not offer a standalone post-merge reconciliation option when its evidence can safely ride with the next substantial PR; reserve standalone reconciliation for task/phase acceptance, guarded transitions, release/security/recovery, material drift, or no-safe-successor cases.
- Interactive buttons may be used when the host supports them; otherwise numbered one-line options are the mandatory fallback.

## URL-only repository entry

When the user's message contains only this repository's canonical GitHub URL (optionally with surrounding whitespace), treat it as a read-only development entry request.

1. Resolve the repository and default/protected branch.
2. Read this repository's durable/current state and governing instructions.
3. Reconcile open Issues first, then open PRs, then any repository-specific coordination/runner state required by local rules.
4. Do **not** create a branch, commit, PR, merge, deployment, provider call, destructive action, or other mutation from the URL alone.
5. Respond with 1 to 3 shuffled valid next-action options and mark the canonical one **Recommended**.
6. The user's subsequent number selection initiates the normal fully revalidated development turn.

## Hard-stop presentation

A genuine human-only boundary must not be phrased as a broad confirmation request. Do not ask “Should I continue?”, “May I fix this?”, “Do you want me to retry?”, or equivalent.

If the human-only boundary is the sole remaining path, report the exact blocked action, the exact external authority/input required, why repository evidence cannot safely supply it, and the exact safe resume action after that input exists. If any independent safe roadmap work exists, do not hand off; continue that work.

## Safety and local authority

Repository-specific governance, security, exact-head CI, approval, migration, production/provider, release, and Fast Batch Development rules remain authoritative and may be stricter than this interaction contract. This file never grants execution authority and never permits bypassing an accepted actionable Issue/PR or deferred work boundary.
