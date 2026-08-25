# TASK-0014 Research Pack

- researched_at: 2026-08-25T22:29:00+05:00
- task: TASK-0014
- phase: PHASE-03
- scope: Repository governance, CI/SAST, dependency/secret/container scanning, GitHub Actions integrity, SBOM, provenance, and OpenSSF posture before production-capable provider work.
- researcher: OpenAI coding agent
- repository_baseline: `main@595aa762f70a6a8a3d4adc5e6144efe371c60401`

## Sources

| Source | Type | Version/date | Accessed | Why authoritative |
|---|---|---|---|---|
| https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets | GitHub Docs | current | 2026-08-25 | Defines repository ruleset and required-status behavior. |
| https://docs.github.com/en/actions/reference/security/secure-use | GitHub Docs | current | 2026-08-25 | GitHub's secure-use guidance for Actions, including full-SHA pinning. |
| https://docs.github.com/en/code-security/reference/code-scanning/workflow-configuration-options | GitHub Docs | current | 2026-08-25 | Canonical CodeQL workflow language identifiers, including `actions`. |
| https://docs.github.com/en/get-started/learning-about-github/github-language-support | GitHub Docs | current | 2026-08-25 | Confirms PHP code scanning is third-party and not CodeQL. |
| https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations | GitHub Docs | current | 2026-08-25 | Defines artifact/SBOM attestation permissions and verification path. |
| https://github.com/actions/attest/blob/1e69f48acb82d1966a394da916b4c1698aa569d6/README.md | actions/attest | v4 pinned source | 2026-08-25 | Confirms the pinned action's attestation modes and required permissions. |
| https://slsa.dev/spec/v1.2/build-track-basics | SLSA specification | v1.2 current | 2026-08-25 | Current approved build-track baseline and level meanings. |
| https://semgrep.dev/products/integrations/ | Semgrep | current | 2026-08-25 | Confirms PHP/Laravel SAST support. |
| https://semgrep.dev/docs/writing-rules/glossary | Semgrep Docs | current | 2026-08-25 | Defines Semgrep taint analysis and its source/sink model. |
| https://semgrep.dev/blog/2021/python-static-analysis-comparison-bandit-semgrep/ | Semgrep | current behavior reference | 2026-08-25 | Documents inline `nosemgrep` result suppression behavior. |
| https://www.trivy.dev/docs/latest/guide/scanner/secret/ | Trivy Docs | current | 2026-08-25 | Documents filesystem/image/git secret scanning and auto-loaded secret configuration. |
| https://trivy.dev/docs/dev/guide/references/configuration/config-file/ | Trivy Docs | current | 2026-08-25 | Documents the auto-loaded `trivy.yaml` configuration surface. |
| https://trivy.dev/docs/latest/guide/target/container_image/ | Trivy Docs | current | 2026-08-25 | Documents container image/SBOM vulnerability scanning. |
| https://github.com/ossf/scorecard-action | OpenSSF Scorecard | v2.4.3 latest documented release | 2026-08-25 | Official Scorecard GitHub Action and workflow restrictions. |

## Repository evidence at activation

Observed through GitHub API at the exact activation baseline:

- Default branch: `main` at `595aa762f70a6a8a3d4adc5e6144efe371c60401`.
- No open pull requests existed when TASK-0014 started.
- Active repository ruleset `main` (ruleset `21212844`) targets the default branch with no bypass actors.
- The ruleset blocks deletion/non-fast-forward changes and requires pull requests/thread resolution, but `required_approving_review_count` is `0`, `require_last_push_approval` is false, strict status enforcement is false, and only status context `governance` is required.
- Current workflows use mutable action tags such as `actions/checkout@v7`, `actions/setup-python@v7`, `actions/setup-node@v6`, `actions/github-script@v7`, and `shivammathur/setup-php@v2`.
- Dependabot already covers GitHub Actions, Composer, and npm on a weekly cadence.
- No provider SDK or TASK-0015 implementation is required or permitted by this task.

## Current external reality

### Repository rules and CI status enforcement

GitHub rulesets can require pull requests, status checks, code scanning results, force-push protection, and code-quality results. Required status checks only provide merge safety when the intended job names are actually configured, and strict mode additionally requires the topic branch to be up to date with the base branch.

