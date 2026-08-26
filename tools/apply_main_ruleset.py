#!/usr/bin/env python3
"""Apply and certify the TASK-0014 AC-1 single-owner repository ruleset contract.

The repository is intentionally operated by a single maintainer. The operator
therefore does not require a second-human PR approval or last-push approval.
It does require strict exact-head CI, resolved review threads, no bypass actors,
and force-push/deletion protection before TASK-0014 acceptance.
"""

from __future__ import annotations

import argparse
import copy
import json
import subprocess
import sys
from typing import Any

REPOSITORY = "Vertex-Systems-Network/vsn-marketing"
RULESET_ID = 21212844
API_PATH = f"/repos/{REPOSITORY}/rulesets/{RULESET_ID}"
REQUIRED_STATUS_CONTEXTS = (
    "governance",
    "foundation",
    "php-floor",
    "integration",
    "e2e",
    "security-gates",
)
CERTIFICATION_MARKER = "MAIN RULESET AC-1 CERTIFICATION PASSED"


def _rule_map(ruleset: dict[str, Any]) -> dict[str, dict[str, Any]]:
    rules = ruleset.get("rules")
    if not isinstance(rules, list):
        return {}
    return {
        rule["type"]: rule
        for rule in rules
        if isinstance(rule, dict) and isinstance(rule.get("type"), str)
    }


