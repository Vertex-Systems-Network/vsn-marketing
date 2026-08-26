#!/usr/bin/env python3
"""Deterministic negative/positive tests for TASK-0014 security controls."""

from __future__ import annotations

import json
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path

import check_action_pins
import normalize_sbom
import security_exceptions

PINNED_SHA = "3d3c42e5aac5ba805825da76410c181273ba90b1"
ROOT = Path(__file__).resolve().parents[1]


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
            "jobs:\n"
            "  tagged:\n"
            "    steps:\n"
            "      - uses: actions/checkout@v7\n"
            "  quoted:\n"
            "    steps:\n"
            "      - 'uses' : actions/setup-node@v6\n"
            "  flow:\n"
            "    steps:\n"
            "      - { uses: actions/checkout@v7 }\n",
            encoding="utf-8",
        )
        failures = check_action_pins.validate_repository(root)
        assert_true(
            len(failures) == 3,
            f"repository scan must fail closed on mutable/noncanonical workflow dependencies: {failures}",
        )
        assert_true(
            any("unsupported/noncanonical uses syntax" in failure for failure in failures),
            "flow-style uses syntax must be rejected rather than skipped",
        )


def test_security_exception_governance() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        security_dir = root / "security"
        security_dir.mkdir(parents=True)
        registry = security_dir / "exceptions.json"

        original_root = security_exceptions.ROOT
        original_registry = security_exceptions.REGISTRY
        original_forbidden = security_exceptions.FORBIDDEN_IGNORE_FILES
        original_configs = security_exceptions.FORBIDDEN_SCANNER_CONFIG_FILES
        security_exceptions.ROOT = root
        security_exceptions.REGISTRY = registry
        security_exceptions.FORBIDDEN_IGNORE_FILES = (
            root / ".trivyignore",
            root / ".trivyignore.yaml",
            root / ".semgrepignore",
        )
        security_exceptions.FORBIDDEN_SCANNER_CONFIG_FILES = (
            root / "trivy.yaml",
            root / "trivy-secret.yaml",
        )

        now = datetime(2026, 8, 10, tzinfo=timezone.utc)
        valid = {
            "id": "SEC-EX-0001",
            "scanner": "trivy",
            "finding": "CVE-2099-0001",
            "owner": "security-owner",
            "reason": "Temporary upstream remediation window",
            "approved_by": "independent-reviewer",
            "created_at": "2026-08-01T00:00:00+00:00",
            "expires_at": "2026-08-20T00:00:00+00:00",
            "evidence": "https://example.invalid/security/SEC-EX-0001",
        }

        def write_registry(exception: dict) -> None:
            registry.write_text(
                json.dumps({"schema_version": 1, "exceptions": [exception]}, indent=2) + "\n",
                encoding="utf-8",
            )

        try:
            write_registry(valid)
            assert_true(
                security_exceptions.validate_registry(now=now) == [],
                "well-formed independently approved time-bounded exception must pass",
            )

            self_approved = dict(valid, approved_by=valid["owner"].upper())
            write_registry(self_approved)
            errors = security_exceptions.validate_registry(now=now)
            assert_true(
                any("independent from owner" in error for error in errors),
                "case-only identity changes must not bypass independent approval",
            )

            overlong = dict(
                valid,
                created_at="2026-08-01T00:00:00+00:00",
                expires_at="2026-09-01T00:00:01+00:00",
            )
            write_registry(overlong)
            errors = security_exceptions.validate_registry(now=now)
            assert_true(
                any("maximum 30-day" in error for error in errors),
                "exception longer than 30 days must fail",
            )

            expired = dict(valid, expires_at="2026-08-09T23:59:59+00:00")
            write_registry(expired)
            errors = security_exceptions.validate_registry(now=now)
            assert_true(
                any("is expired" in error for error in errors),
                "expired security exception must fail",
            )

            write_registry(valid)
            (root / ".trivyignore").write_text("CVE-2099-0001\n", encoding="utf-8")
            errors = security_exceptions.validate_registry(now=now)
            assert_true(
                any("hidden scanner suppressions" in error for error in errors),
                "scanner-local suppression must fail outside canonical registry",
            )
            (root / ".trivyignore").unlink()

            for config_name in ("trivy.yaml", "trivy-secret.yaml"):
                (root / config_name).write_text("allow-rules: []\n", encoding="utf-8")
                errors = security_exceptions.validate_registry(now=now)
                assert_true(
                    any("scanner auto-loads it" in error for error in errors),
                    f"auto-loaded {config_name} must fail outside reviewed policy",
                )
                (root / config_name).unlink()

            (root / "composer.json").write_text(
                json.dumps({"config": {"audit": {"ignore": ["CVE-2099-0001"]}}}),
                encoding="utf-8",
            )
            errors = security_exceptions.validate_registry(now=now)
            assert_true(
                any("config.audit.ignore is forbidden" in error for error in errors),
                "Composer audit suppression must fail outside canonical registry",
            )
        finally:
            security_exceptions.ROOT = original_root
            security_exceptions.REGISTRY = original_registry
            security_exceptions.FORBIDDEN_IGNORE_FILES = original_forbidden
            security_exceptions.FORBIDDEN_SCANNER_CONFIG_FILES = original_configs


def test_security_workflow_fail_closed_flags() -> None:
    workflow = (ROOT / ".github" / "workflows" / "security-ci.yml").read_text(encoding="utf-8")
    assert_true(
        "--disable-nosem" in workflow,
        "PHP SAST must disable inline nosemgrep suppression in blocking CI",
    )
    assert_true(
        "--severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL" in workflow,
        "credential scans must fail on findings at every Trivy secret severity",
    )
    assert_true(
        "--scanners vuln" in workflow and "--scanners secret" in workflow,
        "container vulnerability and secret thresholds must be separated",
    )


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
    serial = normalized_first.get("serialNumber")
    assert_true(isinstance(serial, str) and serial.startswith("urn:uuid:"), "CycloneDX serial must remain attestable")
    uuid.UUID(serial.removeprefix("urn:uuid:"))
    assert_true(
        normalized_first.get("bomFormat") == "CycloneDX"
        and bool(normalized_first.get("specVersion"))
        and bool(serial),
        "actions/attest CycloneDX format detection fields must be preserved",
    )
    assert_true("timestamp" not in normalized_first["metadata"], "metadata timestamp must be removed")

    root_ref = normalized_first["metadata"]["component"]["bom-ref"]
    component_ref = normalized_first["components"][0]["bom-ref"]
    dependency_refs = {item["ref"] for item in normalized_first["dependencies"]}
    assert_true(root_ref in dependency_refs, "rewritten root BOM ref must remain linked")
    assert_true(component_ref in dependency_refs, "rewritten component BOM ref must remain linked")


def main() -> int:
    tests = [
        test_action_pin_guard,
        test_security_exception_governance,
        test_security_workflow_fail_closed_flags,
        test_sbom_normalization,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")
    print(f"Security control tests PASS: {len(tests)} test(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
