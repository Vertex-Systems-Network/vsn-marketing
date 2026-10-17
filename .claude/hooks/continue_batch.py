#!/usr/bin/env python3
"""Claude Code Stop hook: block ending the turn while tools/ai_continue.py says work is ready.

Bounded per session (AI_MAX_CONTINUATIONS, default 40); AI_ALLOW_STOP=1 disables it.
"""
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(os.environ.get("CLAUDE_PROJECT_DIR", Path(__file__).resolve().parents[2]))
sys.path.insert(0, str(ROOT / "tools"))
import ai_continue  # noqa: E402

MAX_CONTINUATIONS = int(os.environ.get("AI_MAX_CONTINUATIONS", "40"))


def main() -> int:
    if os.environ.get("AI_ALLOW_STOP") == "1":
        return 0
    try:
        payload = json.load(sys.stdin)
    except ValueError:
        payload = {}
    session = re.sub(r"[^A-Za-z0-9_-]", "", str(payload.get("session_id", "default")))[:64]
    counter = ROOT / ".claude" / f".continue-{session}"
    count = int(counter.read_text() or 0) if counter.exists() else 0
    if count >= MAX_CONTINUATIONS:
        return 0
    v = ai_continue.verdict()
    if not v["continue"]:
        return 0
    counter.write_text(str(count + 1))
    print(json.dumps({"decision": "block", "reason": v["reason"]}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
