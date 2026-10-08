# PHASE-14 certification evidence matrix — TASK-0087

Certification scope: repository-side, offline connector planning, candidate generation, review, sandbox validation, compatibility and lifecycle controls.

Status: **evidence matrix compiled; exact-head and resulting-main certification gates are being verified.**

This certification does not grant or claim provider credentials, external provider approval, live API polling, production connector activation, successful live rollback, deployment/release authority, production-scale SLOs, or source completeness.

## Requirements, sources, policy and tests

| Task | Requirement and evidence | Source / policy basis | Tests and unsupported-path coverage | State |
|---|---|---|---|---|
| TASK-0082 | Research threat model and fail-closed gates for API descriptions, generated code, sandboxing, supply chain and promotion | Dated official-source pack in [TASK-0082-RESEARCH.md](TASK-0082-RESEARCH.md); OpenAPI 3.2.1, OpenAPI Generator security guidance, OWASP API Security Top 10 2023, GitHub Actions secure-use guidance, Docker run security controls, NIST SSDF 1.1 | Threat model covers untrusted docs/templates, external references, parser/resource exhaustion, injection, path escape, SSRF, secret exfiltration, vulnerable output and privileged execution | Accepted, PR #500 |
| TASK-0083 | Bounded ingestion, immutable provenance, typed capabilities and non-executable connector plans | TASK-0082 research; unsupported and unknown semantics remain explicit; plans cannot grant execution or authority | `Task0083ConnectorFactoryTest`: external `$ref`, active script content, unsupported YAML media, empty workspace, oversized source, excessive nesting, documentation-only input, absent capabilities, workspace mismatch and attempted authority promotion | Accepted, PR #502 |
| TASK-0084 | Deterministic generated adapter/test candidates with pinned provenance and candidate-only status | TASK-0082 research; generated output stays in an allowlisted candidate workspace, with no implicit dependency, workflow or runtime authority | `Task0084ConnectorCandidateGeneratorTest`: deterministic output, malicious operation names, credential-bearing URLs, shell payloads, path traversal, invalid toolchain, hidden workflow/runtime commands, dependency injection and non-executable candidate output | Accepted, PR #504 |
| TASK-0085 | Static, contract, sandbox, security and independent approval gates; canary disabled by default | TASK-0082 research; generated code cannot self-approve; canary policy is bounded and reversible, with external activation authority kept separate | `Task0085ConnectorCandidateReviewTest`, `Task0085ConnectorCandidateSandboxExecutionTest`, `ConnectorContractsTest`, `ReferenceConnectorCertificationTest`: evidence tampering, insecure generated output, approval bypass, untrusted reviewer, network/secrets/write escape, read-only root, resource bounds and disabled-by-default canary | Accepted, PR #505 |
| TASK-0086 | Versioned compatibility scoring, dated deprecation provenance, deterministic disable/rollback decisions and tenant-scoped lifecycle evidence | [TASK-0086-RESEARCH.md](TASK-0086-RESEARCH.md); OpenAPI version/deprecation semantics, RFC 9745 Deprecation, RFC 8594 Sunset and OWASP API inventory/version management | `Task0086ConnectorLifecycleTest`, `Task0086CompatibilityEvidenceBindingTest`, `Task0086ConnectorLifecyclePersistenceTest`: unknown/breaking capability changes fail closed, no automatic upgrade, exact assessment/evidence binding, explicit rollback candidate, tenant isolation, idempotent failure reconciliation, evidence-hash integrity and refusal to discard persisted evidence | Accepted after PR #517 |
| TASK-0087 | Cross-task certification and exact-head/resulting-main evidence | This matrix plus the project charter, AI rules, security rules, quality gates and task/source packs above | Acceptance requires all referenced adversarial tests, unsupported paths and external-authority boundaries to remain explicit; exact-head and resulting-main gates are tracked below | In progress |

## Unsupported and external-authority paths

- Unknown capability, version or contract semantics score as unknown/incompatible and block promotion.
- Plain documentation is data only; it cannot create capabilities or connector authority.
- External references, active content, unsupported media, missing tenant scope, oversized input and excess parser depth fail closed.
- Generated operation names, source credentials, hidden commands, workflow files and dependency changes cannot become executable output.
- Sandbox validation has no network, secret, write-token or provider access; candidate files are mounted read-only inside an ephemeral, unprivileged container.
- Missing, mismatched or untrusted approval denies promotion. Canary remains disabled unless a separate bounded policy and trusted approval are explicitly supplied.
- Disable and rollback records are decisions and evidence; they do not claim a live provider action completed. Rollback requires an explicit immutable candidate and matching evidence.
- This certification does not authorize private provider material retrieval, credential use, production API calls, provider approval, deployment, billing, branch-protection changes or production side effects.

## Test and gate evidence

### Implementation and exact-head evidence

- TASK-0082 research and acceptance: PR #500.
- TASK-0083 ingestion and connector planning: PR #502.
- TASK-0084 candidate generation: PR #504.
- TASK-0085 static/contract/sandbox/approval gates: PR #505.
- TASK-0086 lifecycle and compatibility evidence: PR #517 exact head `b53e8cef1de0becda8efeaa3a1be948710c876da`, merged to main as `6d6d6a92e6beab287d490143db91a3fe0e72b98e`.
- PR #517 exact-head Application Foundation, PostgreSQL infrastructure integration, PHP 8.3 floor, E2E, Security Supply Chain, AI Continuity and governance gates passed.
- Resulting main `6d6d6a92e6beab287d490143db91a3fe0e72b98e` passed Application Foundation, PostgreSQL 18 integration/browser parity, PHP 8.3 floor, E2E, Security Supply Chain, AI Continuity, governance and release-integrity checks.
- PR #518's full exact-head rerun passed Application Foundation, PostgreSQL integration/browser parity, PHP 8.3 floor, E2E, Security Supply Chain, AI Continuity and governance. The PHP-floor first attempt hit a 10-second Docker readiness timeout; the targeted retry passed with 929 tests and 6,089 assertions.

### TASK-0087 certification carrier

The current certification carrier's exact-head gate results and protected-main results will be recorded here before terminal task closure. Certification remains pending until full Application Foundation (including PHP 8.3 and PostgreSQL integration/browser parity), Security Supply Chain and AI Continuity exact-head gates pass and the resulting-main/release-relevant evidence is reconciled.

The resulting-main release-integrity and scorecard evidence for the merged TASK-0086 product head is recorded above. Documentation/state-only certification commits do not independently claim a new product release or production activation.

## Acceptance status

- **AC-1 — matrix:** requirements, official-source packs, deterministic policy, tests, unsupported cases and authority boundaries are mapped above.
- **AC-2 — adversarial tests:** represented by the listed connector ingestion, code-generation, contract, sandbox, approval, compatibility, rollback and persistence suites; final certification carrier gates remain required.
- **AC-3 — exact head and resulting main:** pending the certification carrier and its resulting-main gate observations.
