#!/usr/bin/env python3
"""Deterministic negative/positive tests for TASK-0014 security controls."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path

import check_action_pins
import normalize_sbom

PINNED_SHA = "3d3c42e5aac5ba805825da76410c181273ba90b1"


def assert_true(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def test_action_pin_guard() -> None:
    assert_true(
        check_action_pins.validate_reference(f"actions/checkout@{PINNED_SHA}") is None,
        "full commit SHA must be accepted",
    )
    assert_true(
        check_action_pins.validate_reference("./.github/actions/local") is None,
        "local repository action must be accepted",
    )
    assert_true(
        check_action_pins.validate_reference("docker://alpine@sha256:" + "a" * 64) is None,
        "immutable Docker digest must be accepted",
    )
    assert_true(
        check_action_pins.validate_reference("actions/checkout@v7") is not None,
        "mutable action tag must be rejected",
    )
    assert_true(
        check_action_pins.validate_reference("docker://alpine:3.22") is not None,
        "mutable Docker action tag must be rejected",
    )

    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        workflow_dir = root / ".github" / "workflows"
        workflow_dir.mkdir(parents=True)
        (workflow_dir / "unsafe.yml").write_text(
            "jobs:\n  test:\n    steps:\n      - uses: actions/checkout@v7\n",
            encoding="utf-8",
        )
        failures = check_action_pins.validate_repository(root)
        assert_true(len(failures) == 1, "repository scan must report mutable workflow dependency")


def sbom(serial: str, timestamp: str, root_ref: str, component_ref: str, root_name: str) -> dict:
    return {
        "$schema": "http://cyclonedx.org/schema/bom-1.6.schema.json",
        "bomFormat": "CycloneDX",
        "specVersion": "1.6",
        "serialNumber": serial,
        "version": 1,
        "metadata": {
            "timestamp": timestamp,
            "component": {
                "bom-ref": root_ref,
                "type": "application",
                "name": root_name,
                "properties": [{"name": "aquasecurity:trivy:SchemaVersion", "value": "2"}],
            },
        },
        "components": [
            {
                "bom-ref": component_ref,
                "type": "library",
                "name": "example/package",
                "version": "1.2.3",
                "purl": "pkg:composer/example/package@1.2.3",
            }
        ],
        "dependencies": [
            {"ref": root_ref, "dependsOn": [component_ref]},
            {"ref": component_ref, "dependsOn": []},
        ],
        "vulnerabilities": [],
    }


def test_sbom_normalization() -> None:
    first = sbom(
        "urn:uuid:11111111-1111-4111-8111-111111111111",
        "2026-08-25T17:00:00+00:00",
        "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
        "/home/runner/work/vsn-marketing/vsn-marketing",
    )
    second = sbom(
        "urn:uuid:22222222-2222-4222-8222-222222222222",
        "2026-08-25T17:00:01+00:00",
        "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
        "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
        "/tmp/other-checkout/vsn-marketing",
    )

    normalized_first = normalize_sbom.normalize_document(first, root_name="vsn-marketing")
    normalized_second = normalize_sbom.normalize_document(second, root_name="vsn-marketing")

    assert_true(
        normalize_sbom.canonical_json(normalized_first)
        == normalize_sbom.canonical_json(normalized_second),
        "volatile CycloneDX serial/timestamp/BOM refs/path must normalize deterministically",
    )
    assert_true("serialNumber" not in normalized_first, "CycloneDX document serial must be removed")
    assert_true("timestamp" not in normalized_first["metadata"], "metadata timestamp must be removed")

    root_ref = normalized_first["metadata"]["component"]["bom-ref"]
    component_ref = normalized_first["components"][0]["bom-ref"]
    dependency_refs = {item["ref"] for item in normalized_first["dependencies"]}
    assert_true(root_ref in dependency_refs, "rewritten root BOM ref must remain linked")
    assert_true(component_ref in dependency_refs, "rewritten component BOM ref must remain linked")


def main() -> int:
    tests = [test_action_pin_guard, test_sbom_normalization]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")
    print(f"Security control tests PASS: {len(tests)} test(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
