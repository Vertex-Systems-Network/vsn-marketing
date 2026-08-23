#!/usr/bin/env python3
"""Crash-recoverable transactional wrapper for VSN Marketing continuity mutations."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]


class TransactionError(RuntimeError):
    pass


class TxnCoordinator:
    def __init__(self, root: Path):
        self.root = root
        self.git_dir = self._resolve_git_dir(root)
        self.txn_dir = self.git_dir / "vsn-ai-txn"
        self.staging_dir = self.git_dir / "vsn-ai-txn-staging"
        self.lock_path = self.git_dir / "vsn-ai-txn.lock"
        self.manifest_path = self.txn_dir / "manifest.json"

    @staticmethod
    def _resolve_git_dir(root: Path) -> Path:
        marker = root / ".git"
        if marker.is_dir():
            return marker
        if marker.is_file():
            try:
                raw = marker.read_text(encoding="utf-8").strip()
            except OSError:
                return marker
            if raw.lower().startswith("gitdir:"):
                target = Path(raw.split(":", 1)[1].strip())
                if not target.is_absolute():
                    target = (root / target).resolve()
                return target
        return marker

    def _require_git_dir(self) -> None:
        if not self.git_dir.is_dir():
            raise TransactionError(".git directory is required for crash-recoverable continuity transactions")

    @staticmethod
    def _windows_pid_alive(pid: int) -> bool:
        # os.kill(pid, 0) is NOT a harmless existence probe on Windows:
        # CPython maps non-console signals to TerminateProcess. Use a read-only
        # process handle instead so lock validation can never kill its owner.
        import ctypes
        from ctypes import wintypes

        PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
        ERROR_ACCESS_DENIED = 5
        ERROR_INVALID_PARAMETER = 87

        kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
        kernel32.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        kernel32.OpenProcess.restype = wintypes.HANDLE
        kernel32.CloseHandle.argtypes = [wintypes.HANDLE]
        kernel32.CloseHandle.restype = wintypes.BOOL

        handle = kernel32.OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, False, pid)
        if handle:
            kernel32.CloseHandle(handle)
            return True

        error = ctypes.get_last_error()
        if error == ERROR_INVALID_PARAMETER:
            return False
        if error == ERROR_ACCESS_DENIED:
            return True
        # Fail closed for unusual Windows errors: require explicit --force
        # rather than risk rolling back a transaction owned by a live process.
        return True

    @classmethod
    def _pid_alive(cls, pid: int) -> bool:
        if pid <= 0:
            return False
        if os.name == "nt":
            return cls._windows_pid_alive(pid)
        try:
            os.kill(pid, 0)
            return True
        except ProcessLookupError:
            return False
        except PermissionError:
            return True
        except OSError:
            return True

    @staticmethod
    def _fsync_dir(path: Path) -> None:
        try:
            fd = os.open(path, os.O_RDONLY)
        except OSError:
            return
        try:
            os.fsync(fd)
        except OSError:
            pass
        finally:
            os.close(fd)

    @classmethod
    def _write_bytes_durable(cls, path: Path, data: bytes) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        cls._fsync_dir(path.parent)

    @classmethod
    def _write_text_durable(cls, path: Path, text: str) -> None:
        cls._write_bytes_durable(path, text.encode("utf-8"))

    @staticmethod
    def _sha256_bytes(data: bytes) -> str:
        return hashlib.sha256(data).hexdigest()

    @classmethod
    def _fsync_file(cls, path: Path) -> None:
        try:
            with path.open("rb") as handle:
                os.fsync(handle.fileno())
        except OSError as exc:
            raise TransactionError(f"cannot fsync continuity target: {path}") from exc
        cls._fsync_dir(path.parent)

    @staticmethod
    def _artifact_present(path: Path) -> bool:
        return path.exists() or path.is_symlink()

    def _read_lock(self) -> dict:
        try:
            return json.loads(self.lock_path.read_text(encoding="utf-8"))
        except Exception:
            return {"pid": -1}

    @staticmethod
    def _coerce_pid(lock: dict) -> int:
        raw = str(lock.get("pid", ""))
        return int(raw) if raw.lstrip("-").isdigit() else -1

    def _validate_recovery_manifest(self, manifest: object) -> list[dict]:
        if not isinstance(manifest, dict):
            raise TransactionError("transaction manifest must be a JSON object")
        if manifest.get("schema_version") != 1:
            raise TransactionError("transaction manifest has unsupported schema_version")
        if not isinstance(manifest.get("operation"), str) or not manifest["operation"].strip():
            raise TransactionError("transaction manifest is missing a valid operation")
        if not isinstance(manifest.get("pid"), int) or manifest["pid"] <= 0:
            raise TransactionError("transaction manifest is missing a valid pid")
        if not isinstance(manifest.get("started_at"), str) or not manifest["started_at"].strip():
            raise TransactionError("transaction manifest is missing started_at")

        backups = manifest.get("backups")
        if not isinstance(backups, list) or not backups:
            raise TransactionError("transaction manifest must contain a non-empty backups list")
        if len(backups) > 100:
            raise TransactionError("transaction manifest backup count exceeds safety limit")

        validated: list[dict] = []
        seen_paths: set[str] = set()
        root_resolved = self.root.resolve()
        for index, item in enumerate(backups):
            if not isinstance(item, dict):
                raise TransactionError(f"transaction manifest backup {index} must be an object")
            rel = item.get("path")
            backup_name = item.get("backup")
            existed = item.get("existed")
            expected_backup = f"{index:03d}.bak"
            if not isinstance(rel, str) or not rel or "\\" in rel or "\x00" in rel:
                raise TransactionError(f"transaction manifest backup {index} has invalid path")
            pure = PurePosixPath(rel)
            if pure.is_absolute() or not pure.parts or any(part in {"", ".", ".."} for part in pure.parts):
                raise TransactionError(f"transaction manifest backup {index} escapes repository path rules")
            if ":" in pure.parts[0]:
                raise TransactionError(f"transaction manifest backup {index} contains a drive-like path")
            if pure.as_posix() != rel:
                raise TransactionError(f"transaction manifest backup {index} path is not canonical")
            if rel in seen_paths:
                raise TransactionError(f"transaction manifest contains duplicate target path: {rel}")
            seen_paths.add(rel)
            if backup_name != expected_backup:
                raise TransactionError(f"transaction manifest backup {index} has unexpected backup filename")
            if type(existed) is not bool:
                raise TransactionError(f"transaction manifest backup {index} has invalid existed flag")

            target = self.root.joinpath(*pure.parts)
            try:
                resolved_target = target.resolve(strict=False)
            except OSError as exc:
                raise TransactionError(f"cannot resolve recovery target safely: {rel}") from exc
            if resolved_target != root_resolved and root_resolved not in resolved_target.parents:
                raise TransactionError(f"transaction manifest target escapes repository root: {rel}")
            if target.is_symlink():
                raise TransactionError(f"refusing recovery through symlink target: {rel}")

            backup = self.txn_dir / expected_backup
            expected_sha = item.get("sha256")
            if existed:
                if not isinstance(expected_sha, str) or len(expected_sha) != 64 or any(ch not in "0123456789abcdef" for ch in expected_sha):
                    raise TransactionError("transaction manifest backup {index} has invalid sha256")
                if not backup.is_file() or backup.is_symlink():
                    raise TransactionError(" required recovery backup is missing or unsafe: {expected_backup}")
                data = backup.read_bytes()
                if self._sha256_bytes(data) != expected_sha:
                    raise TransactionError(" recovery backup checksum mismatch: {expected_backup}")
            else:
                if expected_sha is not None:
                    raise TransactionError(f"transaction manifest backup {index} must not hash a non-existent target")
                if backup.exists() or backup.is_symlink():
                    raise TransactionError(f"unexpected recovery backup exists for new target: {expected_backup}")
                data = None
            validated.append({"path": rel, "target": target, "backup": backup, "existed": existed, "data": data})
        return validated

    def _assert_no_pending_artifacts(self) -> None:
        pending = []
        if self._artifact_present(self.txn_dir):
            pending.append(self.txn_dir.name)
        if self._artifact_present(self.staging_dir):
            pending.append(self.staging_dir.name)
        if pending:
            raise TransactionError(
                "pending continuity transaction artifact(s) exist "
                f"({', '.join(pending)}); run `python tools/ai_txn.py recover` before another mutation"
            )

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
            handle.flush()
            os.fsync(handle.fileno())
        self._fsync_dir(self.git_dir)

    def begin(self, operation: str, paths: list[Path]) -> None:
        self._require_git_dir()
        self._assert_no_pending_artifacts()
        self.acquire(operation)
        created_staging = False
        promoted = False
        try:
            self.staging_dir.mkdir(parents=True)
            created_staging = True
            backups = []
            for index, path in enumerate(paths):
                rel = str(path.relative_to(self.root)).replace("\\", "/")
                backup = self.staging_dir / f"{index:03d}.bak"
                existed = path.exists()
                data = None
                if existed:
                    if path.is_symlink():
                        raise TransactionError(f"refusing to transact symlink target: {rel}")
                    data = path.read_bytes()
                    self._write_bytes_durable(backup, data)
                backups.append({
                    "path": rel,
                    "backup": backup.name,
                    "existed": existed,
                    "sha256": self._sha256_bytes(data) if data is not None else None,
                })
            manifest = {
                "schema_version": 1,
                "operation": operation,
                "pid": os.getpid(),
                "started_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "backups": backups,
            }
            staging_manifest = self.staging_dir / "manifest.json"
            self._write_text_durable(staging_manifest, json.dumps(manifest, indent=2) + "\n")
            self._fsync_dir(self.staging_dir)
            os.replace(self.staging_dir, self.txn_dir)
            promoted = True
            created_staging = False
            self._fsync_dir(self.git_dir)
        except Exception:
            if created_staging and self.staging_dir.exists():
                shutil.rmtree(self.staging_dir, ignore_errors=True)
            if promoted and self.txn_dir.exists():
                shutil.rmtree(self.txn_dir, ignore_errors=True)
            self._remove_lock()
            raise

    def _remove_lock(self) -> None:
        try:
            self.lock_path.unlink()
            self._fsync_dir(self.git_dir)
        except FileNotFoundError:
            pass

    def commit(self) -> None:
        if self.staging_dir.is_symlink() or self.txn_dir.is_symlink() or self.lock_path.is_symlink():
            raise TransactionError("unsafe symlink detected in continuity transaction artifacts")
        if self.staging_dir.exists():
            raise TransactionError("staging continuity transaction artifact exists during commit")
        if self.txn_dir.exists():
            try:
                manifest = json.loads(self.manifest_path.read_text(encoding="utf-8"))
            except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
                raise TransactionError("cannot validate transaction manifest before commit") from exc
            validated_backups = self._validate_recovery_manifest(manifest)
            # Make the successful post-mutation ledger durable before deleting the
            # only rollback material. A power/process interruption before this point
            # therefore retains the recoverable backup set.
            for item in validated_backups:
                target = item["target"]
                if target.exists():
                    if target.is_symlink() or not target.is_file():
                        raise TransactionError(f"unsafe continuity target at commit: {item['path']}")
                    self._fsync_file(target)
            shutil.rmtree(self.txn_dir)
            self._fsync_dir(self.git_dir)
        self._remove_lock()

    def restore_pending(self, *, force: bool = False) -> bool:
        has_txn = self._artifact_present(self.txn_dir)
        has_staging = self._artifact_present(self.staging_dir)
        has_lock = self._artifact_present(self.lock_path)

        if self.txn_dir.is_symlink() or self.staging_dir.is_symlink() or self.lock_path.is_symlink():
            raise TransactionError("unsafe symlink detected in continuity transaction artifacts; refusing recovery")

        if has_txn and has_staging:
            raise TransactionError(
                "ambiguous continuity transaction artifacts exist (prepared and committed backup sets); "
                "refusing automatic recovery"
            )

        lock = self._read_lock() if has_lock else {"pid": -1}
        pid = self._coerce_pid(lock)
        if self._pid_alive(pid) and pid != os.getpid() and not force:
            raise TransactionError(f"pending transaction is owned by live pid {pid}; refusing recovery")

        if has_staging:
            # begin() has not returned before the staging directory is atomically
            # promoted, so target files cannot have been mutated yet.
            shutil.rmtree(self.staging_dir)
            self._fsync_dir(self.git_dir)
            self._remove_lock()
            return True

        if not has_txn:
            if has_lock:
                self._remove_lock()
            return False

        try:
            manifest = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
            raise TransactionError(
                "committed transaction backup set is missing a valid manifest; "
                "refusing destructive recovery"
            ) from exc
        validated_backups = self._validate_recovery_manifest(manifest)

        for item in validated_backups:
            target = item["target"]
            backup = item["backup"]
            if item["existed"]:
                target.parent.mkdir(parents=True, exist_ok=True)
                self._write_bytes_durable(target, item["data"])
            elif target.exists():
                if target.is_dir():
                    raise TransactionError(f"cannot remove created directory during recovery: {item['path']}")
                target.unlink()
                self._fsync_dir(target.parent)
        shutil.rmtree(self.txn_dir)
        self._fsync_dir(self.git_dir)
        self._remove_lock()
        return True

    def assert_clean(self) -> None:
        if self._artifact_present(self.txn_dir) or self._artifact_present(self.staging_dir) or self._artifact_present(self.lock_path):
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
        task_path(args.complete),
        ROOT / ".ai/tasks/INDEX.yaml", ROOT / ".ai/roadmap/ROADMAP.yaml",
        ROOT / ".ai/state/CURRENT-STATE.yaml", ROOT / ".ai/state/LAST-CHECKPOINT.md",
        ROOT / ".ai/state/EXECUTION-JOURNAL.jsonl",
    ]
    if args.next_task:
        paths.insert(1, task_path(args.next_task))
    coordinator.begin("task_transition", paths)
    try:
        command = [
            "tools/ai_state.py", "transition", "--complete", args.complete,
            "--evidence", args.evidence, "--tests", args.tests,
        ]
        if args.next_task:
            command.extend(["--next", args.next_task])
        if args.dry_run:
            command.append("--dry-run")
        run_tool(command)
        if not args.dry_run:
            summary = (
                f"{args.complete} completed; {args.next_task} activated. {args.evidence}"
                if args.next_task
                else f"{args.complete} completed; no successor registered. {args.evidence}"
            )
            run_tool([
                "tools/ai_journal.py", "record", "--type", "task_transition",
                "--summary", summary,
            ])
            validate_integrity()
    except BaseException:
        coordinator.restore_pending(force=True)
        raise
    coordinator.commit()


def recover(force: bool) -> None:
    coordinator = TxnCoordinator(ROOT)
    restored = coordinator.restore_pending(force=force)
    print("Recovered interrupted continuity transaction." if restored else "No interrupted continuity transaction found.")
    validate_integrity()


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing transactional continuity mutations")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    rec = sub.add_parser("recover"); rec.add_argument("--force", action="store_true")
    cp = sub.add_parser("checkpoint")
    cp.add_argument("--summary", required=True); cp.add_argument("--tests", required=True); cp.add_argument("--next", dest="next_action", required=True)
    tr = sub.add_parser("transition")
    tr.add_argument("--complete", required=True); tr.add_argument("--next", dest="next_task")
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
