#!/usr/bin/env python3
"""Tests for crash-recoverable transactional continuity mutations."""
from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from unittest import mock
from pathlib import Path

TOOL = Path(__file__).with_name("ai_txn.py")


def load_module():
    spec = importlib.util.spec_from_file_location("vsn_ai_txn_under_test", TOOL)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load ai_txn.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class TransactionCoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.module = load_module()
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / ".git").mkdir()
        self.a = self.root / "a.txt"; self.b = self.root / "nested/b.txt"
        self.a.write_text("before-a", encoding="utf-8")
        self.b.parent.mkdir(); self.b.write_text("before-b", encoding="utf-8")
        self.txn = self.module.TxnCoordinator(self.root)

    def tearDown(self):
        self.temp.cleanup()

    def _mark_owner_dead(self):
        lock = json.loads(self.txn.lock_path.read_text(encoding="utf-8"))
        lock["pid"] = 999999999
        self.txn.lock_path.write_text(json.dumps(lock), encoding="utf-8")


    def test_windows_pid_probe_never_uses_os_kill(self):
        cls = self.module.TxnCoordinator
        with mock.patch.object(self.module.os, "name", "nt"), \
             mock.patch.object(cls, "_windows_pid_alive", return_value=True) as windows_probe, \
             mock.patch.object(self.module.os, "kill", side_effect=AssertionError("os.kill must not run on Windows")):
            self.assertTrue(cls._pid_alive(4242))
        windows_probe.assert_called_once_with(4242)

    def test_commit_removes_lock_and_backups(self):
        self.txn.begin("test", [self.a, self.b])
        self.a.write_text("after", encoding="utf-8")
        self.txn.commit()
        self.assertEqual("after", self.a.read_text(encoding="utf-8"))
        self.assertFalse(self.txn.lock_path.exists())
        self.assertFalse(self.txn.txn_dir.exists())
        self.assertFalse(self.txn.staging_dir.exists())

    def test_begin_promotes_prepared_backups_atomically(self):
        self.txn.begin("test", [self.a, self.b])
        self.assertTrue(self.txn.txn_dir.is_dir())
        self.assertTrue(self.txn.manifest_path.is_file())
        self.assertFalse(self.txn.staging_dir.exists())
        self.txn.commit()

    def test_recovery_restores_interrupted_mutation(self):
        self.txn.begin("test", [self.a, self.b])
        self.a.write_text("broken-a", encoding="utf-8")
        self.b.unlink()
        self._mark_owner_dead()
        self.assertTrue(self.txn.restore_pending())
        self.assertEqual("before-a", self.a.read_text(encoding="utf-8"))
        self.assertEqual("before-b", self.b.read_text(encoding="utf-8"))

    def test_recovery_cleans_interrupted_prepare_without_manifest(self):
        self.txn.acquire("prepare")
        self.txn.staging_dir.mkdir()
        (self.txn.staging_dir / "000.bak").write_text("partial-backup", encoding="utf-8")
        self._mark_owner_dead()
        self.assertTrue(self.txn.restore_pending())
        self.assertEqual("before-a", self.a.read_text(encoding="utf-8"))
        self.assertFalse(self.txn.staging_dir.exists())
        self.assertFalse(self.txn.lock_path.exists())

    def test_begin_never_deletes_preexisting_pending_backup_set(self):
        self.txn.txn_dir.mkdir()
        sentinel = self.txn.txn_dir / "sentinel.bak"
        sentinel.write_text("must-survive", encoding="utf-8")
        with self.assertRaises(self.module.TransactionError):
            self.txn.begin("second", [self.a])
        self.assertEqual("must-survive", sentinel.read_text(encoding="utf-8"))
        self.assertFalse(self.txn.lock_path.exists())

    def test_ambiguous_prepared_and_committed_artifacts_fail_closed(self):
        self.txn.txn_dir.mkdir()
        self.txn.staging_dir.mkdir()
        with self.assertRaises(self.module.TransactionError):
            self.txn.restore_pending()
        self.assertTrue(self.txn.txn_dir.exists())
        self.assertTrue(self.txn.staging_dir.exists())

    def test_concurrent_mutation_is_rejected(self):
        self.txn.begin("first", [self.a])
        second = self.module.TxnCoordinator(self.root)
        with self.assertRaises(self.module.TransactionError):
            second.begin("second", [self.a])
        self.txn.commit()

    def test_recovery_deletes_file_that_did_not_exist_before(self):
        new_file = self.root / "new.txt"
        self.txn.begin("create", [new_file])
        new_file.write_text("partial", encoding="utf-8")
        self._mark_owner_dead()
        self.txn.restore_pending()
        self.assertFalse(new_file.exists())

    def test_assert_clean_rejects_stale_lock(self):
        self.txn.lock_path.write_text('{"pid":999999999}', encoding="utf-8")
        with self.assertRaises(self.module.TransactionError):
            self.txn.assert_clean()

    def test_assert_clean_rejects_interrupted_prepare_artifact(self):
        self.txn.staging_dir.mkdir()
        with self.assertRaises(self.module.TransactionError):
            self.txn.assert_clean()

    def test_gitfile_worktree_marker_resolves_transaction_directory(self):
        actual_git_dir = self.root / "git-metadata"
        actual_git_dir.mkdir()
        (self.root / ".git").rmdir()
        (self.root / ".git").write_text("gitdir: git-metadata\n", encoding="utf-8")
        txn = self.module.TxnCoordinator(self.root)
        self.assertEqual(actual_git_dir.resolve(), txn.git_dir)
        txn.begin("worktree", [self.a])
        self.assertTrue((actual_git_dir / "vsn-ai-txn").exists())
        txn.commit()


class RepositoryTransactionTests(unittest.TestCase):
    def test_repository_transaction_state_is_clean(self):
        module = load_module()
        module.TxnCoordinator(module.ROOT).assert_clean()


if __name__ == "__main__":
    unittest.main(verbosity=2)
