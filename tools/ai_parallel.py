#!/usr/bin/env python3
"""Supervisor-controlled parallel development guard for VSN Marketing."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
PARALLEL = ROOT / ".ai" / "parallel"
CONTROL = PARALLEL / "CONTROL.yaml"
WORKSTREAMS = PARALLEL / "WORKSTREAMS.yaml"
LEASES = PARALLEL / "AGENT-LEASES.yaml"
SHARED = PARALLEL / "SHARED-PATHS.yaml"
PLAN = PARALLEL / "AI-NATIVE-PLAN.md"
STATE = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
README = ROOT / "README.md"
CLAUDE = ROOT / "CLAUDE.md"
RESILIENCE = ROOT / "docs" / "operations" / "AI-EXECUTION-RESILIENCE.md"

VALID_SLOT = {"open", "occupied"}
WRITABLE = {"leased", "in_progress", "paused_for_review", "submitted", "approved", "ready_for_merge"}


def load(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"missing file: {path.relative_to(ROOT)}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid JSON-compatible YAML: {path.relative_to(ROOT)}: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError(f"expected object: {path.relative_to(ROOT)}")
    return data


def dump(path: Path, data: dict[str, Any]) -> None:
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], cwd=ROOT, text=True, capture_output=True, check=check)


def branch_name() -> str:
    result = git("branch", "--show-current", check=False).stdout.strip()
    return result or os.environ.get("GITHUB_HEAD_REF", "")


def sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def instruction_fingerprint(control: dict[str, Any]) -> str:
    sources = control.get("instruction_sources")
    if not isinstance(sources, list) or not sources:
        raise ValueError("CONTROL instruction_sources must be a non-empty list")
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for raw in sources:
        if not isinstance(raw, str) or not raw.strip():
            raise ValueError("instruction source entries must be strings")
        rel = raw.strip().replace("\\", "/")
        if rel in seen:
            raise ValueError(f"duplicate instruction source: {rel}")
        seen.add(rel)
        path = ROOT / rel
        if not path.is_file():
            raise ValueError(f"instruction source missing: {rel}")
        rows.append({"path": rel, "sha256": sha256(path.read_text(encoding="utf-8"))})
    canonical = json.dumps(rows, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return sha256(canonical)


def rows(registry: dict[str, Any]) -> list[dict[str, Any]]:
    value = registry.get("workstreams", [])
    if not isinstance(value, list):
        raise ValueError("WORKSTREAMS workstreams must be a list")
    if not all(isinstance(row, dict) for row in value):
        raise ValueError("WORKSTREAMS entries must be objects")
    return value


def by_id(registry: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {str(row.get("id")): row for row in rows(registry)}


def by_branch(registry: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {str(row.get("branch")): row for row in rows(registry)}


def prefix(scope: str) -> str:
    value = scope.strip().replace("\\", "/").lstrip("./")
    for suffix in ("/**", "/*"):
        if value.endswith(suffix):
            value = value[: -len(suffix)]
    return value.rstrip("/")


def overlaps(a: str, b: str) -> bool:
    x, y = prefix(a), prefix(b)
    if not x or not y:
        return True
    return x == y or x.startswith(y + "/") or y.startswith(x + "/")


def standalone(text: str, expected: str) -> bool:
    return any(line.strip() == expected for line in text.splitlines())


def open_slots(registry: dict[str, Any]) -> list[dict[str, Any]]:
    candidates = [
        row for row in rows(registry)
        if row.get("role") != "supervisor"
        and row.get("slot_status") == "open"
        and not row.get("assigned_agent")
    ]
    return sorted(candidates, key=lambda row: (int(row.get("merge_group", 999)), str(row.get("id", ""))))


def current_active_task() -> str:
    state = load(STATE)
    return str(state.get("execution", {}).get("active_task", ""))


def validate() -> list[str]:
    errors: list[str] = []
    try:
        control, registry, leases, shared = load(CONTROL), load(WORKSTREAMS), load(LEASES), load(SHARED)
    except ValueError as exc:
        return [str(exc)]

    for name, doc in (("CONTROL", control), ("WORKSTREAMS", registry), ("AGENT-LEASES", leases), ("SHARED-PATHS", shared)):
        if doc.get("schema_version") != 2:
            errors.append(f"{name} schema_version must be 2")

    if control.get("protocol_version") != "2.8.0":
        errors.append("protocol_version must be 2.8.0")
    if control.get("protected_main_branch") != "main":
        errors.append("protected_main_branch must be main")
    if control.get("required_completion_signal") != "Work Done and Submitted":
        errors.append("completion signal must be exactly Work Done and Submitted")
    if control.get("onboarding_no_slot_message") != "Go Home Come Back Next Time":
        errors.append("onboarding no-slot message must be exactly Go Home Come Back Next Time")
    if not control.get("new_agent_must_start_from_main"):
        errors.append("new_agent_must_start_from_main must be true")
    if control.get("strict_plan_following") is not True:
        errors.append("strict_plan_following must be true")
    if control.get("change_aware_ci_enabled") is not True:
        errors.append("change_aware_ci_enabled must be true")
    if control.get("runner_benchmark_batch_deferred") is not True:
        errors.append("runner_benchmark_batch_deferred must be true")
    if control.get("runner_benchmark_batch_requires_explicit_activation") is not True:
        errors.append("runner_benchmark_batch_requires_explicit_activation must be true")
    if control.get("ci_force_full_marker") != "CI-Mode: full":
        errors.append("ci_force_full_marker must be exactly CI-Mode: full")
    expected_order = [
        "recover_validate",
        "read_compact_state",
        "resolve_exact_main",
        "reconcile_open_issues",
        "reconcile_open_prs",
        "reread_claims_coordination_queue_runner_benchmark",
        "classify_change",
        "execute_substantial_slice",
        "run_class_appropriate_checks",
        "persist_material_batch_state_in_same_scoped_pr",
        "require_exact_head_pr_gates",
        "bounded_same_scope_repair_if_needed",
        "merge_verified_head_when_green",
        "reread_post_merge_state_claims_queue_runner",
        "carry_forward_reconciliation_or_apply_exception",
    ]
    if control.get("strict_execution_order") != expected_order:
        errors.append("strict_execution_order drift")
    required_contract = {
        "supervisor_contract_version": "2.1",
        "coordination_queue_path": ".ai/coordination/OPEN-WORK-QUEUE.yaml",
        "runner_benchmark_path": ".ai/runner/RUNNER-BENCHMARK.yaml",
        "one_user_turn_one_logical_milestone": False,
        "fast_batch_development_mode": True,
        "one_user_turn_one_substantial_batch": False,
        "continuous_turn_multiple_substantial_slices": True,
        "same_turn_merge_when_required_gates_green": True,
        "bounded_same_scope_ci_repair_stays_in_batch": True,
        "unrelated_task_chaining_forbidden": True,
        "standalone_terminal_reconciliation_pr_default": False,
        "post_merge_reconciliation_strategy": "carry_forward_into_next_substantial_pr",
        "wave_acceleration_enabled": True,
        "wave_control_carrier_strategy": "batch_dependency_ready_disjoint_leases",
        "per_lane_control_pr_default": False,
        "independent_lane_coding_during_integration_verification": True,
        "independent_lane_submission_requires_latest_green_integration": True,
        "shipping_worker_pr_target": "ship/week-1",
        "shipping_sync_check_uses_integration_for_workers": True,
        "wave_main_promotion_strategy": "single_full_certification_after_green_integration_wave",
        "merge_alert_sync_boundary": "before_submission_or_dependency_consumption",
        "readme_progress_sync_mode": "marker_change_only",
        "default_ci_status_refreshes_per_milestone": 4,
        "max_ci_status_refreshes_with_recorded_exception": 12,
        "tight_polling_forbidden": True,
        "pending_ci_state_only_commit_forbidden": True,
        "issues_prs_first_hard_gate": True,
        "completion_requires_durable_state": True,
        "state_drift_reconciliation_required": True,
        "runtime_authority_must_be_current_explicit_and_unconsumed": True,
        "migration_safety_review_required": True,
        "readme_dashboard_churn_guard": True,
        "readme_progress_sync_required": True,
        "readme_progress_sync_trigger_path": ".ai/state/CURRENT-STATE.yaml",
        "readme_progress_sync_machine_marker": "AI_PROGRESS_SNAPSHOT",
        "next_action_options_required": False,
        "next_action_options_min_count": 0,
        "next_action_options_max_count": 3,
        "next_action_primary_source": "exact_next_safe_action",
        "next_action_interactive_control_preferred": False,
        "next_action_click_initiates_request_only": True,
        "next_action_selection_requires_full_resume_revalidation": True,
        "next_action_stale_selection_fails_closed": True,
        "next_action_stale_selection_continuous_fallback": "auto_route_current_canonical_safe_equivalent_or_successor",
        "next_action_fallback_format": "numbered_one_line_commands",
        "next_action_option_order_policy": "shuffle_each_handoff",
        "next_action_previous_selected_action_same_number_forbidden": True,
        "next_action_same_number_exception": "fewer_than_two_valid_options",
        "next_action_canonical_action_may_change_number": True,
        "next_action_recommended_label_required": True,
        "next_action_options_mode": "url_only_or_explicit_read_only_request",
        "next_action_final_handoff_policy": "single_canonical_resume_instruction_no_question",
        "security_fail_closed": True,
        "observed_main_semantics": "snapshot_basis_anchor",
        "self_reconciliation_descendant_is_current": True,
        "self_reconciliation_recursive_commit_forbidden": True,
        "workspace_continuous_batch_enabled": True,
        "workspace_continuous_batch_contract_path": ".ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md",
        "workspace_continuous_batch_duration_minutes": 300,
        "workspace_continuous_batch_default_for_mutating_resume": True,
        "workspace_continuous_batch_default_objective": "safe_canonical_roadmap_frontier_until_credit_exhaustion",
        "workspace_continuous_batch_no_reconfirmation_for_repo_scope": True,
        "workspace_continuous_batch_user_prompt_policy": "no_user_prompt_while_safe_canonical_work_exists",
        "workspace_continuous_batch_blocker_policy": "self_resolve_repo_blockers_quarantine_external_authority_continue_frontier",
        "workspace_continuous_batch_ambiguous_choice_policy": "deterministic_repository_evidence_precedence",
        "workspace_continuous_batch_scope_transition_policy": "guarded_transition_then_auto_advance_canonical_successor",
        "workspace_continuous_batch_entrypoint_consistency_guard": True,
        "workspace_continuous_batch_auto_advance_related_tasks": True,
        "workspace_continuous_batch_phase_boundary_requires_declared_scope": False,
        "workspace_continuous_batch_cross_phase_auto_advance": True,
        "workspace_continuous_batch_completed_phase_is_not_stop_condition": True,
        "workspace_continuous_batch_tool_fallback_required": True,
        "workspace_continuous_batch_suppress_intermediate_handoffs": True,
        "workspace_continuous_batch_final_handoff_only": True,
        "workspace_continuous_batch_handoff_requires_stop_condition": True,
        "workspace_continuous_batch_ci_failure_policy": "diagnose_repair_rerun_same_scope",
        "workspace_continuous_batch_external_wait_policy": "continue_independent_ready_work_else_bounded_backoff_observe_then_checkpoint",
        "workspace_continuous_batch_validator_failure_policy": "diagnose_repair_fallback_continue_without_user_confirmation",
        "workspace_continuous_batch_routine_confirmation_policy": "forbidden",
        "workspace_continuous_batch_ci_wait_confirmation_forbidden": True,
        "workspace_continuous_batch_human_boundary_policy": "record_exact_requirement_skip_lane_continue_frontier",
        "workspace_continuous_batch_hard_stop_prompt_policy": "report_exact_requirement_not_broad_yes_no_question",
        "workspace_continuous_batch_duplicate_pr_policy": "select_clean_authoritative_carrier_and_supersede_stale",
        "workspace_continuous_batch_merge_policy": "merge_verified_green_head_without_reconfirmation",
        "workspace_continuous_batch_checkpoint_policy": "material_boundary_credit_end_or_hard_blocker",
        "workspace_continuous_batch_decision_authority": "ai_selects_and_executes_highest_priority_safe_canonical_action",
        "workspace_continuous_batch_choice_prompt_forbidden": True,
        "workspace_continuous_batch_unknown_implementation_choice_policy": "canonical_architecture_then_least_privilege_then_smallest_reversible_tested_change",
        "workspace_continuous_batch_status_only_return_forbidden": True,
        "workspace_continuous_batch_pending_ci_terminal_response_forbidden": True,
        "workspace_continuous_batch_progress_update_policy": "nonterminal_update_then_continue_execution",
        "workspace_continuous_batch_host_execution_default": "assume_available_until_actual_credit_context_or_tool_limit_is_observed",
        "workspace_continuous_batch_handoff_proof_required": "documented_stop_condition_plus_no_executable_safe_action",
        "workspace_continuous_batch_ci_wait_status_phrase_policy": "never_end_with_next_once_ci_finishes_while_current_turn_can_still_execute",
    }
    for key, expected in required_contract.items():
        if control.get(key) != expected:
            errors.append(f"{key} must be {expected!r}")
    if control.get("workspace_continuous_batch_pre_handoff_recheck_required") != [
        "exact_main",
        "active_pr_exact_head_checks",
        "safe_independent_work",
        "recoverable_tool_paths",
        "remaining_credit_or_context",
    ]:
        errors.append("workspace_continuous_batch_pre_handoff_recheck_required drift")
    if control.get("workspace_continuous_batch_stop_conditions") != [
        "explicit_narrow_objective_complete_or_no_safe_canonical_ready_work",
        "workspace_credit_or_300_minute_window_exhausted",
        "human_only_external_authority_is_sole_remaining_frontier_path",
        "unresolved_safety_security_correctness_conflict_and_no_independent_safe_work",
        "all_available_repository_execution_paths_unavailable_after_bounded_recovery",
    ]:
        errors.append("workspace_continuous_batch_stop_conditions drift")
    if control.get("workspace_continuous_batch_human_only_boundaries") != [
        "production_or_provider_side_effect",
        "secret_disclosure_rotation_or_external_credential_action",
        "billing_or_payment_action",
        "destructive_data_or_migration_action",
        "branch_protection_or_required_check_weakening",
        "deployment_or_release_authority",
        "legal_compliance_owner_approval",
    ]:
        errors.append("workspace_continuous_batch_human_only_boundaries drift")
    if control.get("immediate_reconciliation_exceptions") != [
        "task_or_phase_final_acceptance",
        "guarded_task_transition",
        "release_or_promotion",
        "security_or_incident_recovery",
        "material_state_drift",
        "no_safe_successor_pr",
    ]:
        errors.append("immediate_reconciliation_exceptions drift")
    expected_self_exact = [
        ".ai/state/CURRENT-STATE.yaml",
        ".ai/state/LAST-CHECKPOINT.md",
        ".ai/state/EXECUTION-JOURNAL.jsonl",
        ".ai/coordination/OPEN-WORK-QUEUE.yaml",
        ".ai/runner/RUNNER-BENCHMARK.yaml",
        "README.md",
    ]
    if control.get("next_action_option_numbers") != [1, 2, 3]:
        errors.append("next_action_option_numbers drift")
    if control.get("self_reconciliation_exact_paths") != expected_self_exact:
        errors.append("self_reconciliation_exact_paths drift")
    if control.get("self_reconciliation_prefixes") != [".ai/state/archive/"]:
        errors.append("self_reconciliation_prefixes drift")
    if control.get("compact_state_limits_bytes") != {
        "current_state": 12288,
        "last_checkpoint": 16384,
        "active_execution_journal": 32768,
    }:
        errors.append("compact_state_limits_bytes drift")
    hard = int(control.get("hard_cap_writers", 0) or 0)
    default = int(control.get("default_max_concurrent_writers", 0) or 0)
    target = int(control.get("scale_target_writers", 0) or 0)
    if not (0 < default <= target <= hard <= 12):
        errors.append("writer capacity must satisfy 0 < default <= target <= hard <= 12")

    try:
        computed = instruction_fingerprint(control)
        configured = str(control.get("instruction_fingerprint", ""))
        if computed != configured:
            errors.append(f"instruction fingerprint drift: expected {configured}, computed {computed}")
        readme = README.read_text(encoding="utf-8")
        revision = str(control.get("instruction_revision", ""))
        if f"Agent instruction revision: `{revision}`" not in readme:
            errors.append("README instruction revision is stale")
        if f"Agent instruction fingerprint: `{configured}`" not in readme:
            errors.append("README instruction fingerprint is stale")

        claude = CLAUDE.read_text(encoding="utf-8")
        if "Do not jump ahead to a later phase." in claude:
            errors.append("CLAUDE.md contains legacy phase-stop instruction")
        if "automatically while Workspace credit remains" not in claude:
            errors.append("CLAUDE.md continuous cross-phase routing instruction is missing")

        resilience = RESILIENCE.read_text(encoding="utf-8")
        if "complete one substantial coherent batch inside the active task" in resilience:
            errors.append("AI execution resilience contains legacy task-sized batch instruction")
        if "execute one substantial approved batch inside the active task" in resilience:
            errors.append("AI execution resilience contains legacy active-task execution boundary")
        if "safe canonical roadmap frontier" not in resilience:
            errors.append("AI execution resilience roadmap-frontier instruction is missing")

        workspace_contract = (ROOT / ".ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md").read_text(encoding="utf-8")
        parallel_contract = (ROOT / ".ai/13-PARALLEL-DEVELOPMENT.md").read_text(encoding="utf-8")
        next_action_contract = (ROOT / ".ai/NEXT-ACTION-OPTIONS.md").read_text(encoding="utf-8")

        if "CI waiting by itself is never a stop condition" not in workspace_contract:
            errors.append("continuous instruction content regressed to CI-wait handoff")
        if "status-only terminal reply" not in workspace_contract:
            errors.append("continuous instruction content missing nonterminal status rule")
        if "progress/status message never ends the batch" not in parallel_contract:
            errors.append("parallel instruction content missing nonterminal progress rule")
        if "Progress updates are nonterminal" not in next_action_contract:
            errors.append("next-action instruction content missing nonterminal progress rule")
        if "End the batch for CI waiting only when" in parallel_contract:
            errors.append("parallel instruction content contains legacy CI-wait stop boundary")
        if "before ending the batch. CI waiting never becomes a user confirmation request." in workspace_contract:
            errors.append("workspace instruction content contains legacy CI-wait terminal wording")
    except (OSError, ValueError) as exc:
        errors.append(str(exc))

    mode = registry.get("mode")
    if mode not in {"staged", "active"}:
        errors.append("WORKSTREAMS mode must be staged or active")
    parent = str(registry.get("parent_task", ""))
    if mode == "active" and parent != current_active_task():
        errors.append(f"active workstreams parent {parent} does not match active task {current_active_task()}")

    ids: set[str] = set()
    branches: set[str] = set()
    worktrees: set[str] = set()
    agent_seen: set[str] = set()
    all_rows = rows(registry)
    all_ids = {str(item.get("id")) for item in all_rows}
    for row in all_rows:
        wid, branch, worktree = str(row.get("id", "")), str(row.get("branch", "")), str(row.get("worktree", ""))
        if not wid or wid in ids:
            errors.append(f"invalid/duplicate workstream id: {wid!r}")
        ids.add(wid)
        if not branch or branch in branches:
            errors.append(f"invalid/duplicate workstream branch: {branch!r}")
        branches.add(branch)
        if not worktree or worktree in worktrees:
            errors.append(f"invalid/duplicate worktree: {worktree!r}")
        worktrees.add(worktree)
        slot = row.get("slot_status")
        if slot not in VALID_SLOT:
            errors.append(f"{wid}: invalid slot_status {slot!r}")
        agent = row.get("assigned_agent")
        role = row.get("role")
        if slot == "open" and agent:
            errors.append(f"{wid}: open slot cannot have assigned_agent")
        if slot == "occupied" and not agent:
            errors.append(f"{wid}: occupied slot requires assigned_agent")
        if agent:
            if str(agent) in agent_seen:
                errors.append(f"agent assigned to multiple workstreams: {agent}")
            agent_seen.add(str(agent))
        if role == "supervisor" and agent != control.get("supervisor_agent"):
            errors.append(f"{wid}: Supervisor lane must be assigned to {control.get('supervisor_agent')}")
        if row.get("merge_strategy") != control.get("default_pr_merge_strategy"):
            errors.append(f"{wid}: merge strategy drift")
        for dep in row.get("dependencies", []):
            if dep not in all_ids:
                errors.append(f"{wid}: unknown dependency {dep}")

    shared_paths = shared.get("supervisor_owned_paths", [])
    if not isinstance(shared_paths, list):
        errors.append("SHARED-PATHS supervisor_owned_paths must be a list")
        shared_paths = []
    writable_rows = [row for row in all_rows if row.get("status") in WRITABLE]
    if len(writable_rows) > hard:
        errors.append(f"active writer count {len(writable_rows)} exceeds hard cap {hard}")
    for i, left in enumerate(writable_rows):
        for right in writable_rows[i + 1:]:
            for a in left.get("write_paths", []):
                for b in right.get("write_paths", []):
                    if overlaps(str(a), str(b)):
                        errors.append(f"active write-scope overlap: {left.get('id')} {a} <-> {right.get('id')} {b}")
        if left.get("role") != "supervisor":
            for owned in left.get("write_paths", []):
                for locked in shared_paths:
                    if overlaps(str(owned), str(locked)):
                        errors.append(f"{left.get('id')}: worker scope overlaps Supervisor-owned path: {owned} <-> {locked}")

    lease_rows = leases.get("leases", [])
    if not isinstance(lease_rows, list):
        errors.append("AGENT-LEASES leases must be a list")
        lease_rows = []
    lease_agents: set[str] = set()
    lease_workstreams: set[str] = set()
    mapping = by_id(registry)
    for lease in lease_rows:
        if not isinstance(lease, dict):
            errors.append("lease entries must be objects")
            continue
        agent, wid = str(lease.get("agent", "")), str(lease.get("workstream", ""))
        if agent in lease_agents:
            errors.append(f"duplicate lease for agent {agent}")
        lease_agents.add(agent)
        if wid in lease_workstreams:
            errors.append(f"duplicate lease for workstream {wid}")
        lease_workstreams.add(wid)
        if wid not in mapping:
            errors.append(f"lease references unknown workstream {wid}")
        elif mapping[wid].get("assigned_agent") != agent:
            errors.append(f"lease agent mismatch for {wid}")
    if mode == "staged" and lease_rows:
        errors.append("staged workstreams must have zero leases")
    if registry.get("branches_precreated") is not True:
        errors.append("WORKSTREAMS branches_precreated must be true")
    return errors


def remote_branch_exists(branch: str) -> bool:
    result = git("ls-remote", "--exit-code", "--heads", "origin", f"refs/heads/{branch}", check=False)
    return result.returncode == 0 and bool(result.stdout.strip())


def validate_remote_branches() -> list[str]:
    registry = load(WORKSTREAMS)
    return [f"missing pre-created remote branch: {row.get('branch')}" for row in rows(registry) if not remote_branch_exists(str(row.get("branch", "")))]


def expected_base(control: dict[str, Any], row: dict[str, Any]) -> str:
    if control.get("shipping_mode") is True and row.get("role") != "supervisor":
        target = str(
            control.get("shipping_worker_pr_target")
            or control.get("shipping_integration_branch")
            or ""
        ).strip()
        if target:
            return target
    return str(control.get("protected_main_branch", "main")).strip()


def sync_check(branch: str | None) -> list[str]:
    registry = load(WORKSTREAMS)
    control = load(CONTROL)
    current = branch or branch_name()
    row = by_branch(registry).get(current)
    if row is None:
        return []
    baseline = expected_base(control, row)
    git("fetch", "origin", baseline, "--quiet", check=False)
    candidate = f"origin/{baseline}"
    if git("rev-parse", "--verify", candidate, check=False).returncode != 0:
        candidate = baseline
    if git("merge-base", "--is-ancestor", candidate, "HEAD", check=False).returncode != 0:
        return [
            f"registered branch {current} is stale; merge latest {baseline} "
            "before submission/dependency consumption"
        ]
    return []


def validate_pr_event(path: Path) -> list[str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    pr = payload.get("pull_request")
    if not isinstance(pr, dict):
        return []
    head = pr.get("head", {}).get("ref")
    registry = load(WORKSTREAMS)
    control = load(CONTROL)
    row = by_branch(registry).get(str(head))
    if row is None:
        return []
    errors: list[str] = []
    required_base = expected_base(control, row)
    actual_base = str(pr.get("base", {}).get("ref") or "")
    if actual_base != required_base:
        errors.append(f"registered workstream PR {head} must target {required_base}")
    body = pr.get("body") or ""
    marker = f"Workstream: {row.get('id')}"
    if not standalone(body, marker):
        errors.append(f"registered workstream PR must contain standalone line: {marker}")
    if not pr.get("draft"):
        signal = str(control.get("required_completion_signal"))
        if not standalone(body, signal):
            errors.append(f"non-draft workstream PR must contain exact standalone signal: {signal}")
    return errors


def onboarding_check(branch: str | None) -> tuple[int, str]:
    control, registry = load(CONTROL), load(WORKSTREAMS)
    current = branch or branch_name()
    main = str(control.get("protected_main_branch", "main"))
    if current != main:
        return 2, f"New agent onboarding must start from {main}."
    active = current_active_task()
    parent = str(registry.get("parent_task", ""))
    if parent != active:
        return 4, f"No current parallel onboarding cycle: staged parent {parent} does not match active task {active}."
    slots = open_slots(registry)
    if not slots:
        return 3, str(control.get("onboarding_no_slot_message"))
    return 0, "Open slots: " + ", ".join(str(row.get("id")) for row in slots)


def render_plan_table(registry: dict[str, Any]) -> str:
    lines = [
        "| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |",
        "|---:|---|---|---|---|---|---|---|---|",
    ]
    for row in sorted(rows(registry), key=lambda r: (int(r.get("merge_group", 999)), str(r.get("id", "")))):
        agent = f"`{row.get('assigned_agent')}`" if row.get("assigned_agent") else "—"
        slot = "**OPEN**" if row.get("slot_status") == "open" else "`occupied`"
        baseline = (
            "latest green ship/week-1 before submission"
            if load(CONTROL).get("shipping_mode") is True and row.get("role") != "supervisor"
            else "merge latest main before resume"
        )
        lines.append(f"| {row.get('merge_group')} | {row.get('id')} | {row.get('capability')} | {slot} | {agent} | `{row.get('start_status')}` | `{row.get('branch')}` | {row.get('merge_strategy')} | {baseline} |")
    return "\n".join(lines)


def refresh_plan(registry: dict[str, Any]) -> None:
    text = PLAN.read_text(encoding="utf-8")
    start, end = "<!-- WORKSTREAM_TABLE_START -->", "<!-- WORKSTREAM_TABLE_END -->"
    if start not in text or end not in text:
        raise ValueError("AI-NATIVE-PLAN is missing workstream table markers")
    before, rest = text.split(start, 1)
    _, after = rest.split(end, 1)
    PLAN.write_text(before + start + "\n" + render_plan_table(registry) + "\n" + end + after, encoding="utf-8")


def onboard(agent: str, start_branch: str) -> tuple[int, str]:
    control, registry = load(CONTROL), load(WORKSTREAMS)
    main = str(control.get("protected_main_branch", "main"))
    if start_branch != main:
        return 2, f"New agent onboarding must start from {main}."
    active = current_active_task()
    parent = str(registry.get("parent_task", ""))
    if parent != active:
        return 4, f"Cannot onboard against stale parallel registry {parent}; canonical active task is {active}."
    if any(row.get("assigned_agent") == agent for row in rows(registry)):
        return 2, f"Agent {agent} is already assigned."
    slots = open_slots(registry)
    if not slots:
        return 3, str(control.get("onboarding_no_slot_message"))
    slot = slots[0]
    slot["assigned_agent"] = agent
    slot["slot_status"] = "occupied"
    slot["onboarded_from_branch"] = main
    if registry.get("mode") == "staged":
        slot["start_status"] = "assigned_waiting_for_task_activation"
    else:
        mapping = by_id(registry)
        deps_ready = all(mapping.get(dep, {}).get("status") == "merged" for dep in slot.get("dependencies", []))
        slot["start_status"] = "ready_for_lease" if deps_ready else "assigned_waiting_for_dependencies"
    dump(WORKSTREAMS, registry)
    refresh_plan(registry)
    return 0, f"Assigned {agent} to {slot.get('id')} on {slot.get('branch')}"


def status() -> None:
    control, registry, leases = load(CONTROL), load(WORKSTREAMS), load(LEASES)
    print(f"Parallel mode: {registry.get('mode')}")
    print(f"Parent task: {registry.get('parent_task')} (canonical active: {current_active_task()})")
    print(f"Supervisor: {control.get('supervisor_agent')}")
    print(f"Open worker slots: {len(open_slots(registry))}")
    print(f"Active leases: {len(leases.get('leases', []))}")
    for row in sorted(rows(registry), key=lambda r: (int(r.get("merge_group", 999)), str(r.get("id", "")))):
        print(f"- {row.get('id')}: slot={row.get('slot_status')} agent={row.get('assigned_agent')} branch={row.get('branch')} start={row.get('start_status')}")


def batch_status() -> None:
    control = load(CONTROL)
    print("Workspace continuous batch: ENABLED" if control.get("workspace_continuous_batch_enabled") else "Workspace continuous batch: DISABLED")
    print(f"Duration minutes: {control.get('workspace_continuous_batch_duration_minutes')}")
    print(f"Default objective: {control.get('workspace_continuous_batch_default_objective')}")
    print(f"Contract: {control.get('workspace_continuous_batch_contract_path')}")
    print(f"No routine reconfirmation: {control.get('workspace_continuous_batch_no_reconfirmation_for_repo_scope')}")
    print(f"CI failure policy: {control.get('workspace_continuous_batch_ci_failure_policy')}")
    print(f"Merge policy: {control.get('workspace_continuous_batch_merge_policy')}")
    print(f"Intermediate handoffs suppressed: {control.get('workspace_continuous_batch_suppress_intermediate_handoffs')}")


def print_errors(errors: list[str]) -> int:
    if not errors:
        return 0
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)
    return 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("validate-remote-branches")
    sub.add_parser("status")
    sub.add_parser("fingerprint")
    sub.add_parser("batch-status")
    sync = sub.add_parser("sync-check"); sync.add_argument("--branch")
    pr = sub.add_parser("validate-pr-event"); pr.add_argument("--event-path", default=os.environ.get("GITHUB_EVENT_PATH"))
    oc = sub.add_parser("onboarding-check"); oc.add_argument("--branch")
    ob = sub.add_parser("onboard"); ob.add_argument("--agent", required=True); ob.add_argument("--agent-start-branch", required=True)
    args = parser.parse_args()
    try:
        if args.command == "validate":
            errors = validate()
            if errors: return print_errors(errors)
            print("Parallel development validation PASSED"); return 0
        if args.command == "validate-remote-branches":
            errors = validate_remote_branches()
            if errors: return print_errors(errors)
            print("All required pre-created remote workstream branches exist."); return 0
        if args.command == "status":
            errors = validate()
            if errors: return print_errors(errors)
            status(); return 0
        if args.command == "fingerprint":
            print(instruction_fingerprint(load(CONTROL))); return 0
        if args.command == "batch-status":
            errors = validate()
            if errors: return print_errors(errors)
            batch_status(); return 0
        if args.command == "sync-check":
            errors = sync_check(args.branch)
            if errors: return print_errors(errors)
            print("Parallel main-sync check PASSED"); return 0
        if args.command == "validate-pr-event":
            if not args.event_path:
                print("No PR event path; skipping registered workstream PR validation."); return 0
            return print_errors(validate_pr_event(Path(args.event_path)))
        if args.command == "onboarding-check":
            code, message = onboarding_check(args.branch); print(message); return code
        if args.command == "onboard":
            code, message = onboard(args.agent, args.agent_start_branch); print(message); return code
    except (ValueError, OSError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(f"Parallel development error: {exc}", file=sys.stderr); return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
