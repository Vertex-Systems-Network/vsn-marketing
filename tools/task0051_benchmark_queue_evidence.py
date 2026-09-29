#!/usr/bin/env python3
"""Fail-closed RBT-052 queue, graph, and canonical policy evidence validator."""
from __future__ import annotations

import argparse
import json
import math
import re
import sys


def require(ok: bool, reason: str) -> None:
    if not ok:
        raise ValueError(reason)


def duration(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value) and value >= 0


def validate_run(run: object, count: int, concurrency: int, graph_hash: str | None) -> str:
    require(isinstance(run, dict), "run must be an object")
    require(isinstance(run.get("graph_hash"), str) and re.fullmatch(r"[0-9a-f]{64}", run["graph_hash"]) is not None, "invalid graph hash")
    require(graph_hash is None or run["graph_hash"] == graph_hash, "graph changed across runs")
    require(run.get("operations") == count and run.get("concurrency") == concurrency, "fixture count/concurrency changed")
    require(duration(run.get("elapsed_ms")) and run["elapsed_ms"] > 0, "invalid elapsed time")
    require(run.get("throughput_per_second") == round(count * 1000 / max(run["elapsed_ms"], 0.001), 3), "throughput mismatch")
    require(isinstance(run.get("worker_passes"), int) and 1 <= run["worker_passes"] <= 91, "worker passes missing")
    samples = run.get("queue_samples")
    require(isinstance(samples, list) and len(samples) == count, "raw queue sample count mismatch")
    require([s.get("index") for s in samples if isinstance(s, dict)] == list(range(count)), "sample identities mismatch")
    for sample in samples:
        require(isinstance(sample, dict) and sample.get("workspace_slot") in (0, 1), "workspace sample mismatch")
        require(sample.get("node_attempts") == 5, "complete graph node attempts missing")
        require(duration(sample.get("queue_age_ms")) and duration(sample.get("end_to_end_ms")), "queue or end-to-end timing missing")
        require(sample["end_to_end_ms"] >= sample["queue_age_ms"], "queue age exceeds journey duration")
    return run["graph_hash"]


def validate(document: object) -> None:
    require(isinstance(document, dict) and document.get("schema_version") in (2, 3), "v2/v3 evidence required")
    for field in ("benchmark_id", "resource_profile"):
        require(isinstance(document.get(field), str) and re.fullmatch(r"[A-Za-z0-9._-]{1,100}", document[field]) is not None, "invalid "+field)
    require(isinstance(document.get("source_sha"), str) and re.fullmatch(r"[0-9a-f]{40}", document["source_sha"]) is not None, "source SHA invalid")
    require(isinstance(document.get("runner_image_sha"), str) and re.fullmatch(r"[0-9a-f]{64}", document["runner_image_sha"]) is not None, "runtime image digest invalid")
    require(document.get("redis_queue_measured") is True and document.get("graph_traversal_measured") is True, "queue/graph flags missing")
    require(document.get("synthetic_action_gate_invoked") is True and document.get("production_action_policy_measured") is False and document.get("provider_latency_measured") is False, "unsupported production/provider claim")
    if document["schema_version"] == 3:
        require(document.get("canonical_consent_suppression_measured") is True, "canonical policy checks missing")
        require(document.get("policy_denial_probe") == {
            "missing_consent": "failed_before_action_dispatch",
            "suppressed": "failed_before_action_dispatch",
        }, "policy denial probes missing")
    preflight = document.get("preflight")
    require(isinstance(preflight, dict) and preflight.get("preflight") == "passed" and preflight.get("source_sha") == document["source_sha"] and preflight.get("database") == document.get("database"), "preflight identity mismatch")
    require(isinstance(document.get("cpu_count"), int) and document["cpu_count"] > 0 and isinstance(document.get("memory_mib"), int) and document["memory_mib"] >= 512, "resource identity missing")
    require(isinstance(document.get("fixture_seed"), int) and document["fixture_seed"] >= 1, "fixture seed missing")
    runs = document.get("runs")
    require(isinstance(runs, list) and 2 <= len(runs) <= 5, "two to five measured runs required")
    count = runs[0].get("operations") if isinstance(runs[0], dict) else None
    concurrency = runs[0].get("concurrency") if isinstance(runs[0], dict) else None
    require(isinstance(count, int) and 20 <= count <= 1000 and isinstance(concurrency, int) and 2 <= concurrency <= 16, "invalid workload")
    warmup = document.get("warmup")
    graph = validate_run(warmup, 20, concurrency, None)
    for run in runs:
        validate_run(run, count, concurrency, graph)
    forbidden = {"secret", "password", "token", "email", "recipient", "provider_payload", "raw_payload"}
    def reject(value: object) -> None:
        if isinstance(value, dict):
            for key, child in value.items():
                require(key.lower() not in forbidden, "sensitive evidence field")
                reject(child)
        elif isinstance(value, list):
            for child in value:
                reject(child)
    reject(document)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--stdin", action="store_true")
    parser.add_argument("path", nargs="?")
    args = parser.parse_args()
    if args.stdin == (args.path is not None):
        parser.error("provide exactly one source")
    try:
        raw = sys.stdin.read() if args.stdin else open(args.path, encoding="utf-8").read()
        validate(json.loads(raw))
    except (OSError, ValueError, TypeError, KeyError, AttributeError) as error:
        print("RBT-052 queue evidence rejected: "+str(error), file=sys.stderr)
        return 2
    print("RBT-052 raw queue and graph evidence validated")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
