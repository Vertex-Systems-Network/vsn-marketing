#!/usr/bin/env python3
"""Synchronize the README progress mirror with CURRENT-STATE.yaml.

Updates the AI_PROGRESS_SNAPSHOT marker, headline metrics and text progress
bars so `tools/supervisor_contract.py validate` passes after state changes.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def bar(percent: float) -> str:
    filled = int(round(percent / 5))
    return "█" * filled + "░" * (20 - filled)


def fmt(value: float) -> str:
    return f"{value:g}"


def main() -> None:
    state = json.loads((ROOT / ".ai/state/CURRENT-STATE.yaml").read_text())
    execution = state["execution"]
    roadmap = float(state["progress"]["roadmap_percent"])
    phase = float(state["progress"]["phase_percent"])
    current_phase = execution["current_phase"]
    marker = (
        f"<!-- AI_PROGRESS_SNAPSHOT roadmap={fmt(roadmap)} phase={fmt(phase)} "
        f"current_phase={current_phase} active_task={execution['active_task']} "
        f"milestone={state['current_milestone']} status={state['milestone_status']} -->"
    )
    readme = ROOT / "README.md"
    text = readme.read_text()
    text = re.sub(r"<!-- AI_PROGRESS_SNAPSHOT .*? -->", marker, text)
    text = re.sub(r"\*\*Overall roadmap progress: [\d.]+%\*\*", f"**Overall roadmap progress: {roadmap:.2f}%**", text)
    text = re.sub(r"\*\*Current phase: PHASE-\d+ — [\d.]+%\*\*", f"**Current phase: {current_phase} — {phase:.2f}%**", text)
    text = re.sub(
        r"\*\*Current milestone: .*?\*\*",
        f"**Current milestone: {state['current_milestone']} — {state['milestone_status']}**",
        text,
    )
    text = re.sub(r" Overall  \[[█░]{20}\] [\d.]+%", f" Overall  [{bar(roadmap)}] {roadmap:.2f}%", text)
    text = re.sub(
        r"Phase \d+ \[[█░]{20}\] [\d.]+%",
        f"Phase {int(current_phase.split('-')[1])} [{bar(phase)}] {phase:.2f}%",
        text,
    )
    readme.write_text(text)
    print(marker)


if __name__ == "__main__":
    main()
