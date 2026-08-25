# Repository and Software Supply-Chain Security

This document is the operational contract for `TASK-0014`. It describes controls that are actually implemented, the failure thresholds used by CI, the evidence that must exist before acceptance, and the repository-setting gap that must not be mistaken for completion.

## Scope boundary

These controls harden repository governance and the software supply chain before provider integrations. They do **not** add provider SDKs, connectors, delivery routing, production credentials, or any `TASK-0015+` capability.

## Required merge gates

The target default-branch ruleset for `main` must require all of these status contexts:

- `governance`
- `foundation`
- `php-floor`
- `integration`
- `e2e`
- `security-gates`

The ruleset must also require:

- the topic branch to be up to date with `main` before merge (`strict_required_status_checks_policy: true`);
- at least one approving review;
- approval from someone other than the person who most recently pushed (`require_last_push_approval: true`);
- all review threads resolved;
- no force pushes or branch deletion;
- no bypass actor for normal repository contributors.

The same independent-review rule applies to privileged workflow and security-policy changes. The immutable-action guard provides an additional machine control for `.github/workflows/**` changes, but it does not replace human review.

### Current observed ruleset gap

At `main@595aa762f70a6a8a3d4adc5e6144efe371c60401`, repository ruleset `21212844` was active and had no bypass actors, but it required **zero** approving reviews, did not require last-push approval, used non-strict required status checks, and required only the `governance` status context.

That state does **not** satisfy `TASK-0014 AC-1`. The task remains blocked until the active `main` ruleset is changed to the target contract above and the effective rule is re-read from GitHub, or a specifically approved, time-bounded exception is recorded. Documentation alone is not enforcement.

## GitHub Actions integrity

`python tools/check_action_pins.py` scans every workflow under `.github/workflows/` and fails when an external `uses:` reference is not a full 40-character commit SHA. Local repository actions (`./...`) are allowed. A future Docker action reference must use a `sha256:` digest.

Reviewed action pins introduced by TASK-0014 include:

| Dependency | Immutable reference | Human-readable release |
|---|---|---|
| `actions/checkout` | `3d3c42e5aac5ba805825da76410c181273ba90b1` | v7 |
| `actions/setup-python` | `5fda3b95a4ea91299a34e894583c3862153e4b97` | v7 |
| `actions/setup-node` | `249970729cb0ef3589644e2896645e5dc5ba9c38` | v6 |
| `actions/github-script` | `f28e40c7f34bde8b3046d885e986cb6290c5673b` | v7 |
| `shivammathur/setup-php` | `f3e473d116dcccaddc5834248c87452386958240` | v2 |
| `github/codeql-action` | `db488ddef3bf6cb639b32c2e9a7c0a7ea8271d28` | v4 |
| `actions/upload-artifact` | `ea165f8d65b6e75b540449e92b4886f43607fa02` | v4 |
| `actions/attest` | `1e69f48acb82d1966a394da916b4c1698aa569d6` | v4 |
| `ossf/scorecard-action` | `4eaacf0543bb3f2c246792bd56e8cdeffafb205a` | v2.4.3 |

Dependabot remains enabled for the `github-actions`, `composer`, and `npm` ecosystems so updates arrive as reviewable pull requests instead of mutable runtime references.

## SAST coverage and failure policy

The repository intentionally does not describe CodeQL as a PHP scanner.

### CodeQL

`Security Supply Chain CI` runs CodeQL only for currently supported repository surfaces:

- `javascript-typescript`
- `actions`

CodeQL results are uploaded to GitHub code scanning. The `codeql` matrix must complete successfully for the aggregate `security-gates` job to pass.

### PHP/Laravel

PHP uses Semgrep `1.173.0`, pinned at installation time, with repository-owned taint rules in `.semgrep.yml`. The current ERROR rules detect request-controlled data reaching:

- OS command execution;
- raw SQL execution/building sinks;
- PHP `unserialize()`;
- PHP `eval()`.

CI invokes Semgrep with `--error --severity ERROR`. Any matching ERROR finding makes `php-sast` fail. This is a Community Edition path and must not be represented as proprietary cross-file/interprocedural analysis.

## Dependency vulnerability policy

`dependency-audit` is lockfile based:

- `composer audit --locked` fails on known Composer security advisories; abandoned-package information is reported but does not redefine an advisory into a vulnerability.
- `npm audit --audit-level=high` fails on HIGH or CRITICAL npm advisories, including build/development dependencies because those dependencies execute inside CI and are part of the software supply chain.

