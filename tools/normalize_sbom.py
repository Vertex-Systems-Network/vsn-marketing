#!/usr/bin/env python3
"""Normalize CycloneDX JSON into a byte-stable repository SBOM artifact."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

VOLATILE_METADATA_KEYS = {"timestamp"}


def normalize(value: Any, parent_key: str | None = None) -> Any:
    if isinstance(value, dict):
        result = {
            key: normalize(item, key)
            for key, item in value.items()
            if not (parent_key == "metadata" and key in VOLATILE_METADATA_KEYS)
            and not (parent_key is None and key == "serialNumber")
        }
        return result

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


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()

    source = json.loads(args.input.read_text(encoding="utf-8"))
    normalized = normalize(source)
    args.output.write_text(
        json.dumps(normalized, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
