# Recovery Protocol

Use this when a previous AI/developer session ended unexpectedly or the ledger may be stale.

1. Run `python tools/ai_state.py recover` first. It validates the ledger, inspects the working tree, and reports unsynchronized product changes.
2. Read `CURRENT-STATE.yaml`, `LAST-CHECKPOINT.md`, active task, and test state.
3. Confirm the checkpoint `State fingerprint` matches the current execution ledger. `python tools/ai_state.py validate` enforces this automatically.
4. Inspect current branch/HEAD/diff and compare modified files/commits with the checkpoint.
5. Run the smallest reliable test set for the active task, then broader required tests if possible.
6. If code contains unrecorded progress, update task acceptance evidence and create a synchronized checkpoint; do not discard valid work just to match old text.
7. If checkpoint claims completion not supported by code/tests, downgrade task status and document the mismatch.
8. If architecture/contracts changed without ADR, stop feature work, create a reconciliation blocker, and restore or formally propose the change.
9. Clear `needs_reconciliation` only when state, task registry, repository evidence, checkpoint fingerprint, and tests agree.
10. Before handing off, run `python tools/ai_state.py validate` and resume only from the newly recorded exact next action.

Never solve uncertain recovery state by guessing what the previous model intended. Never manually force a task transition to bypass false acceptance criteria or incomplete dependencies.
