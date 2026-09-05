# TASK-0018 — PHASE-03 Security and Supply-Chain Certification Evidence

## Scope

This worker-owned audit reviews the security and supply-chain controls inherited from TASK-0014 through TASK-0017 for PHASE-03 certification. It records evidence without weakening required controls and does not itself mark TASK-0018 complete.

## Required controls

| Control | Repository mechanism | Certification expectation |
|---|---|---|
| Immutable CI dependencies | `tools/check_action_pins.py` and hash-pinned GitHub Actions | Every workflow dependency remains immutable or has an explicitly reviewed exception |
| Security exception governance | `tools/security_exceptions.py` and security-control tests | Exceptions are explicit, reviewed and time-bounded; absence of valid evidence fails closed |
| Secret detection | Security Supply Chain CI repository secret scan | Repository credentials/secrets are rejected at every configured severity |
| PHP/Laravel SAST | Security Supply Chain CI `php-sast` | Reviewed PHP-capable taint rules pass without inline suppression |
| Dependency audit | Security Supply Chain CI `dependency-audit` | Mandatory dependency findings are resolved or governed by an approved exception |
| CodeQL | Security Supply Chain CI JavaScript/TypeScript + Actions CodeQL | Supported CodeQL surfaces pass |
| Container vulnerability scan | Security Supply Chain CI `container-scan` | Fixed critical vulnerabilities fail the gate; embedded container secrets fail at every severity |
| Reproducible SBOM/source evidence | `tools/build_supply_chain_artifacts.sh`, `sbom-reproducibility` | Repeated source archive and normalized CycloneDX SBOM generation is byte-reproducible |
| Protected-main governance | Hosted main ruleset + AI Continuity default-branch ledger | Required contexts, up-to-date enforcement, non-fast-forward/deletion protection and no unauthorized bypass remain effective |
| Release integrity | Release Integrity workflow | Production release evidence retains signed provenance/SBOM attestations where required by repository policy |

## Accepted baseline evidence

- TASK-0014 established the security/supply-chain control plane and strict protected-main requirements.
- TASK-0015 and TASK-0016 completed on protected main with their applicable security and release-integrity gates green.
- TASK-0017 closeout baseline `4caa654426397473d21d382a8e4d3bcf43057546` passed AI Continuity run `33808835709`, Application Foundation run `33808835634`, Security Supply Chain run `33808835644`, Release Integrity run `33808835615`, and OpenSSF Scorecard run `33808835611`.
- The TASK-0018 activation PR discovered a stale task-specific negative test rather than a product/security defect; the guard was made task-agnostic so future task transitions remain covered.

## Fail-closed certification rules

1. A cancelled, skipped, queued or missing mandatory gate is not evidence of success for the final TASK-0018 acceptance head.
2. Historical green runs establish baseline continuity only; the Supervisor must still require green exact-head TASK-0018 gates.
3. No security exception may be inferred from a passing unrelated job or from provider sandbox behavior.
4. Raw provider credentials remain forbidden in canonical application data and audit evidence; only approved secret references are allowed.
5. Provider webhook authenticity remains connector-owned. No universal HMAC assumption may be introduced.
6. Unsupported or unknown provider capabilities must remain fail-closed and must not be promoted to executable readiness.

## Final status

Baseline security evidence is mapped. Final TASK-0018 security acceptance remains pending until the final Supervisor acceptance head passes every mandatory current security, continuity, application and release-quality gate. This audit deliberately does not pre-approve that future head.
