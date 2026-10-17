#!/usr/bin/env python3
"""Vendor-neutral continuation check for any AI agent (Claude, Codex/ChatGPT, Gemini...).

Exit 0 and print the continuation instruction when canonical work is still ready
(task ready/in_progress, 0 blockers); exit 3 when a documented stop condition holds.
`--json` prints a machine-readable verdict. Used by the Claude Stop hook and by
tools/ai_autopilot.py.
"""
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTINUE_STATES = {"ready", "in_progress"}

INSTRUCTION = (
    "Continuous batch is active: {task} is {status} with 0 blockers. Do not hand off, "
    "summarize-and-stop, or ask for confirmation (AGENTS.md, "
    ".ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md). Execute the next substantial slice now; "
    "after a guarded transition continue with the next dependency-ready task. If near the "
    "context limit, checkpoint via tools/ai_txn.py. Stop only when a documented stop condition is true."
)


def verdict() -> dict:
    try:
        out = subprocess.run([sys.executable, "tools/ai_state.py", "status"], cwd=ROOT,
                             capture_output=True, text=True, timeout=60).stdout
    except (OSError, subprocess.SubprocessError) as exc:
        return {"continue": False, "reason": f"state unavailable: {exc}"}
    task = re.search(r"^TASK\s+(.+)$", out, re.M)
    status = re.search(r"^TASK STATUS\s+(\S+)", out, re.M)
    blockers = re.search(r"^BLOCKERS\s+(\d+)", out, re.M)
    if not (task and status and blockers):
        return {"continue": False, "reason": "state status unparseable"}
    info = {"task": task.group(1).strip(), "status": status.group(1), "blockers": int(blockers.group(1))}
    if info["status"] in CONTINUE_STATES and info["blockers"] == 0:
        return {"continue": True, "reason": INSTRUCTION.format(**info), **info}
    return {"continue": False, "reason": "no ready unblocked canonical task", **info}


def main() -> int:
    v = verdict()
    print(json.dumps(v) if "--json" in sys.argv else v["reason"])
    return 0 if v["continue"] else 3


if __name__ == "__main__":
    sys.exit(main())