def validate_identity(ruleset: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if ruleset.get("id") != RULESET_ID:
        errors.append(f"unexpected ruleset id: {ruleset.get('id')!r}")
    if ruleset.get("source") != REPOSITORY:
        errors.append(f"unexpected ruleset source: {ruleset.get('source')!r}")
    if ruleset.get("target") != "branch":
        errors.append(f"unexpected ruleset target: {ruleset.get('target')!r}")
    if ruleset.get("enforcement") != "active":
        errors.append("ruleset enforcement must be active")

    conditions = ruleset.get("conditions")
    ref_name = conditions.get("ref_name") if isinstance(conditions, dict) else None
    includes = ref_name.get("include") if isinstance(ref_name, dict) else None
    if not isinstance(includes, list) or not (
        "~DEFAULT_BRANCH" in includes or "refs/heads/main" in includes
    ):
        errors.append("ruleset must target the default/main branch")
    return errors


def build_target_ruleset(current: dict[str, Any]) -> dict[str, Any]:
    identity_errors = validate_identity(current)
    if identity_errors:
        raise ValueError("; ".join(identity_errors))

    target = copy.deepcopy(current)
    target["name"] = current.get("name") or "main"
    target["target"] = "branch"
    target["enforcement"] = "active"
    target["bypass_actors"] = []

    rules = target.get("rules")
    if not isinstance(rules, list):
        raise ValueError("ruleset rules must be a list")

    new_rules: list[dict[str, Any]] = []
    saw_deletion = False
    saw_non_fast_forward = False
    saw_pull_request = False
    saw_required_status_checks = False

    for raw_rule in rules:
        if not isinstance(raw_rule, dict) or not isinstance(raw_rule.get("type"), str):
            raise ValueError("ruleset contains an invalid rule object")

        rule = copy.deepcopy(raw_rule)
        rule_type = rule["type"]

        if rule_type == "deletion":
            saw_deletion = True
        elif rule_type == "non_fast_forward":
            saw_non_fast_forward = True
        elif rule_type == "pull_request":
            saw_pull_request = True
            params = rule.setdefault("parameters", {})
            if not isinstance(params, dict):
                raise ValueError("pull_request parameters must be an object")
            params["required_approving_review_count"] = 0
            params["dismiss_stale_reviews_on_push"] = False
            params["require_code_owner_review"] = False
            params["require_last_push_approval"] = False
            params["required_review_thread_resolution"] = True
        elif rule_type == "required_status_checks":
            saw_required_status_checks = True
            params = rule.setdefault("parameters", {})
            if not isinstance(params, dict):
                raise ValueError("required_status_checks parameters must be an object")
            params["strict_required_status_checks_policy"] = True
            params["do_not_enforce_on_create"] = False
            params["required_status_checks"] = [
                {"context": context} for context in REQUIRED_STATUS_CONTEXTS
            ]

        new_rules.append(rule)

    if not saw_deletion:
        new_rules.append({"type": "deletion"})
    if not saw_non_fast_forward:
        new_rules.append({"type": "non_fast_forward"})
    if not saw_pull_request:
        raise ValueError("existing ruleset must already contain a pull_request rule")
    if not saw_required_status_checks:
        raise ValueError("existing ruleset must already contain a required_status_checks rule")

    target["rules"] = new_rules

    return {
        "name": target["name"],
        "target": target["target"],
        "enforcement": target["enforcement"],
        "bypass_actors": target["bypass_actors"],
        "conditions": target["conditions"],
        "rules": target["rules"],
    }


def validate_effective_ruleset(ruleset: dict[str, Any]) -> list[str]:
    errors = validate_identity(ruleset)
    bypass_actors = ruleset.get("bypass_actors")
    if bypass_actors not in ([], None):
        errors.append("ruleset must not have bypass actors")

    rules = _rule_map(ruleset)
    if "deletion" not in rules:
        errors.append("branch deletion protection is missing")
    if "non_fast_forward" not in rules:
        errors.append("force-push protection is missing")

    pull_request = rules.get("pull_request")
    pr_params = pull_request.get("parameters") if isinstance(pull_request, dict) else None
    if not isinstance(pr_params, dict):
        errors.append("pull_request rule is missing")
    else:
        if pr_params.get("required_approving_review_count") != 0:
            errors.append("single-owner governance must require zero approving reviews")
        if pr_params.get("require_last_push_approval") is not False:
            errors.append("single-owner governance must keep last-push approval disabled")
        if pr_params.get("required_review_thread_resolution") is not True:
            errors.append("review-thread resolution must be required")

    status_rule = rules.get("required_status_checks")
    status_params = status_rule.get("parameters") if isinstance(status_rule, dict) else None
    if not isinstance(status_params, dict):
        errors.append("required_status_checks rule is missing")
    else:
        if status_params.get("strict_required_status_checks_policy") is not True:
            errors.append("required status checks must be strict/up-to-date")
        configured = status_params.get("required_status_checks")
        contexts = (
            {
                item.get("context")
                for item in configured
                if isinstance(item, dict)
            }
            if isinstance(configured, list)
            else set()
        )
        missing = [context for context in REQUIRED_STATUS_CONTEXTS if context not in contexts]
        if missing:
            errors.append(f"missing required status contexts: {', '.join(missing)}")

    return errors


def run_gh_api(method: str, *, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    command = [
        "gh",
        "api",
        "--method",
        method,
        "-H",
        "Accept: application/vnd.github+json",
        "-H",
        "X-GitHub-Api-Version: 2026-03-10",
        API_PATH,
    ]
    input_text = None
    if payload is not None:
        command.extend(["--input", "-"])
        input_text = json.dumps(payload)

    completed = subprocess.run(
        command,
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if completed.returncode != 0:
        detail = completed.stderr.strip() or completed.stdout.strip() or "unknown gh api failure"
        raise RuntimeError(f"gh api {method} failed: {detail}")

    try:
        result = json.loads(completed.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"gh api {method} returned invalid JSON") from exc
    if not isinstance(result, dict):
        raise RuntimeError(f"gh api {method} returned a non-object response")
    return result


def print_failures(prefix: str, failures: list[str]) -> None:
    print(prefix, file=sys.stderr)
    for failure in failures:
        print(f"- {failure}", file=sys.stderr)


def self_test() -> None:
    weak = {
        "id": RULESET_ID,
        "name": "main",
        "target": "branch",
        "source": REPOSITORY,
        "enforcement": "active",
        "conditions": {
            "ref_name": {
                "include": ["~DEFAULT_BRANCH", "refs/heads/main"],
                "exclude": [],
            }
        },
        "bypass_actors": [],
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {"type": "creation"},
            {
                "type": "pull_request",
                "parameters": {
                    "required_approving_review_count": 0,
                    "dismiss_stale_reviews_on_push": False,
                    "required_reviewers": [],
                    "require_code_owner_review": False,
                    "dismissal_restriction": {"enabled": False, "allowed_actors": []},
                    "require_last_push_approval": False,
                    "required_review_thread_resolution": True,
                    "require_extra_approval_for_unattributed_changes": True,
                    "allowed_merge_methods": ["merge", "squash", "rebase"],
                },
            },
            {
                "type": "required_status_checks",
                "parameters": {
                    "strict_required_status_checks_policy": False,
                    "do_not_enforce_on_create": False,
                    "required_status_checks": [{"context": "governance"}],
                },
            },
            {"type": "code_quality", "parameters": {"severity": "all"}},
        ],
    }

    weak_failures = validate_effective_ruleset(weak)
    assert weak_failures, "weak baseline must fail AC-1 certification"

    payload = build_target_ruleset(weak)
    effective = copy.deepcopy(weak)
    effective.update(payload)
    assert validate_effective_ruleset(effective) == [], "target ruleset must satisfy AC-1"

    rule_types = [rule["type"] for rule in effective["rules"]]
    assert "creation" in rule_types, "unrelated existing rules must be preserved"
    assert "code_quality" in rule_types, "unrelated security rules must be preserved"
    assert effective["bypass_actors"] == [], "target must not introduce bypass actors"

    pr_params = _rule_map(effective)["pull_request"]["parameters"]
    assert pr_params["required_approving_review_count"] == 0
    assert pr_params["require_last_push_approval"] is False
    assert pr_params["required_review_thread_resolution"] is True

    wrong_repo = copy.deepcopy(weak)
    wrong_repo["source"] = "example/wrong-repository"
    try:
        build_target_ruleset(wrong_repo)
    except ValueError:
        pass
    else:
        raise AssertionError("operator must reject the wrong repository identity")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--apply",
        action="store_true",
        help="apply the locked single-owner AC-1 target; without this flag the operator is read-only",
    )
    parser.add_argument(
        "--self-test",
        action="store_true",
        help="run deterministic operator regression tests without network access",
    )
    parser.add_argument(
        "--confirm-repository",
        default=None,
        help=f"required with --apply; must equal {REPOSITORY}",
    )
    args = parser.parse_args()

    if args.apply and args.self_test:
        parser.error("--apply and --self-test are mutually exclusive")

    if args.self_test:
        self_test()
        print("PASS apply_main_ruleset self-test")
        return 0

    current = run_gh_api("GET")

    if not args.apply:
        failures = validate_effective_ruleset(current)
        if failures:
            print_failures("MAIN RULESET AC-1 CHECK FAILED:", failures)
            return 1
        print(CERTIFICATION_MARKER)
        return 0

    if args.confirm_repository != REPOSITORY:
        print(
            f"--apply requires --confirm-repository {REPOSITORY}",
            file=sys.stderr,
        )
        return 2

    try:
        payload = build_target_ruleset(current)
        updated = run_gh_api("PUT", payload=payload)
    except (ValueError, RuntimeError) as exc:
        print(f"MAIN RULESET AC-1 APPLY FAILED: {exc}", file=sys.stderr)
        return 1

    immediate_failures = validate_effective_ruleset(updated)
    if immediate_failures:
        print_failures("MAIN RULESET AC-1 APPLY RESPONSE FAILED CERTIFICATION:", immediate_failures)
        return 1

    verified = run_gh_api("GET")
    failures = validate_effective_ruleset(verified)
    if failures:
        print_failures("MAIN RULESET AC-1 READ-BACK FAILED CERTIFICATION:", failures)
        return 1

    print(CERTIFICATION_MARKER)
    print("Ruleset hardening is certified for single-owner governance; independent PR approval is intentionally not required.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
