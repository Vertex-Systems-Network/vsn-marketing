#!/usr/bin/env python3
"""Crash-recoverable transactional wrapper for VSN Marketing continuity mutations."""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class TransactionError(RuntimeError):
    pass


class TxnCoordinator:
    def __init__(self, root: Path):
        self.root = root
        self.git_dir = root / ".git"
        self.txn_dir = self.git_dir / "vsn-ai-txn"
        self.lock_path = self.git_dir / "vsn-ai-txn.lock"
        self.manifest_path = self.txn_dir / "manifest.json"

    def _require_git_dir(self) -> None:
        if not self.git_dir.is_dir():
            raise TransactionError(".git directory is required for crash-recoverable continuity transactions")

    @staticmethod
    def _pid_alive(pid: int) -> bool:
        if pid <= 0:
            return False
        try:
            os.kill(pid, 0)
            return True
        except ProcessLookupError:
            return False
        except PermissionError:
            return True
        except OSError:
            return True

    def _read_lock(self) -> dict:
        try:
            return json.loads(self.lock_path.read_text(encoding="utf-8"))
        except Exception:
            return {"pid": -1}

    def acquire(self, operation: str) -> None:
        self._require_git_dir()
        payload = {
            "pid": os.getpid(),
            "operation": operation,
            "started_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        }
        try:
            fd = os.open(self.lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        except FileExistsError as exc:
            lock = self._read_lock()
            raise TransactionError(
                f"continuity mutation lock already exists (pid={lock.get('pid')}, operation={lock.get('operation')}); "
                "run `python tools/ai_txn.py recover` before another mutation"
            ) from exc
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, separators=(",", ":"))
            handle.write("\n")

    def begin(self, operation: str, paths: list[Path]) -> None:
        self.acquire(operation)
        try:
            if self.txn_dir.exists():
                raise TransactionError("pending continuity transaction exists; recover it before starting another")
            self.txn_dir.mkdir(parents=True)
            backups = []
            for index, path in enumerate(paths):
                rel = str(path.relative_to(self.root)).replace("\\", "/")
                backup = self.txn_dir / f"{index:03d}.bak"
                existed = path.exists()
                if existed:
                    backup.write_bytes(path.read_bytes())
                backups.append({"path": rel, "backup": backup.name, "existed": existed})
            manifest = {
                "schema_version": 1,
                "operation": operation,
                "pid": os.getpid(),
                "started_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "backups": backups,
            }
            self.manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
        except Exception:
            self._remove_lock()
            if self.txn_dir.exists():
                shutil.rmtree(self.txn_dir, ignore_errors=True)
            raise

    def _remove_lock(self) -> None:
        try:
            self.lock_path.unlink()
        except FileNotFoundError:
            pass

    def commit(self) -> None:
        if self.txn_dir.exists():
            shutil.rmtree(self.txn_dir)
        self._remove_lock()

    def restore_pending(self, *, force: bool = False) -> bool:
        if not self.txn_dir.exists():
            if self.lock_path.exists():
                lock = self._read_lock()
                pid = int(lock.get("pid", -1)) if str(lock.get("pid", "")).lstrip("-").isdigit() else -1
                if self._pid_alive(pid) and not force:
                    raise TransactionError(f"continuity mutation is still owned by live pid {pid}")
                self._remove_lock()
            return False
        lock = self._read_lock() if self.lock_path.exists() else {"pid": -1}
        pid = int(lock.get("pid", -1)) if str(lock.get("pid", "")).lstrip("-").isdigit() else -1
        if self._pid_alive(pid) and pid != os.getpid() and not force:
            raise TransactionError(f"pending transaction is owned by live pid {pid}; refusing recovery")
        manifest = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        for item in manifest.get("backups", []):
            target = self.root / item["path"]
            backup = self.txn_dir / item["backup"]
            if item.get("existed"):
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(backup.read_bytes())
            elif target.exists():
                target.unlink()
        shutil.rmtree(self.txn_dir)
        self._remove_lock()
        return True

    def assert_clean(self) -> None:
        if self.txn_dir.exists() or self.lock_path.exists():
            raise TransactionError("pending/stale continuity transaction detected; run `python tools/ai_txn.py recover`")


def run_tool(args: list[str]) -> None:
    proc = subprocess.run([sys.executable, *args], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if proc.stdout:
        print(proc.stdout, end="")
    if proc.returncode != 0:
        raise TransactionError(f"command failed ({proc.returncode}): {' '.join(args)}")


def validate_integrity() -> None:
    run_tool(["tools/ai_state.py", "validate"])
    run_tool(["tools/ai_journal.py", "validate"])
    run_tool(["tools/ai_context.py", "manifest"])


def task_path(task_id: str) -> Path:
    return ROOT / ".ai" / "tasks" / f"{task_id}.yaml"


def checkpoint(args) -> None:
    coordinator = TxnCoordinator(ROOT)
    paths = [
        ROOT / ".ai/state/CURRENT-STATE.yaml",
        ROOT / ".ai/state/LAST-CHECKPOINT.md",
        ROOT / ".ai/state/EXECUTION-JOURNAL.jsonl",
    ]
    coordinator.begin("checkpoint", paths)
    try:
        run_tool(["tools/ai_state.py", "checkpoint", "--summary", args.summary, "--tests", args.tests, "--next", args.next_action])
        run_tool(["tools/ai_journal.py", "record", "--type", "checkpoint", "--summary", args.summary])
        validate_integrity()
    except BaseException:
        coordinator.restore_pending(force=True)
        raise
    coordinator.commit()


def transition(args) -> None:
    coordinator = TxnCoordinator(ROOT)
    paths = [
        task_path(args.complete), task_path(args.next_task),
        ROOT / ".ai/tasks/INDEX.yaml", ROOT / ".ai/roadmap/ROADMAP.yaml",
        ROOT / ".ai/state/CURRENT-STATE.yaml", ROOT / ".ai/state/LAST-CHECKPOINT.md",
        ROOT / ".ai/state/EXECUTION-JOURNAL.jsonl",
    ]
    coordinator.begin("task_transition", paths)
    try:
        command = [
            "tools/ai_state.py", "transition", "--complete", args.complete, "--next", args.next_task,
            "--evidence", args.evidence, "--tests", args.tests,
        ]
        if args.dry_run:
            command.append("--dry-run")
        run_tool(command)
        if not args.dry_run:
            run_tool([
                "tools/ai_journal.py", "record", "--type", "task_transition",
                "--summary", f"{args.complete} completed; {args.next_task} activated. {args.evidence}",
            ])
            validate_integrity()
    except BaseException:
        coordinator.restore_pending(force=True)
        raise
    coordinator.commit()


def recover(force: bool) -> None:
    coordinator = TxnCoordinator(ROOT)
    restored = coordinator.restore_pending(force=force)
    print("Recovered and rolled back interrupted continuity transaction." if restored else "No interrupted continuity transaction found.")
    validate_integrity()


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing transactional continuity mutations")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    rec = sub.add_parser("recover"); rec.add_argument("--force", action="store_true")
    cp = sub.add_parser("checkpoint")
    cp.add_argument("--summary", required=True); cp.add_argument("--tests", required=True); cp.add_argument("--next", dest="next_action", required=True)
    tr = sub.add_parser("transition")
    tr.add_argument("--complete", required=True); tr.add_argument("--next", dest="next_task", required=True)
    tr.add_argument("--evidence", required=True); tr.add_argument("--tests", required=True); tr.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        if args.command == "validate":
            TxnCoordinator(ROOT).assert_clean(); validate_integrity(); print("Transactional continuity state is clean."); return 0
        if args.command == "recover":
            recover(args.force); return 0
        if args.command == "checkpoint":
            checkpoint(args); return 0
        if args.command == "transition":
            transition(args); return 0
    except (TransactionError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"AI continuity transaction error: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
