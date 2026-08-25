#!/usr/bin/env python3
"""Normalize CycloneDX JSON into a byte-stable repository SBOM artifact.

Trivy intentionally emits a fresh CycloneDX serial number/timestamp and may use
opaque UUID BOM references for filesystem components. Those values are valid for
an individual BOM but make byte-for-byte reproduction impossible. This tool
removes document-instance metadata and replaces component BOM references with
content-derived references while preserving the dependency graph.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any

VOLATILE_METADATA_KEYS = {"timestamp"}


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def component_reference(component: dict[str, Any], *, scope: str) -> str:
    identity = {key: value for key, value in component.items() if key != "bom-ref"}
    digest = hashlib.sha256(canonical_json(identity).encode("utf-8")).hexdigest()
    return f"urn:vsn:sbom:{scope}:{digest}"


def build_reference_map(source: dict[str, Any]) -> dict[str, str]:
    mapping: dict[str, str] = {}
    reverse: dict[str, str] = {}

    candidates: list[tuple[str, dict[str, Any]]] = []
    metadata = source.get("metadata")
    if isinstance(metadata, dict):
        root = metadata.get("component")
        if isinstance(root, dict):
            candidates.append(("root", root))

    components = source.get("components")
    if isinstance(components, list):
        for component in components:
            if isinstance(component, dict):
                candidates.append(("component", component))

    for scope, component in candidates:
        old_ref = component.get("bom-ref")
        if not isinstance(old_ref, str) or not old_ref:
            continue
        new_ref = component_reference(component, scope=scope)
        prior_old_ref = reverse.get(new_ref)
        if prior_old_ref is not None and prior_old_ref != old_ref:
            raise ValueError(
                "two CycloneDX components have identical stable identities but distinct BOM refs; "
                "refusing to collapse the dependency graph"
            )
        mapping[old_ref] = new_ref
        reverse[new_ref] = old_ref

    return mapping


def rewrite_references(value: Any, mapping: dict[str, str]) -> Any:
    if isinstance(value, dict):
        return {key: rewrite_references(item, mapping) for key, item in value.items()}
    if isinstance(value, list):
        return [rewrite_references(item, mapping) for item in value]
    if isinstance(value, str):
        return mapping.get(value, value)
    return value


def normalize(value: Any, parent_key: str | None = None) -> Any:
    if isinstance(value, dict):
        return {
            key: normalize(item, key)
            for key, item in value.items()
            if not (parent_key == "metadata" and key in VOLATILE_METADATA_KEYS)
            and not (parent_key is None and key == "serialNumber")
        }

    if isinstance(value, list):
        items = [normalize(item, parent_key) for item in value]
        if parent_key == "components":
            return sorted(
                items,
                key=lambda item: (
                    item.get("purl", "") if isinstance(item, dict) else "",
                    item.get("bom-ref", "") if isinstance(item, dict) else "",
                    item.get("name", "") if isinstance(item, dict) else "",
                    item.get("version", "") if isinstance(item, dict) else "",
                ),
            )
        if parent_key == "dependencies":
            normalized_dependencies = []
            for item in items:
                if isinstance(item, dict) and isinstance(item.get("dependsOn"), list):
                    item = dict(item)
                    item["dependsOn"] = sorted(item["dependsOn"])
                normalized_dependencies.append(item)
            return sorted(
                normalized_dependencies,
                key=lambda item: item.get("ref", "") if isinstance(item, dict) else "",
            )
        return items

    return value


def normalize_document(source: dict[str, Any], *, root_name: str | None = None) -> dict[str, Any]:
    # Filesystem scanners may record an absolute checkout path as the root name.
    # The release identity is repository-defined, so make it explicit before
    # deriving the root reference.
    if root_name:
        metadata = source.get("metadata")
        if isinstance(metadata, dict) and isinstance(metadata.get("component"), dict):
            source = json.loads(json.dumps(source))
            source["metadata"]["component"]["name"] = root_name

    reference_map = build_reference_map(source)
    rewritten = rewrite_references(source, reference_map)
    return normalize(rewritten)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--root-name", default=None)
    args = parser.parse_args()

    source = json.loads(args.input.read_text(encoding="utf-8"))
    if not isinstance(source, dict):
        raise SystemExit("CycloneDX input must be a JSON object")
    normalized = normalize_document(source, root_name=args.root_name)
    args.output.write_text(canonical_json(normalized) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
