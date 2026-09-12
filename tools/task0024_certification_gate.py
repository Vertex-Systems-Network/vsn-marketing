#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import re
from pathlib import Path
from typing import Any

from delivery_benchmark_evidence import validate_and_aggregate

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
REQUIRED_REPOSITORY_EVIDENCE = (
    "docs/operations/TASK-0024-CERTIFICATION.md",
    "tools/delivery_benchmark_evidence.py",
    "tools/test_delivery_benchmark_evidence.py",
    "tests/Integration/DeliveryEngine/Phase04PostgresCertificationTest.php",
    "tests/Integration/DeliveryEngine/Phase04RedisCertificationTest.php",
    "tests/Integration/Providers/Phase04ProviderCertificationTest.php",
    "tests/Integration/DeliveryEngine/Phase04QueueCertificationTest.php",
    "tests/Integration/DeliveryEngine/Phase04RecoveryCertificationTest.php",
    "tests/Feature/DeliveryEngine/Phase04TelemetryCertificationTest.php",
    "tests/Feature/Security/Phase04DeliverySecurityCertificationTest.php",
)

REQUIRED_THRESHOLD_SPECS: dict[str, tuple[str, str, str]] = {
    "queue_age_p95_ms": ("queue_age_ms", "p95", "max"),
    "queue_age_p99_ms": ("queue_age_ms", "p99", "max"),
    "end_to_end_p95_ms": ("end_to_end_ms", "p95", "max"),
    "end_to_end_p99_ms": ("end_to_end_ms", "p99", "max"),
    "reconciliation_lag_p95_ms": ("reconciliation_lag_ms", "p95", "max"),
    "reconciliation_lag_p99_ms": ("reconciliation_lag_ms", "p99", "max"),
    "sustainable_throughput_ops_s": ("throughput_ops_s", "min", "min"),
}