For TASK-0014 the repository should not rely on the legacy branch-protection payload alone: the active repository ruleset is the authoritative visible control. The existing ruleset is materially weaker than the target because it accepts zero approvals and requires only the continuity `governance` job.

### SAST coverage

GitHub currently lists CodeQL support for JavaScript/TypeScript and GitHub Actions workflows, but not PHP. PHP code scanning is explicitly listed as third-party. Therefore a truthful stack-wide SAST claim needs separate engines:

- CodeQL: `javascript-typescript` and `actions` surfaces.
- PHP: a PHP-capable scanner with taint/data-flow rules. Semgrep supports PHP/Laravel and taint-mode rules.

The PHP path must state its limitations: Semgrep Community Edition taint analysis is primarily intra-file/intra-procedural; it must not be described as equivalent to proprietary cross-file analysis.

Semgrep supports inline `nosemgrep` comments that suppress findings. A blocking repository-owned SAST gate therefore cannot rely only on forbidding `.semgrepignore`; it must also disable inline suppression (the CLI supports `--disable-nosem`) or govern those suppressions explicitly.

### Dependency and secret scanning

Composer and npm both have deterministic lockfiles in this repository. CI can fail on known dependency vulnerabilities using `composer audit` and `npm audit`, while Dependabot remains the update channel. Trivy can scan filesystems, git repositories, and container images for secrets and vulnerabilities, and supports explicit severity selection.

Trivy's current secret-scanning documentation states that `trivy-secret.yaml` in the working directory is loaded by default and can add allow-rules, disable built-in rules, or alter skip patterns. Trivy also supports a repository-local `trivy.yaml` configuration file. Those automatic configuration paths are therefore policy inputs, not harmless files; a fail-closed gate must either pin/validate them or reject repository-local copies.

For credential policy, severity is not a remediation distinction: a real credential remains a credential even if a detector labels the finding LOW or MEDIUM. The repository tree secret gate therefore uses all Trivy severities and routes false positives through the canonical reviewed exception mechanism.

The filesystem/tree scan proves the reviewed commit's tree, not the complete historical absence of secrets from every prior Git commit. No full-history secret-cleanliness claim should be made without separate historical-scanning evidence.

Exception handling must be repository-owned and reviewable. Scanner suppression must not be a hidden CI flag; every accepted suppression needs an owner, reason, evidence, independent approval, and expiry/review date.

### GitHub Actions integrity and permissions

GitHub states that full-length commit SHA pinning is the only immutable way to reference an action release. Privileged workflows therefore must pin every external `uses:` reference to a 40-character commit SHA. Workflow/job permissions should remain explicit and least-privilege; elevated permissions such as `security-events: write`, `attestations: write`, and `id-token: write` belong only in jobs that require them.

A line-oriented action-pin guard must fail closed on alternative YAML spellings it cannot safely parse. Flow mappings or other noncanonical `uses` syntax must not become a route around immutable-reference enforcement.

### SBOM and provenance

SLSA v1.2 is the current approved build-track baseline. Build L1 requires provenance to exist; L2 adds signed provenance from a hosted build platform; L3 requires a hardened build platform. This task will not claim a SLSA level merely because a workflow exists.

GitHub artifact attestations can bind a build artifact or SBOM to repository/workflow/commit identity. The practical VSN path is to generate a deterministic release bundle and CycloneDX/SPDX-compatible SBOM, attest the release artifact on trusted branch/release events, and document verification with `gh attestation verify`. Test-only artifacts should not be marketed as release provenance.

The pinned `actions/attest` v4 documentation requires `id-token: write`, `attestations: write`, and `artifact-metadata: write` for the attestation job. Those permissions should remain isolated from ordinary PR validation.

### OpenSSF posture

OpenSSF Scorecard is appropriate as a recurring posture signal, not as proof that the repository is secure. Its result should be generated on a recurring default-branch workflow with bounded permissions and retained as auditable evidence.

## Security/privacy findings

- TASK-0014 adds no new customer data, provider credentials, PII, or external provider trust boundary.
- CI itself is a privileged execution surface: mutable Actions, broad `GITHUB_TOKEN` permissions, unreviewed workflow changes, and remote/scanner configuration are supply-chain risks.
- Security findings can contain code paths or suspected secrets. SARIF/artifacts must not intentionally publish raw credentials, and any discovered credential must be rotated rather than merely suppressed.
- Repository settings that cannot be modified by the available automation interface must remain an explicit blocker or approved, time-bounded exception; documentation is not equivalent to enforcement.

