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

    def _read_lock(self) -> dict:
        try:
            return json.loads(self.lock_path.read_text(encoding="utf-8"))
        except Exception:
            return {"pid": -1}

    @staticmethod
    def _coerce_pid(lock: dict) -> int:
        raw = str(lock.get("pid", ""))
        return int(raw) if raw.lstrip("-").isdigit() else -1

    def _assert_no_pending_artifacts(self) -> None:
        pending = []
        if self.txn_dir.exists():
            pending.append(self.txn_dir.name)
        if self.staging_dir.exists():
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
                if existed:
                    self._write_bytes_durable(backup, path.read_bytes())
                backups.append({"path": rel, "backup": backup.name, "existed": existed})
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
        if self.staging_dir.exists():
            raise TransactionError("staging continuity transaction artifact exists during commit")
        if self.txn_dir.exists():
            shutil.rmtree(self.txn_dir)
            self._fsync_dir(self.git_dir)
        self._remove_lock()

    def restore_pending(self, *, force: bool = False) -> bool:
        has_txn = self.txn_dir.exists()
        has_staging = self.staging_dir.exists()
        has_lock = self.lock_path.exists()

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

        for item in manifest.get("backups", []):
            target = self.root / item["path"]
            backup = self.txn_dir / item["backup"]
            if item.get("existed"):
                target.parent.mkdir(parents=True, exist_ok=True)
                self._write_bytes_durable(target, backup.read_bytes())
            elif target.exists():
                if target.is_dir():
                    raise TransactionErrop€¡˜‰…¹¹½ÐÉ•µ½Ù”É•…Ñ•‘¥É•Ñ½Éä‘ÕÉ¥¹œÉ•½Ù•Éäèí¥Ñ•µlÁ…Ñ uôˆ¤(€€€€€€€€€€€€€€€Ñ…É•Ð¹Õ¹±¥¹¬ ¤(€€€€€€€€€€€€€€€Í•±˜¹}™Íå¹}‘¥È¡Ñ…É•Ð¹Á…É•¹Ð¤(€€€€€€€Í¡ÕÑ¥°¹ÉµÑÉ•”¡Í•±˜¹Ñá¹}‘¥È¤(€€€€€€€Í•±˜¹}™Íå¹}‘¥È¡Í•±˜¹¥Ñ}‘¥È¤(€€€€€€€Í•±˜¹}É•µ½Ù•}±½¬ ¤(€€€€€€€É•ÑÕÉ¸QÉÕ”((€€€‘•˜…ÍÍ•ÉÑ}±•…¸¡Í•±˜¤€´ø9½¹”è(€€€€€€€¥˜Í•±˜¹Ñá¹}‘¥È¹•á¥ÍÑÌ ¤½ÈÍ•±˜¹ÍÑ…¥¹}‘¥È¹•á¥ÍÑÌ ¤½ÈÍ•±˜¹±½­}Á…Ñ ¹•á¥ÍÑÌ ¤è(€€€€€€€€€€€É…¥Í”QÉ…¹Í…Ñ¥½¹ÉÉ½È ‰Á•¹‘¥¹œ½ÍÑ…±”½¹Ñ¥¹Õ¥ÑäÑÉ…¹Í…Ñ¥½¸‘•Ñ•Ñ•ìÉÕ¸ÁåÑ¡½¸Ñ½½±Ì½…¥}Ñá¸¹ÁäÉ•½Ù•É€ˆ¤(()‘•˜ÉÕ¹}Ñ½½°¡…ÉÌè±¥ÍÑmÍÑÉt¤€´ø9½¹”è(€€€ÁÉ½Œ€ôÍÕ‰ÁÉ½•ÍÌ¹ÉÕ¸¡mÍåÌ¹•á•ÕÑ…‰±”°€©…ÉÍt°ÝõI==P°Ñ•áÐõQÉÕ”°ÍÑ‘½ÕÐõÍÕ‰ÁÉ½•ÍÌ¹A%A°ÍÑ‘•ÉÈõÍÕ‰ÁÉ½•ÍÌ¹MQ=UP¤(€€€¥˜ÁÉ½Œ¹ÍÑ‘½ÕÐè(€€€€€€€ÁÉ¥¹Ð¡ÁÉ½Œ¹ÍÑ‘½ÕÐ°•¹ôˆˆ¤(€€€¥˜ÁÉ½Œ¹É•ÑÕÉ¹½‘”€„ô€Àè(€€€€€€€É…¥Í”QÉ…¹Í…Ñ¥½¹ÉÉ½È¡˜‰½µµ…¹™…¥±•€¡íÁÉ½Œ¹É•ÑÕÉ¹½‘•ô¤èìœ€œ¹©½¥¸¡…ÉÌ¥ôˆ¤(()‘•˜Ù…±¥‘…Ñ•}¥¹Ñ•É¥Ñä ¤€´ø9½¹”è(€€€ÉÕ¹}Ñ½½°¡l‰Ñ½½±Ì½…¥}ÍÑ…Ñ”¹Áäˆ°€‰Ù…±¥‘…Ñ”‰t¤(€€€ÉÕ¹}Ñ½½°¡l‰Ñ½½±Ì½…¥}©½ÕÉ¹…°¹Áäˆ°€‰Ù…±¥‘…Ñ”‰t¤(€€€ÉÕ¹}Ñ½½°¡l‰Ñ½½±Ì½…¥}½¹Ñ•áÐ¹Áäˆ°€‰µ…¹¥™•ÍÐ‰t¤(()‘•˜Ñ…Í­}Á…Ñ ¡Ñ…Í­}¥èÍÑÈ¤€´øA…Ñ è(€€€É•ÑÕÉ¸I==P€¼€ˆ¹…¤ˆ€¼€‰Ñ…Í­Ìˆ€¼˜‰íÑ…Í­}¥‘ô¹å…µ°ˆ(()‘•˜¡•­Á½¥¹Ð¡…ÉÌ¤€´ø9½¹”è(€€€½½É‘¥¹…Ñ½È€ôQá¹½½É‘¥¹…Ñ½È¡I==P¤(€€€Á…Ñ¡Ì€ôl(€€€€€€€I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½UII9PµMQQ¹å…µ°ˆ°(€€€€€€€I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½1MPµ!-A=%9P¹µˆ°(€€€€€€€I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½aUQ%=8µ)=UI90¹©Í½¹°ˆ°(€€€t(€€€½½É‘¥¹…Ñ½È¹‰•¥¸ ‰¡•­Á½¥¹Ðˆ°Á…Ñ¡Ì¤(€€€ÑÉäè(€€€€€€€ÉÕ¹}Ñ½½°¡l‰Ñ½½±Ì½…¥}ÍÑ…Ñ”¹Áäˆ°€‰¡•­Á½¥¹Ðˆ°€ˆ´µÍÕµµ…Éäˆ°…ÉÌ¹ÍÕµµ…Éä°€ˆ´µÑ•ÍÑÌˆ°…ÉÌ¹Ñ•ÍÑÌ°€ˆ´µ¹•áÐˆ°…ÉÌ¹¹•áÑ}…Ñ¥½¹t¤(€€€€€€€ÉÕ¹}Ñ½½°¡l‰Ñ½½±Ì½…¥}©½ÕÉ¹…°¹Áäˆ°€‰É•½Éˆ°€ˆ´µÑåÁ”ˆ°€‰¡•­Á½¥¹Ðˆ°€ˆ´µÍÕµµ…Éäˆ°…ÉÌ¹ÍÕµµ…Éåt¤(€€€€€€€Ù…±¥‘…Ñ•}¥¹Ñ•É¥Ñä ¤(€€€•á•ÁÐ	…Í•á•ÁÑ¥½¸è(€€€€€€€½½É‘¥¹…Ñ½È¹É•ÍÑ½É•}Á•¹‘¥¹œ¡™½É”õQÉÕ”¤(€€€€€€€É…¥Í”(€€€½½É‘¥¹…Ñ½È¹½µµ¥Ð ¤(()‘•˜ÑÉ…¹Í¥Ñ¥½¸¡…ÉÌ¤€´ø9½¹”è(€€€½½É‘¥¹…Ñ½È€ôQá¹½½É‘¥¹…Ñ½È¡I==P¤(€€€Á…Ñ¡Ì€ôl(€€€€€€€Ñ…Í­}Á…Ñ ¡…ÉÌ¹½µÁ±•Ñ”¤°Ñ…Í­}Á…Ñ ¡…ÉÌ¹¹•áÑ}Ñ…Í¬¤°(€€€€€€€I==P€¼€ˆ¹…¤½Ñ…Í­Ì½%9`¹å…µ°ˆ°I==P€¼€ˆ¹…¤½É½…‘µ…À½I=5@¹å…µ°ˆ°(€€€€€€€I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½UII9PµMQQ¹å…µ°ˆ°I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½1MPµ!-A=%9P¹µˆ°(€€€€€€€I==P€¼€ˆ¹…¤½ÍÑ…Ñ”½aUQ%=8µ)=UI90¹©Í½¹°ˆ°(€€€t(€€€½½É‘¥¹…Ñ½È¹‰•¥¸ ‰Ñ…Í­}ÑÉ…¹Í¥Ñ¥½¸ˆ°Á…Ñ¡Ì¤(€€€ÑÉäè(€€€€€€€½µµ…¹€ôl(€€€€€€€€€€€€‰Ñ½½±Ì½…¥}ÍÑ…Ñ”¹Áäˆ°€‰ÑÉ…¹Í¥Ñ¥½¸ˆ°€ˆ´µ½µÁ±•Ñ”ˆ°…ÉÌ¹½µÁ±•Ñ”°€ˆ´µ¹•áÐˆ°…ÉÌ¹¹•áÑ}Ñ…Í¬°(€€€€€€€€€€€€ˆ´µ•Ù¥‘•¹”ˆ°…ÉÌ¹•Ù¥‘•¹”°€ˆ´µÑ•ÍÑÌˆ°…ÉÌ¹Ñ•ÍÑÌ°(€€€€€€€t(€€€€€€€¥˜…ÉÌ¹‘Éå}ÉÕ¸è(€€€€€€€€€€€½µµ…¹¹…ÁÁ•¹ ˆ´µ‘ÉäµÉÕ¸ˆ¤(€€€€€€€ÉÕ¹}Ñ½½°¡½µµ…¹¤(€€€€€€€¥˜¹½Ð…ÉÌ¹‘Éå}ÉÕ¸è(€€€€€€€€€€€ÉÕ¹}Ñ½½°¡l(€€€€€€€€€€€€€€€€‰Ñ½½±Ì½…¥}©½ÕÉ¹…°¹Áäˆ°€‰É•½Éˆ°€ˆ´µÑåÁ”ˆ°€‰Ñ…Í­}ÑÉ…¹Í¥Ñ¥½¸ˆ°(€€€€€€€€€€€€€€€€ˆ´µÍÕµµ…Éäˆ°˜‰í…ÉÌ¹½µÁ±•Ñ•ô½µÁ±•Ñ•ìí…ÉÌ¹¹•áÑ}Ñ…Í­ô…Ñ¥Ù…Ñ•¸í…ÉÌ¹•Ù¥‘•¹•ôˆ°(€€€€€€€€€€€t¤(€€€€€€€€€€€Ù…±¥‘…Ñ•}¥¹Ñ•É¥Ñä ¤(€€€•á•ÁÐ	…Í•á•ÁÑ¥½¸è(€€€€€€€½½É‘¥¹…Ñ½È¹É•ÍÑ½É•}Á•¹‘¥¹œ¡™½É”õQÉÕ”¤(€€€€€€€É…¥Í”(€€€½½É‘¥¹…Ñ½È¹½µµ¥Ð ¤(()‘•˜É•½Ù•È¡™½É”è‰½½°¤€´ø9½¹”è(€€€½½É‘¥¹…Ñ½È€ôQá¹½½É‘¥¹…Ñ½È¡I==P¤(€€€É•ÍÑ½É•€ô½½É‘¥¹…Ñ½È¹É•ÍÑ½É•}Á•¹‘¥¹œ¡™½É”õ™½É”¤(€€€ÁÉ¥¹Ð ‰I•½Ù•É•¥¹Ñ•ÉÉÕÁÑ•½¹Ñ¥¹Õ¥ÑäÑÉ…¹Í…Ñ¥½¸¸ˆ¥˜É•ÍÑ½É••±Í”€‰9¼¥¹Ñ•ÉÉÕÁÑ•½¹Ñ¥¹Õ¥ÑäÑÉ…¹Í…Ñ¥½¸™½Õ¹¸ˆ¤(€€€Ù…±¥‘…Ñ•}¥¹Ñ•É¥Ñä ¤(()‘•˜µ…¥¸ ¤€´ø¥¹Ðè(€€€Á…ÉÍ•È€ô…ÉÁ…ÉÍ”¹ÉÕµ•¹ÑA…ÉÍ•È¡‘•ÍÉ¥ÁÑ¥½¸ô‰YM85…É­•Ñ¥¹œÑÉ…¹Í…Ñ¥½¹…°½¹Ñ¥¹Õ¥ÑäµÕÑ…Ñ¥½¹Ìˆ¤(€€€ÍÕˆ€ôÁ…ÉÍ•È¹…‘‘}ÍÕ‰Á…ÉÍ•ÉÌ¡‘•ÍÐô‰½µµ…¹ˆ°É•ÅÕ¥É•õQÉÕ”¤(€€€ÍÕˆ¹…‘‘}Á…ÉÍ•È ‰Ù…±¥‘…Ñ”ˆ¤(€€€É•Œ€ôÍÕˆ¹…‘‘}Á…ÉÍ•È ‰É•½Ù•Èˆ¤ìÉ•Œ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ™½É”ˆ°…Ñ¥½¸ô‰ÍÑ½É•}ÑÉÕ”ˆ¤(€€€À€ôÍÕˆ¹…‘‘}Á…ÉÍ•È ‰¡•­Á½¥¹Ðˆ¤(€€€À¹…‘‘}…ÉÕµ•¹Ð ˆ´µÍÕµµ…Éäˆ°É•ÅÕ¥É•õQÉÕ”¤ìÀ¹…‘‘}…ÉÕµ•¹Ð ˆ´µÑ•ÍÑÌˆ°É•ÅÕ¥É•õQÉÕ”¤ìÀ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ¹•áÐˆ°‘•ÍÐô‰¹•áÑ}…Ñ¥½¸ˆ°É•ÅÕ¥É•õQÉÕ”¤(€€€ÑÈ€ôÍÕˆ¹…‘‘}Á…ÉÍ•È ‰ÑÉ…¹Í¥Ñ¥½¸ˆ¤(€€€ÑÈ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ½µÁ±•Ñ”ˆ°É•ÅÕ¥É•õQÉÕ”¤ìÑÈ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ¹•áÐˆ°‘•ÍÐô‰¹•áÑ}Ñ…Í¬ˆ°É•ÅÕ¥É•õQÉÕ”¤(€€€ÑÈ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ•Ù¥‘•¹”ˆ°É•ÅÕ¥É•õQÉÕ”¤ìÑÈ¹…‘‘}…ÉÕµ•¹Ð ˆ´µÑ•ÍÑÌˆ°É•ÅÕ¥É•õQÉÕ”¤ìÑÈ¹…‘‘}…ÉÕµ•¹Ð ˆ´µ‘ÉäµÉÕ¸ˆ°…Ñ¥½¸ô‰ÍÑ½É•}ÑÉÕ”ˆ¤(€€€…ÉÌ€ôÁ…ÉÍ•È¹Á…ÉÍ•}…ÉÌ ¤(€€€ÑÉäè(€€€€€€€¥˜…ÉÌ¹½µµ…¹€ôô€‰Ù…±¥‘…Ñ”ˆè(€€€€€€€€€€€Qá¹½½É‘¥¹…Ñ½È¡I==P¤¹…ÍÍ•ÉÑ}±•…¸ ¤ìÙ…±¥‘…Ñ•}¥¹Ñ•É¥Ñä ¤ìÁÉ¥¹Ð ‰QÉ…¹Í…Ñ¥½¹…°½¹Ñ¥¹Õ¥ÑäÍÑ…Ñ”¥Ì±•…¸¸ˆ¤ìÉ•ÑÕÉ¸€À(€€€€€€€¥˜…ÉÌ¹½µµ…¹€ôô€‰É•½Ù•Èˆè(€€€€€€€€€€€É•½Ù•È¡…ÉÌ¹™½É”¤ìÉ•ÑÕÉ¸€À(€€€€€€€¥˜…ÉÌ¹½µµ…¹€ôô€‰¡•­Á½¥¹Ðˆè(€€€€€€€€€€€¡•­Á½¥¹Ð¡…ÉÌ¤ìÉ•ÑÕÉ¸€À(€€€€€€€¥˜…ÉÌ¹½µµ…¹€ôô€‰ÑÉ…¹Í¥Ñ¥½¸ˆè(€€€€€€€€€€€ÑÉ…¹Í¥Ñ¥½¸¡…ÉÌ¤ìÉ•ÑÕÉ¸€À(€€€•á•ÁÐ€¡QÉ…¹Í…Ñ¥½¹ÉÉ½È°=MÉÉ½È°Y…±Õ•ÉÉ½È°©Í½¸¹)M=9•½‘•ÉÉ½È¤…Ì•áŒè(€€€€€€€ÁÉ¥¹Ð¡˜‰$½¹Ñ¥¹Õ¥ÑäÑÉ…¹Í…Ñ¥½¸•ÉÉ½Èèí•áôˆ°™¥±”õÍåÌ¹ÍÑ‘•ÉÈ¤(€€€€€€€É•ÑÕÉ¸€Ä(€€€É•ÑÕÉ¸€È(()¥˜}}¹…µ•}|€ôô€‰}}µ…¥¹}|ˆè(€€€É…¥Í”MåÍÑ•µá¥Ð¡µ…¥¸ ¤¤