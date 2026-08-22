# Recovery Protocol

Use this when a previous AI/developer session ended unexpectedly or the ledger may be stale.

1. Run `git status` and inspect current branch/HEAD/diff.
2. Read `CURRENT-STATE.yaml`, `LAST-CHECKPOINT.md`, active task, and test state.
3. Run `python tools/ai_state.py validate`.
4. Compare modified files/commits with the checkpoint.
5. Run the smallest reliable test set for the active task, then broader required tests if possible.
6. If code contains unrecorded progress, update task acceptance evidence/checkpoint/state; do not discard valid work just to match old text.
7. If checkpoint claims completion not supported by code/tests, downgrade task status and document the mismatch.
8. If architecture/contracts changed without ADR, stop feature work, create reconciliation blocker, and restore or formally propose the change.
9. Clear `needs_reconciliation` only when state, task registry, repository evidence, and tests agree.
10. Resume from the newly recorded exact next action.

Never solve uncertain recovery state by guessing what the previous model intended.
