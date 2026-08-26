#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${1:?usage: build_supply_chain_artifacts.sh <output-dir>}"
TRIVY_BIN="${TRIVY_BIN:-trivy}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

rm -rf "${OUT_DIR}"
mkdir -p "${OUT_DIR}"

# git archive has deterministic ordering and file mtimes for a fixed commit;
# gzip -n removes filename/timestamp headers so identical commits produce the
# same source archive bytes.
git -C "${ROOT_DIR}" archive --format=tar --prefix=vsn-marketing/ HEAD \
  | gzip -n -9 > "${OUT_DIR}/vsn-marketing-source.tar.gz"

RAW_SBOM="${OUT_DIR}/vsn-marketing-sbom.raw.cdx.json"
NORMALIZED_SBOM="${OUT_DIR}/vsn-marketing-sbom.cdx.json"

"${TRIVY_BIN}" fs \
  --quiet \
  --format cyclonedx \
  --output "${RAW_SBOM}" \
  "${ROOT_DIR}"

python "${ROOT_DIR}/tools/normalize_sbom.py" \
  "${RAW_SBOM}" \
  "${NORMALIZED_SBOM}" \
  --root-name vsn-marketing
rm -f "${RAW_SBOM}"

(
  cd "${OUT_DIR}"
  sha256sum \
    vsn-marketing-source.tar.gz \
    vsn-marketing-sbom.cdx.json \
    > SHA256SUMS
)
