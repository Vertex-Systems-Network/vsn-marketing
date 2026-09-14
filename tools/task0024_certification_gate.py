#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import re
import subprocess
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
CANONICAL_SLO_CONTRACT = "docs/operations/TASK-0023-DELIVERY-SLOS.md"

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


def _sha(value: str, label: str) -> str:
    normalized = value.strip().lower()
    if not SHA_RE.fullmatch(normalized):
        raise ValueError(f"{label} must be a 40-character hexadecimal Git SHA")
    return normalized


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


def _git(root: Path, *args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *args],
        cwd=root,
        text=True,
        capture_output=True,
        check=False,
    )


def _require_clean_checkout(root: Path) -> None:
    status = _git(root, "status", "--porcelain")
    if status.returncode != 0:
        raise ValueError("unable to inspect final certification checkout")
    if status.stdout.strip():
        raise ValueError("final certification gate requires a clean committed checkout")


def _checkout_head(root: Path) -> str:
    result = _git(root, "rev-parse", "HEAD")
    if result.returncode != 0:
        raise ValueError("unable to resolve final acceptance HEAD")
    return _sha(result.stdout, "final acceptance HEAD")


def _source_is_ancestor(root: Path, benchmark_source_commit_sha: str, acceptance_head_sha: str) -> bool:
    result = _git(
        root,
        "merge-base",
        "--is-ancestor",
        benchmark_source_commit_sha,
        acceptance_head_sha,
    )
    if result.returncode == 0:
        return True
    if result.returncode == 1:
        return False
    raise ValueError(
        "unable to verify benchmark source ancestry; ensure the source commit exists in checkout history"
    )


def _tracked_repository_artifact(root: Path, path: Path, label: str) -> Path:
    root = root.resolve()
    candidate = path if path.is_absolute() else root / path
    resolved = candidate.resolve()
    try:
        relative = resolved.relative_to(root)
    except ValueError as exc:
        raise ValueError(f"{label} must be inside the repository") from exc
    if not resolved.is_file():
        raise ValueError(f"{label} does not exist: {relative.as_posix()}")

    tracked = _git(root, "ls-files", "--error-unmatch", "--", relative.as_posix())
    if tracked.returncode != 0:
        raise ValueError(f"{label} must be committed and tracked in the repository")

    committed = _git(root, "diff", "--quiet", "HEAD", "--", relative.as_posix())
    if committed.returncode == 1:
        raise ValueError(f"{label} must match the content committed at final acceptance HEAD")
    if committed.returncode != 0:
        raise ValueError(f"unable to verify committed {label} content")

    return resolved


def evaluate_certification(
    evidence_documents: list[dict[str, Any]],
    threshold_manifest: dict[str, Any],
    benchmark_source_commit_sha: str,
    acceptance_head_sha: str,
    source_commit_is_ancestor: bool,
    slo_contract_text: str,
    repository_evidence: set[str],
) -> dict[str, Any]:
    benchmark_source_commit_sha = _sha(
        benchmark_source_commit_sha,
        "benchmark_source_commit_sha",
    )
    acceptance_head_sha = _sha(acceptance_head_sha, "acceptance_head_sha")
    if not evidence_documents:
        raise ValueError("at least one benchmark evidence document is required")

    evidence_by_id: dict[str, dict[str, Any]] = {}
    for document in evidence_documents:
        aggregate = validate_and_aggregate(document)
        benchmark_id = str(aggregate["benchmark_id"])
        if benchmark_id in evidence_by_id:
            raise ValueError(f"duplicate benchmark_id: {benchmark_id}")
        if aggregate["commit_sha"] != benchmark_source_commit_sha:
            raise ValueError(
                f"benchmark {benchmark_id} commit_sha does not match benchmark source commit"
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

    if not source_commit_is_ancestor:
        _append_once(blockers, "benchmark source commit is not an ancestor of final acceptance head")
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
    if source_commit != benchmark_source_commit_sha:
        _append_once(blockers, "threshold manifest source_commit_sha does not match benchmark source commit")

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
        "benchmark_source_commit_sha": benchmark_source_commit_sha,
        "acceptance_head_sha": acceptance_head_sha,
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
    parser.add_argument(
        "--source-commit",
        required=True,
        help="Exact benchmark source commit measured by every evidence document and approved manifest.",
    )
    args = parser.parse_args(argv)

    try:
        _require_clean_checkout(root)
        benchmark_source_commit_sha = _sha(args.source_commit, "benchmark source commit")
        acceptance_head_sha = _checkout_head(root)
        source_commit_is_ancestor = _source_is_ancestor(
            root,
            benchmark_source_commit_sha,
            acceptance_head_sha,
        )
        evidence_paths = [
            _tracked_repository_artifact(root, path, "benchmark evidence")
            for path in args.evidence
        ]
        threshold_path = _tracked_repository_artifact(root, args.thresholds, "threshold manifest")
        slo_contract_path = _tracked_repository_artifact(
            root,
            Path(CANONICAL_SLO_CONTRACT),
            "canonical SLO contract",
        )
        evidence_documents = [_load_object(path) for path in evidence_paths]
        manifest = _load_object(threshold_path)
        slo_contract_text = slo_contract_path.read_text(encoding="utf-8")
        repository_evidence = {
            path for path in REQUIRED_REPOSITORY_EVIDENCE if (root / path).is_file()
        }
        result = evaluate_certification(
            evidence_documents,
            manifest,
            benchmark_source_commit_sha,
            acceptance_head_sha,
            source_commit_is_ancestor,
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
