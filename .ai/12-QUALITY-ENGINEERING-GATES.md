# Quality Engineering Gates

These gates convert repository audit findings into planned, measurable engineering obligations. They apply across phases in addition to task-specific acceptance criteria.

The intent is not checkbox compliance. Each gate must be backed by evidence appropriate to the risk of the active task.

## 1. Security engineering

### Required baseline

- Default-deny authorization and workspace isolation remain mandatory.
- Threat modeling is required for new trust boundaries, credentials, webhooks, AI tools, tracking, billing, destructive actions, and externally reachable APIs.
- Security requirements must be testable and mapped to automated/manual verification.
- Sensitive actions must be auditable and attributable.
- Secrets are references to a secure secret store and must never be logged or embedded into AI context.
- Consent, suppression, permissions, approvals, quotas, budgets, and policy cannot be bypassed by AI/provider fallback logic.

### Planned repository controls

Before production-capable external integrations are accepted, establish and evidence:

- repository rules/branch protection with required status checks;
- review requirements for high-risk changes where repository capabilities allow;
- dependency review and automated dependency updates;
- SAST/code scanning;
- secret scanning or equivalent repository/runtime secret-detection controls;
- vulnerability scanning for application and container dependencies;
- hash-pinned third-party GitHub Actions or explicit reviewed exceptions;
- SBOM generation for production releases;
- build/release provenance and artifact integrity aligned to a practical SLSA target;
- OpenSSF Scorecard or equivalent recurring supply-chain posture assessment;
- signed/attested release path where deployment model justifies it.

### Application security verification

Use current OWASP ASVS guidance as a verification baseline for applicable web/API controls. High-risk flows require negative tests, not only happy-path tests.

## 2. Tenant and data-isolation gates

For every tenant-owned entity or async path, tests must cover relevant cross-workspace attacks through:

- reads;
- writes;
- references/foreign keys;
- list/membership relationships;
- jobs/queues;
- caches;
- object-storage paths;
- events/webhooks;
- AI context/memory;
- analytics/reporting aggregates;
- search/index/vector surfaces when introduced;
- exports/imports;
- retry/replay paths.

A cross-tenant leak is a release-blocking defect.

## 3. Privacy and compliance engineering

Before collecting or processing a new data class, research and define:

- purpose and lawful/contractual basis where relevant;
- consent/preference behavior;
- data classification;
- retention period;
- deletion/anonymization behavior;
- export/access behavior;
- regional/residency restrictions;
- encryption requirements;
- AI-model data-sharing/training policy;
- logging/redaction policy;
- third-party processor/provider implications.

Region-specific marketing/privacy rules must be researched before claiming compliance. The product must not infer universal legality from one jurisdiction.

## 4. Software supply-chain gates

Production build/release paths must progressively provide:

- deterministic lockfiles;
- pinned container bases/digests where operationally practical;
- pinned CI dependencies;
- dependency provenance/ownership review for critical packages;
- SBOM;
- vulnerability-policy thresholds;
- authenticated build provenance;
- reproducible or independently verifiable build evidence where justified;
- documented patch/update response policy.

A dependency update must not silently change canonical behavior without the relevant tests.

## 5. Testing pyramid and risk expansion

Required test layers remain unit, contract, integration, feature/API, E2E, and security regression. Add the following when the risk warrants them:

- property-based tests for deterministic domain invariants;
- fuzz tests for parsers, webhook payloads, template inputs, importers, and externally controlled structured data;
- mutation testing selectively for critical deterministic policy code;
- concurrency/race tests for idempotency, locks, quotas, counters, enrollment, and scheduling;
- replay tests for webhooks/events/outbox/DLQ recovery;
- fault-injection tests for external providers and partial infrastructure failures;
- migration/rollback verification;
- long-running soak tests for queues/schedulers when scale paths are introduced.

Tests may not be weakened to make a task pass.

## 6. Database and production-parity gates

PostgreSQL is the canonical relational runtime. SQLite may remain useful for fast isolated tests, but critical production workflows must receive PostgreSQL-backed acceptance coverage.

Before major user workflows are certified, browser/API acceptance must include a production-representative PostgreSQL path for flows where database semantics, constraints, transactions, locking, JSON/index behavior, or concurrency can affect correctness.

Redis/queue/object-storage behavior must similarly receive integration evidence when relied upon by the task.

## 7. Performance engineering and SLOs

Do not claim performance from intuition. Define and measure service-level indicators for relevant workflows.

Potential SLIs include:

- API p50/p95/p99 latency;
- queue wait/age;
- scheduled-send or scheduled-publication lateness;
- provider dispatch latency;
- webhook ingest/normalization latency;
- automation/journey trigger latency;
- campaign materialization throughput;
- asset transformation duration;
- AI request latency and fallback rate;
- database query count/time;
- cache hit/miss behavior;
- error/retry/DLQ rates.

Before scale-critical phases close, establish:

- explicit SLOs;
- realistic load profiles;
- capacity/load tests;
- queue saturation/backpressure tests;
- p95/p99 budgets;
- resource/cost measurements;
- regression thresholds.

Optimization must be driven by measured bottlenecks. Premature microservices, search clusters, vector stores, or infrastructure expansion still require an ADR and evidence.

## 8. Reliability and resilience

As capabilities become operational, evidence must cover:

- idempotent retries;
- duplicate delivery/event suppression;
- bounded retry policy;
- retryable versus permanent failure classification;
- circuit breakers;
- dead-letter handling;
- replay/reconciliation tools;
- provider outage/degradation behavior;
- timezone/DST scheduling behavior;
- clock skew where relevant;
- partial batch failure;
- rollback/cancel semantics;
- stale-state detection;
- recovery after process interruption.

