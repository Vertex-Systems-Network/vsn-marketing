# ADR-0002 — Research-First Preplanned Marketing OS Architecture

- Status: Proposed
- Date: 2026-08-25
- Governing task: TASK-0013
- Scope: Research-first development governance, full future roadmap preplanning, audit remediation, social/creative operating boundaries, and quality engineering gates

## Context

PHASE-02 is complete and the canonical execution state is intentionally in reconciliation because no successor task is registered. The repository already defines VSN Marketing as an AI-native, provider-agnostic marketing operating system rather than a Mailchimp clone, but the current future roadmap is only phase-level and the product model is stronger for email/customer automation than for social publishing, creative asset operations, community workflows, and continuously researched market/API constraints.

A repository audit after TASK-0012 also identified governance and quality gaps that must become planned work rather than informal observations: branch/ruleset enforcement, security and secret/dependency scanning, supply-chain provenance and hash-pinned CI dependencies, PostgreSQL-backed critical browser paths, performance/SLO/load gates, accessibility and visual regression, disaster-recovery evidence, stronger adversarial AI evaluation, and uniform phase/acceptance traceability.

The platform will integrate fast-changing external systems. Provider APIs, AI models, social networks, privacy rules, deliverability requirements, security guidance, and market expectations can materially change between initial planning and implementation. Static planning alone is therefore insufficient, while ad-hoc AI replanning would violate repository continuity.

## Proposed decision

### 1. Research-First Gate

Before implementation begins for any new subsystem, provider, channel, AI capability, security-sensitive integration, major dependency, user-facing market category, or significant infrastructure mechanism, the active task must produce a dated research evidence pack following `.ai/11-RESEARCH-FIRST-STANDARD.md`.

Research must prioritize current authoritative sources: official API/provider documentation, standards bodies, security guidance, platform policies, release notes, and relevant market/reference implementations. Research is evidence, not authority to silently rewrite the roadmap.

If research discovers missing prerequisites or materially better/safer requirements, the AI must extend the preplanned roadmap through explicit task/acceptance/ADR changes before implementation. Existing completed history, task identifiers, dependencies, and acceptance criteria may not be silently deleted, weakened, reordered, or reinterpreted.

### 2. Full preplanned implementation skeleton

Reserve the future implementation skeleton from `TASK-0013` through `TASK-0100` in `.ai/roadmap/PREPLANNED-IMPLEMENTATION-PLAN.md`.

The skeleton is the minimum known plan, not a claim that future external APIs are already understood. Each phase starts with research/reconciliation where appropriate and ends with certification. Machine task files are materialized and activated through the existing transactional state process; the reserved plan is extended when research produces justified prerequisites.

### 3. Channel-neutral marketing operating model

Provider abstractions must not become email-only. Canonical contracts must support capability discovery for delivery providers, marketing platforms, mailbox APIs, messaging channels, and social publishing/community APIs without provider-name conditionals in core modules.

The following candidate stable module boundaries are proposed for later acceptance and registry addition:

- `Assets`: canonical media/creative asset ownership, variants, metadata, provenance, rights, and transformations.
- `Publishing`: channel-neutral publication drafts, targets, schedules, attempts, status reconciliation, edits/deletes when supported, and publication analytics references.
- `Community`: normalized comments, mentions, conversations, inbox assignments, moderation/response proposals, and listening signals.

Social networks remain connectors/adapters. Core business logic must not import concrete social SDKs.

### 4. AI architecture extension

Keep the existing specialized-agent model and deterministic execution boundary. Add a future research/market-intelligence capability through the AI gateway rather than creating an unrestricted super-agent. Research-capable AI must preserve source provenance, access date, confidence, conflicting evidence, and implementation impact.

Product AI remains model-vendor neutral. OpenAI, Anthropic, Google, cloud-hosted models, or private/local models are replaceable routes selected by capability, privacy/region policy, measured quality, latency, cost, and fallback compatibility.

### 5. Quality engineering baseline

