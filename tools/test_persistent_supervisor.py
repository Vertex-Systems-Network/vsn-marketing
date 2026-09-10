#!/usr/bin/env python3
from __future__ import annotations

import unittest

from persistent_supervisor import (
    REQUIRED_CI,
    classify_ci,
    evaluate_pr,
    issue_needs_update,
    main_is_ancestor,
    render_status,
    standalone,
    workstreams_by_branch,
)


class PersistentSupervisorPolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.main = "a" * 40
        self.head = "b" * 40
        self.control = {
            "protected_main_branch": "main",
            "required_completion_signal": "Work Done and Submitted",
        }
        self.workstream = {
            "id": "WS-0101-PERSISTENT-SUPERVISOR",
            "branch": "supervisor/task-0101-persistent-control-plane",
        }

    def runs(self, conclusion: str = "success") -> list[dict]:
        return [
            {
                "id": index + 1,
                "name": name,
                "head_sha": self.head,
                "status": "completed",
                "conclusion": conclusion,
                "created_at": f"2026-09-10T00:00:0{index}Z",
            }
            for index, name in enumerate(REQUIRED_CI)
        ]

    def pr(self, *, body: str | None = None, draft: bool = False) -> dict:
        return {
            "number": 100,
            "body": body if body is not None else (
                "Workstream: WS-0101-PERSISTENT-SUPERVISOR\n\n"
                "Work Done and Submitted\n"
            ),
            "draft": draft,
            "head": {
                "ref": "supervisor/task-0101-persistent-control-plane",
                "sha": self.head,
                "repo": {"full_name": "Vertex-Systems-Network/vsn-marketing"},
            },
            "base": {
                "ref": "main",
                "repo": {"full_name": "Vertex-Systems-Network/vsn-marketing"},
            },
        }

    def test_standalone_requires_exact_line_not_substring(self) -> None:
        self.assertTrue(standalone("x\n Work Done and Submitted \ny", "Work Done and Submitted"))
        self.assertFalse(standalone("prefix Work Done and Submitted suffix", "Work Done and Submitted"))
        self.assertFalse(standalone("Work Done and Submitted-ish", "Work Done and Submitted"))

    def test_registry_is_indexed_only_by_valid_branch_rows(self) -> None:
        registry = {
            "workstreams": [
                self.workstream,
                {"id": "NO-BRANCH"},
                "not-an-object",
            ]
        }
        mapping = workstreams_by_branch(registry)
        self.assertEqual(mapping, {self.workstream["branch"]: self.workstream})

    def test_main_ancestry_fails_closed(self) -> None:
        self.assertTrue(main_is_ancestor({"status": "ahead", "behind_by": 0}))
        self.assertTrue(main_is_ancestor({"status": "identical", "behind_by": 0}))
        self.assertFalse(main_is_ancestor({"status": "diverged", "behind_by": 1}))
        self.assertFalse(main_is_ancestor({"status": "behind", "behind_by": 2}))
        self.assertFalse(main_is_ancestor(None))
        self.assertFalse(main_is_ancestor({"status": "ahead"}))

    def test_ci_uses_latest_exact_head_run_and_pending_supersedes_old_success(self) -> None:
        runs = self.runs()
        runs.append({
            "id": 99,
            "name": REQUIRED_CI[0],
            "head_sha": self.head,
            "status": "in_progress",
            "conclusion": None,
            "created_at": "2026-09-10T01:00:00Z",
        })
        runs.append({
            "id": 100,
            "name": REQUIRED_CI[1],
            "head_sha": "c" * 40,
            "status": "completed",
            "conclusion": "failure",
            "created_at": "2026-09-10T02:00:00Z",
        })
        state = classify_ci(runs, self.head)
        self.assertEqual(state[REQUIRED_CI[0]], "pending")
        self.assertEqual(state[REQUIRED_CI[1]], "success")
        self.assertEqual(state[REQUIRED_CI[2]], "success")

    def test_review_ready_requires_every_trusted_condition(self) -> None:
        evaluated = evaluate_pr(
            self.pr(),
            self.workstream,
            self.control,
            self.main,
            {"status": "ahead", "behind_by": 0},
            self.runs(),
        )
        self.assertTrue(evaluated["review_ready"])
        self.assertEqual(evaluated["blockers"], [])

    def test_missing_or_ambiguous_evidence_never_becomes_review_ready(self) -> None:
        evaluated = evaluate_pr(
            self.pr(body="Workstream: WS-0101-PERSISTENT-SUPERVISOR\n"),
            self.workstream,
            self.control,
            self.main,
            None,
            [],
        )
        self.assertFalse(evaluated["review_ready"])
        self.assertFalse(evaluated["checks"]["completion_signal"])
        self.assertFalse(evaluated["checks"]["contains_current_main"])
        self.assertFalse(evaluated["checks"]["exact_head_ci"])

    def test_draft_is_not_review_ready(self) -> None:
        evaluated = evaluate_pr(
            self.pr(draft=True),
            self.workstream,
            self.control,
            self.main,
            {"status": "ahead", "behind_by": 0},
            self.runs(),
        )
        self.assertFalse(evaluated["review_ready"])
        self.assertIn("PR is draft", evaluated["blockers"])

    def test_cross_repository_head_is_rejected(self) -> None:
        pr = self.pr()
        pr["head"]["repo"]["full_name"] = "attacker/fork"
        evaluated = evaluate_pr(
            pr,
            self.workstream,
            self.control,
            self.main,
            {"status": "ahead", "behind_by": 0},
            self.runs(),
        )
        self.assertFalse(evaluated["review_ready"])
        self.assertFalse(evaluated["checks"]["same_repository"])

    def test_status_render_is_deterministic_and_draft_does_not_degrade(self) -> None:
        state = {"execution": {"active_task": "TASK-0101", "status": "ready"}, "blockers": []}
        registry = {"mode": "active", "parent_task": "TASK-0101"}
        main_ci = {name: "success" for name in REQUIRED_CI}
        draft = evaluate_pr(
            self.pr(draft=True),
            self.workstream,
            self.control,
            self.main,
            {"status": "ahead", "behind_by": 0},
            self.runs(),
        )
        first = render_status(state, registry, self.main, main_ci, [draft])
        second = render_status(state, registry, self.main, main_ci, [draft])
        self.assertEqual(first, second)
        self.assertEqual(first[0], "HEALTHY")
        self.assertIn("Review ready |", first[1])

    def test_non_draft_blocker_degrades_status(self) -> None:
        state = {"execution": {"active_task": "TASK-0101", "status": "ready"}, "blockers": []}
        registry = {"mode": "active", "parent_task": "TASK-0101"}
        main_ci = {name: "success" for name in REQUIRED_CI}
        blocked = evaluate_pr(
            self.pr(body="Workstream: WS-0101-PERSISTENT-SUPERVISOR\n"),
            self.workstream,
            self.control,
            self.main,
            {"status": "ahead", "behind_by": 0},
            self.runs(),
        )
        health, body = render_status(state, registry, self.main, main_ci, [blocked])
        self.assertEqual(health, "DEGRADED")
        self.assertIn("completion", body.lower())

    def test_issue_deduplication(self) -> None:
        body = "stable\n"
        self.assertTrue(issue_needs_update(None, body))
        self.assertFalse(issue_needs_update({"state": "open", "body": body}, body))
        self.assertTrue(issue_needs_update({"state": "closed", "body": body}, body))
        self.assertTrue(issue_needs_update({"state": "open", "body": "old"}, body))


if __name__ == "__main__":
    unittest.main()