def _object(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{label} must be an object")
    return value


def _string(value: Any, label: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"{label} must be a non-empty string")
    return value.strip()


def _number(value: Any, label: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError(f"{label} must be numeric")
    number = float(value)
    if not math.isfinite(number) or number <= 0:
        raise ValueError(f"{label} must be a positive finite number")
    return number


def _load_object(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return _object(data, str(path))


def _contains_tbd(value: Any) -> bool:
    if isinstance(value, str):
        return "TBD_MEASURED" in value.upper()
    if isinstance(value, dict):
        return any(_contains_tbd(key) or _contains_tbd(nested) for key, nested in value.items())
    if isinstance(value, list):
        return any(_contains_tbd(item) for item in value)
    return False


def _append_once(blockers: list[str], message: str) -> None:
    if message not in blockers:
        blockers.append(message)


def evaluate_certification(
    evidence_documents: list[dict[str, Any]],
    threshold_manifest: dict[str, Any],
    expected_commit_sha: str,
    slo_contract_text: str,
    repository_evidence: set[str],
) -> dict[str, Any]:
    expected_commit_sha = expected_commit_sha.strip().lower()
    if not SHA_RE.fullmatch(expected_commit_sha):
        raise ValueError("expected_commit_sha must be a 40-character hexadecimal Git SHA")
    if not evidence_documents:
        raise ValueError("at least one benchmark evidence document is required")

    evidence_by_id: dict[str, dict[str, Any]] = {}
    for index, document in enumerate(evidence_documents):
        aggregate = validate_and_aggregate(document)
        benchmark_id = str(aggregate["benchmark_id"])
        if benchmark_id in evidence_by_id:
            raise ValueError(f"duplicate benchmark_id: {benchmark_id}")
        if aggregate["commit_sha"] != expected_commit_sha:
            raise ValueError(
                f"benchmark {benchmark_id} commit_sha does not match expected acceptance head"
            )
        evidence_by_id[benchmark_id] = aggregate

    environments = [aggregate["environment"] for aggregate in evidence_by_id.values()]
    if environments and any(environment != environments[0] for environment in environments[1:]):
        environment_mismatch = True
    else:
        environment_mismatch = False

    manifest = _object(threshold_manifest, "threshold manifest")
    blockers: list[str] = []
    checks: list[dict[str, Any]] = []

    if environment_mismatch:
        _append_once(blockers, "benchmark evidence environments do not match")
    for path in REQUIRED_REPOSITORY_EVIDENCE:
        if path not in repository_evidence:
            _append_once(blockers, f"missing required repository evidence: {path}")

    if manifest.get("schema_version") != 1:
        _append_once(blockers, "threshold manifest schema_version must be 1")
    if manifest.get("task_id") != "TASK-0024":
        _append_once(blockers, "threshold manifest task_id must be TASK-0024")
    if _contains_tbd(manifest):
        _append_once(blockers, "threshold manifest contains unresolved TBD_MEASURED values")

    source_commit = str(manifest.get("source_commit_sha", "")).strip().lower()
    if source_commit != expected_commit_sha:
        _append_once(blockers, "threshold manifest source_commit_sha does not match acceptance head")

    if manifest.get("status") != "approved":
        _append_once(blockers, "threshold manifest is not explicitly approved")

    threshold_set_id = manifest.get("threshold_set_id")
    if not isinstance(threshold_set_id, str) or not threshold_set_id.strip():
        _append_once(blockers, "threshold manifest is missing threshold_set_id")

    approval = manifest.get("approval")
    if not isinstance(approval, dict):
        _append_once(blockers, "threshold manifest is missing Delivery-owner approval")
    else:
        if approval.get("role") != "delivery_owner":
            _append_once(blockers, "threshold manifest approval role must be delivery_owner")
        for field in ("approved_by", "approved_at"):
            value = approval.get(field)
            if not isinstance(value, str) or not value.strip():
                _append_once(blockers, f"threshold manifest approval.{field} is required")

    revisions = manifest.get("evidence_revisions")
    if not isinstance(revisions, dict):
        _append_once(blockers, "threshold manifest evidence_revisions must pin benchmark fingerprints")
        revisions = {}
    for benchmark_id, aggregate in evidence_by_id.items():
        if revisions.get(benchmark_id) != aggregate["evidence_fingerprint"]:
            _append_once(blockers, f"benchmark {benchmark_id} evidence fingerprint is not approved")
    for benchmark_id in revisions:
        if benchmark_id not in evidence_by_id:
            _append_once(blockers, f"approved benchmark revision is missing evidence: {benchmark_id}")

    if "TBD_MEASURED" in slo_contract_text.upper():
        _append_once(blockers, "TASK-0023 SLO contract still contains TBD_MEASURED")

    thresholds = manifest.get("thresholds")
    if not isinstance(thresholds, dict):
        _append_once(blockers, "threshold manifest thresholds must be an object")
        thresholds = {}

    required_names = set(REQUIRED_THRESHOLD_SPECS)
    actual_names = set(thresholds)
    for missing in sorted(required_names - actual_names):
        _append_once(blockers, f"missing required threshold: {missing}")
    for unexpected in sorted(actual_names - required_names):
        _append_once(blockers, f"unexpected threshold is not part of TASK-0024 contract: {unexpected}")

    for threshold_name in sorted(required_names & actual_names):
        raw_rule = thresholds[threshold_name]
        if not isinstance(raw_rule, dict):
            _append_once(blockers, f"threshold rule must be an object: {threshold_name}")
            continue

        expected_metric, expected_statistic, expected_operator = REQUIRED_THRESHOLD_SPECS[threshold_name]
        benchmark_id = raw_rule.get("benchmark_id")
        metric = raw_rule.get("metric")
        statistic = raw_rule.get("statistic")
        operator = raw_rule.get("operator")
        threshold_value = raw_rule.get("threshold")

        if metric != expected_metric or statistic != expected_statistic or operator != expected_operator:
            _append_once(blockers, f"threshold rule does not match canonical metric mapping: {threshold_name}")
            continue
        if not isinstance(benchmark_id, str) or benchmark_id not in evidence_by_id:
            _append_once(blockers, f"threshold references missing benchmark evidence: {threshold_name}")
            continue
        if isinstance(threshold_value, str) and "TBD_MEASURED" in threshold_value.upper():
            _append_once(blockers, f"threshold remains TBD_MEASURED: {threshold_name}")
            continue
        try:
            threshold = _number(threshold_value, f"thresholds.{threshold_name}.threshold")
        except ValueError as exc:
            _append_once(blockers, str(exc))
            continue

        aggregate = evidence_by_id[benchmark_id]
        if aggregate["scenario"]["fault_mode"] != "none":
            _append_once(
                blockers,
                f"fault scenario cannot supply steady-state acceptance threshold: {threshold_name}",
            )
            continue

        if expected_metric == "throughput_ops_s":
            actual = float(aggregate["throughput_ops_s"][expected_statistic])
        else:
            pooled = aggregate["pooled_metrics"]
            if expected_metric not in pooled:
                _append_once(blockers, f"benchmark {benchmark_id} is missing metric {expected_metric}")
                continue
            actual = float(pooled[expected_metric][expected_statistic])

        passed = actual <= threshold if expected_operator == "max" else actual >= threshold
        checks.append(
            {
                "threshold": threshold_name,
                "benchmark_id": benchmark_id,
                "metric": expected_metric,
                "statistic": expected_statistic,
                "operator": expected_operator,
                "actual": actual,
                "limit": threshold,
                "passed": passed,
            }
        )
        if not passed:
            _append_once(blockers, f"measured evidence does not satisfy threshold: {threshold_name}")

    status = "pass" if not blockers and len(checks) == len(REQUIRED_THRESHOLD_SPECS) else "blocked"
    return {
        "schema_version": 1,
        "task_id": "TASK-0024",
        "status": status,
        "certification_decision": status,
        "thresholds_inferred": False,
        "expected_commit_sha": expected_commit_sha,
        "threshold_set_id": threshold_set_id if isinstance(threshold_set_id, str) else None,
        "benchmark_ids": sorted(evidence_by_id),
        "checks": checks,
        "blockers": blockers,
    }


def main(argv: list[str] | None = None) -> int:
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(
        description="Fail-closed TASK-0024 certification gate for approved measured delivery thresholds."
    )
    parser.add_argument("--evidence", action="append", required=True, type=Path)
    parser.add_argument("--thresholds", required=True, type=Path)
    parser.add_argument("--expected-commit", required=True)
    parser.add_argument(
        "--slo-contract",
        type=Path,
        default=root / "docs" / "operations" / "TASK-0023-DELIVERY-SLOS.md",
    )
    args = parser.parse_args(argv)

    try:
        evidence_documents = [_load_object(path) for path in args.evidence]
        manifest = _load_object(args.thresholds)
        slo_contract_text = args.slo_contract.read_text(encoding="utf-8")
        repository_evidence = {
            path for path in REQUIRED_REPOSITORY_EVIDENCE if (root / path).is_file()
        }
        result = evaluate_certification(
            evidence_documents,
            manifest,
            args.expected_commit,
            slo_contract_text,
            repository_evidence,
        )
    except (OSError, json.JSONDecodeError, ValueError) as exc:
        print(json.dumps({"status": "error", "reason": str(exc)}, sort_keys=True))
        return 2

    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