Adopt `.ai/12-QUALITY-ENGINEERING-GATES.md` as the cross-cutting planning baseline. It incorporates the audit findings and maps security, supply chain, privacy, accessibility, reliability, performance, observability, UI/UX, data quality, and AI safety to measurable acceptance gates.

Relevant external baselines include:

- NIST Secure Software Development Framework (SSDF)
- OWASP Application Security Verification Standard (ASVS)
- OWASP Artificial Intelligence Security Verification Standard (AISVS) for AI-enabled system security requirements
- SLSA build/provenance guidance
- OpenSSF Scorecard practices
- WCAG 2.2 Level AA
- SRE-style SLO/error-budget and capacity practices

These references are baselines to research against at task activation, not frozen dependency versions. Their current stable versions must be revalidated by the active Research Gate; for example, OWASP AISVS 1.0 became available in 2026 after the repository's original AI-control-plane design.

### 6. Audit findings become planned work

The following are explicit roadmap requirements:

- required branch/repository rules and status checks before privileged production development;
- dependency, SAST/code, secret, and supply-chain scanning;
- hash-pin third-party CI actions or document an approved exception;
- SBOM/provenance/release-integrity path before production distribution;
- PostgreSQL-backed integration and critical browser acceptance paths;
- load/capacity/queue saturation and p95/p99 SLO gates before scale claims;
- accessibility, keyboard, responsive, and visual-regression gates for critical UI;
- backup/restore, RPO/RTO, disaster-recovery and webhook replay evidence;
- security regression, fuzz/property testing where risk justifies it, and provider contract testing;
- AI prompt-injection, tool-abuse, cross-tenant context, data-exfiltration, budget-denial, malformed-output, unsafe-fallback, and applicable AISVS-derived security evaluations;
- immutable auditability and deterministic policy gates for all privileged AI/provider actions;
- design-system consistency and complete loading/empty/error/permission states;
- data retention, deletion/export, classification, redaction, residency, and privacy research before enterprise release.

## Sequence impact

The existing PHASE-00 through PHASE-16 order remains authoritative. This proposal does not renumber phases. It expands the implementation skeleton inside those phases and makes security/research prerequisites explicit.

PHASE-03 becomes the immediate next planned phase. TASK-0013 is governance/research only and must not implement provider functionality. It researches current provider/channel/API constraints, reconciles this ADR, finalizes PHASE-03 tasks, and closes the current no-successor gap before provider code begins.

## Extension-only planning rule

Research may:

- add a prerequisite task;
- split an unstarted task while preserving traceability;
- add acceptance criteria;
- introduce a proposed ADR;
- add a new connector capability or test matrix;
- defer a capability with explicit evidence and user/ADR approval.

Research may not silently:

- delete a planned requirement;
- weaken an acceptance criterion;
- mark work complete;
- reorder completed history;
- bypass an earlier dependency;
- change permanent security/consent/provider-neutral invariants;
- promote a provider/model because of marketing claims without measured evidence.

## Consequences

### Positive

- AI sessions have a complete long-horizon direction instead of inventing future work.
- Fast-changing external constraints are revalidated immediately before implementation.
- Social, creative, community, email, messaging, analytics, and AI can share canonical policy/data foundations.
- Audit findings become testable roadmap obligations.
- Model/provider churn does not force core rewrites.

### Trade-offs

- More planning artifacts and pre-implementation research are required.
- Some tasks will intentionally stop after research if provider/legal/security evidence invalidates assumptions.
- The roadmap may grow beyond TASK-0100 when research identifies justified prerequisites; this is expected and must be explicit.

## Acceptance path

This ADR remains `Proposed` until TASK-0013:

1. completes a current internet/official-document research pack;
2. verifies that proposed module boundaries do not duplicate existing canonical responsibilities;
3. reconciles audit findings against current repository controls;
4. finalizes PHASE-03 task/exit criteria;
5. validates the preplanned TASK-0013..TASK-0100 skeleton for dependency consistency;
6. records any required changes before marking this ADR Accepted;
7. passes AI continuity and applicable repository quality gates.

No implementation may rely on the proposed module additions until this ADR is accepted.