## API/platform constraints

- GitHub required status check names are job/check contexts, not workflow display names.
- A required check must have been observed by GitHub before it can be safely configured as a branch rule.
- Artifact attestations require `contents: read`, `id-token: write`, `attestations: write`, and for the pinned v4 action `artifact-metadata: write`; container publication additionally needs package permissions.
- GitHub Actions SHA policy, when enabled at repository/organization level, applies to GitHub-authored actions as well as third-party actions.
- OpenSSF Scorecard action has workflow restrictions and should run in a dedicated job/workflow rather than being mixed into arbitrary privileged steps.

## Performance/reliability findings

- Security scans can add substantial CI latency. Separate independent jobs permit parallel execution and clearer failure ownership.
- Vulnerability/secret/container gates should fail deterministically on documented severities, while non-blocking posture metrics (for example a Scorecard score) should not be converted into an arbitrary release claim without an accepted threshold.
- External vulnerability databases are time-varying. Exact-head acceptance records the tool/config version and run result; a later newly-disclosed CVE is new evidence, not proof the earlier scan was fabricated.

## Conflicts with current assumptions

No roadmap or acceptance criterion needs weakening. Research confirms ADR-0002 and TASK-0014's capability split.

One repository-setting gap is concrete: the active `main` ruleset currently requires zero approvals, does not require last-push approval, does not require branches to be up to date, and requires only `governance`. This is now explicitly recorded in canonical state/BLOCKERS and must be hardened in GitHub settings after the new security/application checks have produced valid status contexts, or be covered by a specifically approved time-bounded exception under AC-1.

A follow-up scanner audit found two suppression surfaces that the initial implementation did not fully govern: Semgrep inline `nosemgrep` and Trivy auto-loaded `trivy.yaml`/`trivy-secret.yaml`. The implementation was hardened rather than redefining AC-2/AC-3.

The local execution environment used for this session could not resolve `github.com` for a `git clone`, so mandated repository validators could not be rerun locally. Hosted CI therefore remains required acceptance evidence for the final exact head.

## Required roadmap extensions

None. Every material finding maps to existing TASK-0014 acceptance criteria:

- ruleset/status/review hardening -> AC-1
- CodeQL + PHP-capable SAST -> AC-2
- dependency/secret/container scanning -> AC-3
- immutable Action pinning + least privilege -> AC-4
- SBOM/provenance/OpenSSF posture -> AC-5
- regression/no-future-task scope -> AC-6
- exact-head continuity/application/security gates -> AC-7

## Rejected options

- **CodeQL-only security scanning:** rejected because it would falsely imply PHP coverage.
- **Mutable action tags (`@vN`) in privileged workflows:** rejected because GitHub documents full-SHA references as the immutable form.
- **A single all-powerful security job:** rejected because it broadens token permissions and obscures which gate failed.
- **Hidden scanner-local suppressions:** rejected because `nosemgrep`, Trivy allow-rules/config, ignore files, or package-manager audit ignores would bypass canonical exception ownership.
- **Scanner results as a SLSA claim:** rejected; SLSA levels describe provenance/build-system properties, not scanner quantity.
- **Skipping container scanning because no production image is published yet:** rejected; the repository already builds `docker/app/Dockerfile`, so image vulnerability evidence is applicable.
- **Starting TASK-0015 provider work while security hardening is in progress:** rejected by canonical sequencing.

## Decision impact

- `CONFIRMS_PLAN`: CodeQL/PHP split, dependency/secret/container gates, action SHA pinning, SBOM/provenance, OpenSSF posture.
- `CONFIRMS_PLAN`: Dependabot already covers the three present update ecosystems.
- `HARDENS_PLAN`: scanner suppressions/configuration must fail closed and credential findings are blocking at every secret severity.
- `BLOCKER_IF_UNCHANGED`: default-branch ruleset review/status strictness does not yet satisfy AC-1.
- `NO_PRODUCT_IMPACT`: no provider/domain schema/product feature change is required.

## Freshness risks

GitHub Actions majors/tags, CodeQL language support, scanner behavior/databases, OpenSSF Scorecard releases, GitHub ruleset capabilities, and SLSA guidance can change. Revalidate these sources before materially changing the security policy or before PHASE-03 certification.