No Composer audit ignore list is allowed outside the canonical exception process.

## Secret policy

Trivy `0.73.0` is downloaded from its immutable GitHub release URL and verified against the recorded Linux x86-64 SHA-256 digest before execution.

`secret-scan` scans the reviewed repository tree and fails on HIGH or CRITICAL secret findings. A discovered real credential must be revoked/rotated; adding a suppression is not remediation.

## Container vulnerability policy

The application Dockerfile is built by CI and scanned with the same checksum-verified Trivy binary. `container-scan` fails on fixed CRITICAL vulnerabilities and CRITICAL embedded-secret findings. `--ignore-unfixed` prevents CI from claiming that maintainers can remediate a base-image vulnerability for which no upstream fix exists; such findings remain visible in scanner output and must be reassessed as upstream fixes become available.

The current developer Dockerfile uses mutable upstream image tags (`php:8.5-cli-bookworm` and `composer:2`). TASK-0014 therefore does **not** claim byte-reproducible container images or container provenance. Its provenance subject is the source release artifact described below.

## Security exception governance

The canonical registry is `security/exceptions.json`. It is empty by default.

`python tools/security_exceptions.py` requires every future exception to have:

- a unique ID and scanner/finding identity;
- a named owner;
- a concrete reason and evidence link;
- an `approved_by` identity different from the owner;
- timezone-aware creation/expiry timestamps;
- a lifetime no longer than 30 days;
- an expiry later than creation and later than the validation time.

Non-comment entries in `.trivyignore`, `.trivyignore.yaml`, or `.semgrepignore`, and hidden `composer.json` audit ignores, are rejected. If a scanner requires a technical suppression mechanism in the future, the code that maps the approved registry entry to that narrow suppression must be reviewed in the same change.

## Reproducible SBOM path

`tools/build_supply_chain_artifacts.sh` builds two repository artifacts for a fixed Git commit:

- `vsn-marketing-source.tar.gz` from `git archive`, compressed with `gzip -n` so gzip timestamp/name headers do not vary;
- `vsn-marketing-sbom.cdx.json`, a CycloneDX SBOM generated by Trivy and normalized by `tools/normalize_sbom.py` to remove volatile timestamp/serial metadata and sort dependency structures deterministically.

The script writes `SHA256SUMS`. `sbom-reproducibility` independently generates both artifacts twice and uses byte comparison before `security-gates` can pass.

## Build provenance and attestation

`Release Integrity` runs only on trusted `main` pushes or explicit workflow dispatch. It regenerates the checksummed source artifact and normalized SBOM, retains them as a workflow artifact, and uses `actions/attest` to create:

1. signed build provenance for `vsn-marketing-source.tar.gz`;
2. a signed CycloneDX SBOM attestation binding the SBOM to the same source artifact.

The attestation job alone receives `id-token: write`, `attestations: write`, and `artifact-metadata: write`; other security jobs retain read-only repository permissions unless their documented GitHub API operation requires more.

Verification example after downloading an attested source artifact:

```bash
gh attestation verify vsn-marketing-source.tar.gz --repo Vertex-Systems-Network/vsn-marketing
sha256sum --check SHA256SUMS
```

No SLSA level is claimed merely because an attestation exists. The repository records the SLSA v1.2 build-track definitions as a baseline; any level claim requires separate evidence that every requirement for that level is satisfied.

## OpenSSF posture

`OpenSSF Scorecard` runs on `main`, weekly, and on manual dispatch. It writes JSON evidence as a retained artifact with `publish_results: false` and read-only repository permissions. A Scorecard number is a posture signal, not acceptance proof and not a statement that the repository is secure.

## Acceptance evidence checklist

TASK-0014 can transition to DONE only when all of the following are true on the exact acceptance head:

1. AI Continuity passes.
2. Application Foundation CI passes, including foundation, PHP compatibility floor, Integration suite, static analysis, formatting, frontend tests/build, and Playwright smoke.
3. Security Supply Chain CI passes and `security-gates` is green.
4. The `main` ruleset has been re-read and matches the target review/strict-status contract.
5. A trusted `main` Release Integrity run has exercised provenance + SBOM attestation successfully.
6. A trusted `main` OpenSSF Scorecard run has produced retained posture evidence.
7. No provider-integration or future-task files were introduced.

If any item is unavailable, TASK-0014 remains active and the unavailable control is recorded as a blocker or approved time-bounded exception; the acceptance criteria are not redefined.
