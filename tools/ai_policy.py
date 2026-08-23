#!/usr/bin/env python3
"""Machine validator for VSN Marketing AI control-plane registries."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REG = ROOT / ".ai" / "ai"
FILES = {
    "agents": REG / "AGENT-REGISTRY.yaml",
    "autonomy": REG / "AUTONOMY-POLICY.yaml",
    "models": REG / "MODEL-CAPABILITY-REGISTRY.yaml",
    "prompts": REG / "PROMPT-REGISTRY.yaml",
    "tools": REG / "TOOL-REGISTRY.yaml",
    "evals": REG / "EVAL-REGISTRY.yaml",
    "memory": REG / "MEMORY-POLICY.yaml",
    "observability": REG / "AI-OBSERVABILITY-SCHEMA.yaml",
}
RISK = {"R0": 0, "R1": 1, "R2": 2, "R3": 3}
HIGH_RISK_CLASSES = {
    "bulk_or_production_send", "sending_identity_or_domain_change",
    "provider_credentials_or_secret_scope_change", "billing_commitment",
    "destructive_data_operation", "security_or_governance_policy_change",
    "architecture_contract_change", "generated_connector_activation",
}


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def duplicates(values: list[str]) -> set[str]:
    seen, dup = set(), set()
    for value in values:
        if value in seen:
            dup.add(value)
        seen.add(value)
    return dup


def validate_documents(docs: dict[str, dict]) -> list[str]:
    errors: list[str] = []
    tiers = docs["autonomy"].get("tiers", {})
    if set(tiers) != set(RISK):
        errors.append("autonomy tiers must be exactly R0,R1,R2,R3")
    if tiers.get("R3", {}).get("automatic") is not False:
        errors.append("R3 must never be automatic")
    min_classes = docs["autonomy"].get("minimum_risk_classes", {})
    for name in HIGH_RISK_CLASSES:
        if min_classes.get(name) != "R3":
            errors.append(f"high-risk class {name} must remain R3")

    tool_rows = docs["tools"].get("tools", [])
    tool_ids = [row.get("id") for row in tool_rows]
    for dup in sorted(duplicates([x for x in tool_ids if x])):
        errors.append(f"duplicate tool id: {dup}")
    tool_map = {row.get("id"): row for row in tool_rows if row.get("id")}
    for tid, row in tool_map.items():
        risk = row.get("min_risk")
        if risk not in RISK:
            errors.append(f"tool {tid}: invalid min_risk {risk!r}")
        if row.get("effect") not in {"read", "proposal", "reversible_write", "side_effect"}:
            errors.append(f"tool {tid}: invalid effect {row.get('effect')!r}")
        if row.get("effect") in {"reversible_write", "side_effect"} and row.get("idempotency_required") is not True:
            errors.append(f"tool {tid}: write/side-effect tool must require idempotency")

    memory_scopes = set(docs["memory"].get("scopes", {}))
    agent_rows = docs["agents"].get("agents", [])
    agent_ids = [row.get("id") for row in agent_rows]
    for dup in sorted(duplicates([x for x in agent_ids if x])):
        errors.append(f"duplicate agent id: {dup}")
    for agent in agent_rows:
        aid = agent.get("id", "<missing>")
        max_risk = agent.get("max_autonomy")
        if max_risk not in RISK:
            errors.append(f"agent {aid}: invalid max_autonomy {max_risk!r}")
            continue
        for tid in agent.get("tools", []):
            tool = tool_map.get(tid)
            if not tool:
                errors.append(f"agent {aid}: unknown tool {tid}")
                continue
            min_risk = tool.get("min_risk")
            if min_risk in RISK and RISK[min_risk] > RISK[max_risk]:
                errors.append(f"agent {aid}: tool {tid} requires {min_risk} above agent maximum {max_risk}")
        for scope in agent.get("memory_scopes", []):
            if scope not in memory_scopes:
                errors.append(f"agent {aid}: unknown memory scope {scope}")

    eval_rows = docs["evals"].get("suites", [])
    eval_ids = [row.get("id") for row in eval_rows]
    for dup in sorted(duplicates([x for x in eval_ids if x])):
        errors.append(f"duplicate eval suite id: {dup}")
    eval_set = {x for x in eval_ids if x}

    prompt_rows = docs["prompts"].get("prompts", [])
    prompt_ids = [row.get("id") for row in prompt_rows]
    for dup in sorted(duplicates([x for x in prompt_ids if x])):
        errors.append(f"duplicate prompt id: {dup}")
    for prompt in prompt_rows:
        pid = prompt.get("id", "<missing>")
        suite = prompt.get("required_eval_suite")
        if suite not in eval_set:
            errors.append(f"prompt {pid}: unknown required eval suite {suite!r}")
        if prompt.get("status") == "active" and not prompt.get("current_version"):
            errors.append(f"prompt {pid}: active prompt must pin current_version")

    capabilities = docs["models"].get("capability_keys", [])
    for dup in sorted(duplicates(capabilities)):
        errors.append(f"duplicate model capability key: {dup}")
    for required in ("structured_output", "tool_calling", "usage_telemetry"):
        if required not in capabilities:
            errors.append(f"model capability registry missing required key {required}")

    required_trace = set(docs["observability"].get("required_trace_fields", []))
    for required in ("trace_id", "agent_id", "prompt_id", "prompt_version", "context_manifest_sha256", "risk_tier", "policy_decisions", "status"):
        if required not in required_trace:
            errors.append(f"observability schema missing required field {required}")
    return errors


def load_documents() -> dict[str, dict]:
    return {name: load(path) for name, path in FILES.items()}


def main() -> int:
    try:
        errors = validate_documents(load_documents())
    except (OSError, json.JSONDecodeError, KeyError, TypeError) as exc:
        print(f"AI policy registry error: {exc}", file=sys.stderr)
        return 1
    if errors:
        print("AI policy registry validation FAILED:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1
    print("AI policy registry validation PASSED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
