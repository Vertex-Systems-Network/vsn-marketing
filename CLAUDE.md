# Claude Code Instructions

`AGENTS.md` is the authoritative agent contract for this repository. Follow it completely.

At the beginning of every Claude Code session run:

```bash
python tools/ai_txn.py recover
python tools/ai_txn.py validate
python tools/ai_state.py recover
python tools/ai_state.py validate
python tools/ai_journal.py validate
python tools/ai_policy.py
python tools/ai_context.py manifest
python tools/ai_state.py status
```

Read the active task and last checkpoint before editing. Do not infer project state from prior chats. Do not skip dependencies, but a generic active continuous batch MUST automatically cross into the next canonical dependency-ready task/phase after a guarded transition; that is normal roadmap advancement, not jumping ahead. All checkpoint/task-transition mutations must use `tools/ai_txn.py` so journal/state changes cannot be split by a crash. Routine errors, validator failures, CI failures and connector/tool failures are repaired or routed through documented fallbacks without asking the user. When approaching a real context/session limit, checkpoint using the interruption protocol in `AGENTS.md`; if the host still permits safe execution afterward, continue rather than handing control back only because a checkpoint was written.
