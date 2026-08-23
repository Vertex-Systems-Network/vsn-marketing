#!/usr/bin/env python3
"""Tests for crash-recoverable transactional continuity mutations."""
from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
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

    def test_commit_removes_lock_and_backups(self):
        self.txn.begin("test", [self.a, self.b])
        self.a.write_text("after", encoding="utf-8")
        self.txn.commit()
        self.assertEqual("after", self.a.read_text(encoding="utf-8"))
        self.assertFalse(self.txn.lock_path.exists())
        self.assertFalse(self.txn.txn_dir.exists())

    def test_recovery_restores_interrupted_mutation(self):
        self.txn.begin("test", [self.a, self.b])
        self.a.write_text("broken-a", encoding="utf-8")
        self.b.unlink()
        lock = json.loads(self.txn.lock_path.read_text(encoding="utf-8")); lock["pid"] = 999999999
        self.txn.lock_path.write_text(json.dumps(lock), encoding="utf-8")
        self.assertTrue(self.txn.restore_pending())
        self.assertEqual("before-a", self.a.read_text(encoding="utf-8"))
        self.assertEqual("before-b", self.b.read_text(encoding="utf-8"))

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
        lock = json.loads(self.txn.lock_path.read_text(encoding="utf-8")); lock["pid"] = 999999999
        self.txn.lock_path.write_text(json.dumps(lock), encoding="utf-8")
        self.txn.restore_pending()
        self.assertFalse(new_file.exists())

    def test_assert_clean_rejects_stale_lock(self):
        self.txn.lock_path.write_text('{"pid":999999999}', encoding="utf-8")
        with self.assertRaises(self.module.TransactionError):
            self.txn.assert_clean()


class RepositoryTransactionTests(unittest.TestCase):
    def test_repository_transaction_state_is_clean(self):
        module = load_module()
        module.TxnCoordinator(module.ROOT).assert_clean()


if __name__ == "__main__":
    unittest.main(verbosity=2)
