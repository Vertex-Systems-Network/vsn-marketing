# TASK-0082 Research Pack — AI Connector Factory trust boundaries

- researched_at: 2026-10-08
- task: TASK-0082
- phase: PHASE-14
- scope: API-description ingestion, generated connector candidates, isolated validation, supply-chain/security review and activation boundaries
- researcher: Supervisor

## Current official sources

| Source | Current finding / implementation impact |
| --- | --- |
| OpenAPI Specification 3.2.1 (https://spec.openapis.org/oas/v3.2.1.html), published 2026-09-10 | Current OAS explicitly warns that external resources may be on untrusted domains, reference cycles require bounded handling, and Markdown/HTML fields require sanitization. Description parsing must therefore be data-only, bounded and provenance-aware. |
| OpenAPI Specification versions/schema registry (https://spec.openapis.org/oas/) | OAS 3.2.1 is current; schemas do not catch every specification violation. Schema validation is necessary but insufficient, so semantic policy validation remains mandatory. |
| OpenAPI Generator project (https://github.com/OpenAPITools/openapi-generator) | Current project guidance explicitly warns that untrusted specs, templates, options or environment inputs can lead to security issues such as code injection. Generated output is the consumer's responsibility and cannot be treated as trusted merely because a generator produced it. |
| OpenAPI Generator security advisories (https://github.com/OpenAPITools/openapi-generator/security) | Historical arbitrary-file/temp-file and generated-code vulnerabilities prove generator execution/output requires version pinning, sandboxing, static review and regression tests rather than blind trust. |
| OWASP API Security Top 10 2023 (https://api-security.owasp.org/editions/2023/en/0x11-t10/) | API7 SSRF, API9 inventory/version management and API10 unsafe API consumption map directly to remote-reference fetching, provider-version lifecycle and third-party description ingestion. |
| GitHub Actions secure use (https://docs.github.com/en/actions/reference/security/secure-use) and pull_request_target hardening (https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target) | Untrusted code/artifacts must not execute in a privileged workflow with secrets/write tokens. Generated connector candidates must be inspected/tested in an unprivileged lane and promoted separately. |
| Docker run security controls (https://docs.docker.com/reference/cli/docker/container/run) | Default seccomp should remain enabled; no-new-privileges is available. Candidate execution needs ephemeral isolation, restricted privileges, resource bounds and no implicit host/provider access. |
| NIST SSDF 1.1 (https://csrc.nist.gov/pubs/sp/800/218/final) and SSDF publications (https://csrc.nist.gov/projects/ssdf/publications) | Final SSDF 1.1 requires protected development environments, provenance and verification practices. SSDF 1.2 remains draft as of this research and is informative, not a final normative dependency. |

## Threat model

Untrusted API descriptions and documentation are attacker-controlled data until independently approved. Threats include:

- external `$ref` or documentation URLs causing SSRF, internal-network probing, credential metadata access or mutable dependency substitution;
- reference cycles, decompression/JSON/YAML/parser bombs, excessive nesting or huge schemas causing CPU/memory exhaustion;
- Markdown/HTML/script payloads reaching operator UI or generated documentation without sanitization;
- malicious operation IDs, schema names, examples, vendor extensions, templates or generator options becoming code/shell/template injection;
- path traversal, absolute paths, symlink tricks or crafted names escaping the candidate output root;
- generated dependency or build-script changes introducing supply-chain execution before review;
- generated clients embedding unsafe defaults, insecure temp-file behavior, missing auth, over-broad scopes or logging secrets;
- privileged CI executing candidate code with repository secrets/write tokens;
- candidate code calling live provider endpoints, metadata services or the public network during validation;
- candidate self-approval or evidence tampering making generation equivalent to activation;
- stale provider/OpenAPI versions silently changing runtime behavior after initial approval.

## Required architecture gates

1. **Acquire as data, never execute.** Retrieval is allowlisted and bounded by scheme/host, byte size, redirect count, nesting/reference depth, timeout and content type. External references are either denied or separately fetched through the same policy and pinned by digest.
2. **Immutable provenance.** Preserve source URL/identifier, retrieval timestamp, declared API version, normalized content hash, redirect/fetch evidence and parser/tool versions. Mutable URLs never become identity.
3. **Validate twice.** Schema/spec validation is followed by semantic policy checks: auth/scopes, server URLs, external refs, unsupported extensions, operation uniqueness, limits and capability uncertainty.
4. **Plan before code.** TASK-0083 produces a typed non-executable connector plan. Unknown capabilities stay unknown; documentation cannot grant credentials, account ownership or provider approval.
5. **Candidate-only generation.** TASK-0084 pins generator/templates/options and writes only to an allowlisted candidate root. Generated code, tests, manifests and scripts are never auto-merged or auto-activated.
6. **Unprivileged sandbox.** Candidate build/tests run ephemeral with no repository/provider secrets, no write token, default seccomp, no-new-privileges, read-only inputs, bounded CPU/memory/time/processes and network denied unless a deterministic local fixture explicitly requires loopback.
7. **Independent review evidence.** Static analysis, dependency/license/supply-chain review, contract tests and adversarial fixtures operate on the generated tree. Candidate output cannot edit its own approval policy or evidence.
8. **Separated promotion.** A trusted control path consumes immutable evidence and explicit authorization. Canary/activation remains disabled by default and cannot imply production/provider authority.
9. **Lifecycle fail-closed.** Provider/version drift creates a new candidate/review cycle; incompatible or unknown drift disables promotion rather than silently updating active contracts.

## Phase-14 task mapping

- **TASK-0083:** bounded description/OpenAPI acquisition, provenance, semantic capability extraction and non-executable connector plan.
- **TASK-0084:** deterministic generated adapter/test candidates with pinned generator/template/toolchain and output-path isolation.
- **TASK-0085:** static/contract/security/sandbox evidence, independent approval boundary and disabled-by-default canary state.
- **TASK-0086:** compatibility/version/deprecation monitoring plus auditable disable/rollback and health evidence.
- **TASK-0087:** adversarial certification across malicious docs, insecure generation, sandbox escape, approval bypass and rollback.

## External-authority boundary

Research does not authorize downloading arbitrary private provider material, using credentials, running generated code against public providers, modifying branch protection, deploying a connector, enabling a canary, incurring billing, or performing any production side effect. These boundaries do not block repository-side contracts, fixtures, generators, isolated tests or certification work.

## Decision

`CONFIRMS_PLAN`: proceed with TASK-0082 through TASK-0087 exactly as preplanned. Treat documentation/specification/generator input as untrusted data; keep all generated code candidate-only until independent deterministic evidence and explicit activation authority exist.
