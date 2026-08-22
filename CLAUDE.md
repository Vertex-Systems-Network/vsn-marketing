# Claude Code Instructions

`AGENTS.md` is the authoritative agent contract for this repository. Follow it completely.

At the beginning of every Claude Code session run:

```bash
python tools/ai_state.py validate
python tools/ai_state.py status
```

Read the active task and last checkpoint before editing. Do not infer project state from prior chats. Do not jump ahead to a later phase. When approaching a context limit, checkpoint immediately using the interruption protocol in `AGENTS.md` instead of continuing with partial undocumented work.