No fallback may weaken security, consent, suppression, approval, schema, data-region, or risk constraints.

## 9. Backup, restore, and disaster recovery

Before enterprise/production readiness is claimed, define and test:

- backup scope and encryption;
- restore procedure;
- restore integrity checks;
- recovery point objective (RPO);
- recovery time objective (RTO);
- object-store recovery/versioning behavior;
- credential/secret recovery process;
- queue/outbox/event reconciliation after restore;
- cross-region strategy if offered;
- disaster-recovery exercise evidence.

A backup that has not been restore-tested is not acceptance evidence.

## 10. Observability and operability

Every critical distributed/async/AI/provider operation should be traceable through a correlation identifier.

Plan for structured logs, metrics, traces where justified, dashboards, and alerting for:

- request/queue/provider errors;
- rate limits and quota exhaustion;
- retries/DLQ;
- webhook failures;
- scheduled work lateness;
- sender/deliverability health;
- AI route/model/prompt/tool decisions;
- AI cost/latency/schema failures;
- tenant-isolation/security denials;
- data-sync lag and reconciliation drift.

Logs must not expose secrets or unnecessary PII.

## 11. AI safety, security, and evaluation

AI execution-driving output must remain structured and schema-validated.

Before privileged product AI is accepted, test at minimum:

- malformed structured output;
- prompt injection from customer/provider/web content;
- indirect prompt injection through retrieved knowledge;
- tool argument manipulation;
- unauthorized tool selection;
- cross-workspace context leakage;
- secret/data exfiltration attempts;
- policy/risk-tier manipulation;
- hallucinated identifiers/entities;
- stale context;
- incompatible model fallback;
- model outage and retry storms;
- budget/cost exhaustion;
- excessive tool loops;
- conflicting instructions;
- malicious uploaded/template/social content;
- self-modification or evaluation-threshold manipulation attempts.

AI quality promotion requires externally computed evaluation thresholds. An agent cannot approve its own prompt/model/policy promotion.

## 12. AI model/vendor evaluation

Never select a model only because it is popular. The AI gateway must evaluate routes against task-required capabilities and measured evidence including:

- structured-output/schema reliability;
- tool-call reliability;
- reasoning/task quality;
- latency;
- cost;
- context capacity;
- vision/media capability where needed;
- region/privacy/data-use terms;
- fallback compatibility;
- availability/failure rate;
- task-specific golden evaluations.

Provider/model configuration remains replaceable.

## 13. Provider and connector certification

Every connector must test:

- auth success/failure/expiry/rotation;
- capability declarations;
- success path;
- transient failure;
- permanent failure;
- rate limiting/quota exhaustion;
- idempotency behavior;
- malformed provider responses;
- webhook signature/authenticity when supported;
- replay/duplicate webhook behavior;
- unsupported feature failure;
- sandbox/test behavior;
- provider API version/deprecation compatibility.

Social/publishing connectors additionally research and test applicable account types, app review, scopes, media constraints, status polling, edit/delete semantics, comments/community permissions, analytics availability, and AI-generated-content disclosure/metadata requirements where platforms require them.

## 14. Deliverability and messaging quality

Before production messaging at scale, research current provider and industry requirements for:

- domain/sender verification;
- SPF/DKIM/DMARC where applicable;
- unsubscribe/preference mechanisms;
- suppression;
- bounce/complaint handling;
- rate/frequency limits;
- reputation monitoring;
- transactional versus marketing separation;
- provider-specific policy and required headers/metadata.

Never implement mechanisms intended to evade anti-abuse controls.

## 15. UI/UX engineering

User-facing modules require a coherent design system rather than isolated AI-generated screens.

Critical workflows must define and test:

- information hierarchy;
- responsive behavior;
- keyboard navigation;
- visible focus;
- loading/skeleton states;
- empty states;
- error/retry states;
- partial-success states;
- permission-denied states;
- approval/pending states;
- destructive confirmation/undo where appropriate;
- timezone/date clarity;
- localization/internationalization readiness;
- bulk-operation safeguards;
- audit/history visibility for high-impact actions.

Use visual regression for stable critical surfaces once the design system exists.

## 16. Accessibility

Target current WCAG 2.2 Level AA for the first-party web application unless an explicitly documented requirement is stricter.

Automated accessibility checks are useful but not sufficient. Critical workflows also require keyboard/focus/manual validation appropriate to the task.

## 17. Data quality and analytics correctness

Analytics/attribution work must establish:

- event schema/version lineage;
- deduplication rules;
- late/out-of-order event behavior;
- timezone rules;
- identity merge/split behavior;
- source-of-truth definitions;
- metric definitions;
- reconciliation against provider/source totals;
- sampling/estimation disclosure;
- attribution-model versioning;
- data freshness indicators.

AI-generated explanations must distinguish measured facts from inference.

## 18. Six Sigma / continuous quality approach

Use Six Sigma concepts where they improve software operations rather than forcing manufacturing metrics onto code.

For material workflows:

- define Critical-to-Quality (CTQ) outcomes;
- define what counts as a defect;
- measure baseline defect/error rates;
- analyze root causes;
- improve the process/control;
- track recurrence after remediation.

Suggested zero-tolerance CTQs include:

- cross-tenant data leak;
- consent/suppression bypass;
- unauthorized privileged AI action;
- duplicate billable send caused by retry defects;
- untraceable high-risk state mutation;
- secret exposure.

Do not claim literal Six Sigma capability without statistically meaningful production data.

## 19. Phase certification rule

A phase cannot close merely because feature code exists. Certification must evaluate applicable gates from this document and explicitly record which gates are not yet applicable and why.

New research findings may strengthen these gates. They may not silently weaken them.