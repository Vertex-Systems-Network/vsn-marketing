#!/usr/bin/env python3
"""Run any AI coding CLI in a non-stop loop until no ready canonical work remains.

The agent CLI ends its own turn; this runner re-invokes it with the continuation
instruction from tools/ai_continue.py, so the batch does not depend on a human
typing "continue". Works with any headless agent CLI:

  python tools/ai_autopilot.py --agent codex     # OpenAI Codex / ChatGPT
  python tools/ai_autopilot.py --agent claude    # Claude Code
  python tools/ai_autopilot.py --cmd 'gemini -y -p {prompt}'

Bounded by --max-runs and --hours (default 5h, the Workspace batch window).
"""
import argparse
import shlex
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import ai_continue  # noqa: E402

AGENTS = {
    "codex": ["codex", "exec", "--full-auto", "{prompt}"],
    "claude": ["claude", "-p", "--permission-mode", "acceptEdits", "{prompt}"],
}

START_PROMPT = (
    "Follow AGENTS.md completely. Run the session-start recovery/validation commands, read the "
    "active task and last checkpoint, then run the continuous batch. {reason}"
)


def build(cmd: list[str], prompt: str) -> list[str]:
    return [part.replace("{prompt}", prompt) for part in cmd]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--agent", choices=sorted(AGENTS), default="codex")
    ap.add_argument("--cmd", help="custom command template containing {prompt}")
    ap.add_argument("--max-runs", type=int, default=50)
    ap.add_argument("--hours", type=float, default=5.0)
    args = ap.parse_args()
    cmd = shlex.split(args.cmd) if args.cmd else AGENTS[args.agent]
    deadline = time.monotonic() + args.hours * 3600
    failures = 0
    for run in range(1, args.max_runs + 1):
        v = ai_continue.verdict()
        if not v["continue"]:
            print(f"[autopilot] stop condition: {v['reason']}")
            return 0
        if time.monotonic() > deadline:
            print("[autopilot] batch window exhausted; resume with the same command")
            return 0
        print(f"[autopilot] run {run}: {v['task']} ({v['status']})", flush=True)
        rc = subprocess.run(build(cmd, START_PROMPT.format(reason=v["reason"])), cwd=ROOT).returncode
        failures = failures + 1 if rc else 0
        if failures >= 3:
            print("[autopilot] agent CLI failed 3 times in a row; check the CLI/auth")
            return 1
    print("[autopilot] max runs reached; resume with the same command")
    return 0


if __name__ == "__main__":
    sys.exit(main())
