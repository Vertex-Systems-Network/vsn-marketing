#!/usr/bin/env bash
set -euo pipefail

# Trivy is installed from an immutable release artifact whose digest was
# independently recorded during TASK-0014 research. Do not replace this with a
# mutable package-manager install in privileged CI.
TRIVY_VERSION="0.73.0"
TRIVY_ARCHIVE="trivy_${TRIVY_VERSION}_Linux-64bit.tar.gz"
TRIVY_SHA256="2edd39da482bb4e9831962487b68f68e3928ec3137794757f54d00383d79547b"
TRIVY_URL="https://github.com/aquasecurity/trivy/releases/download/v${TRIVY_VERSION}/${TRIVY_ARCHIVE}"

DEST_DIR="${1:-${RUNNER_TEMP:-/tmp}/vsn-trivy}"
mkdir -p "${DEST_DIR}"
ARCHIVE_PATH="${DEST_DIR}/${TRIVY_ARCHIVE}"

curl \
  --proto '=https' \
  --tlsv1.2 \
  --fail \
  --silent \
  --show-error \
  --location \
  --retry 3 \
  --retry-connrefused \
  --output "${ARCHIVE_PATH}" \
  "${TRIVY_URL}"

printf '%s  %s\n' "${TRIVY_SHA256}" "${ARCHIVE_PATH}" | sha256sum --check --strict

tar -xzf "${ARCHIVE_PATH}" -C "${DEST_DIR}" trivy
chmod 0755 "${DEST_DIR}/trivy"
"${DEST_DIR}/trivy" --version
printf '%s\n' "${DEST_DIR}/trivy"
