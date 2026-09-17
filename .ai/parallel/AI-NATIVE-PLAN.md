# AI-Native Parallel Plan — TASK-0028 Safe Sending Policy

Status: **active — certification wave**. TASK-0027 suppression and objection authority remains certified on protected `main`. TASK-0028 frequency, reputation/health and safe-sending composition are merged on protected `main` at `c7d531f010433f3a245d5d9896d3bc63878d8317`; adversarial/PostgreSQL certification is the only active worker lane.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0028`  
Parent task: `TASK-0028`  
Branch baseline: `6593e6f6fd57adadaf80c2d84613a07b7a469a2b`  
Certification baseline: `c7d531f010433f3a245d5d9896d3bc63878d8317`  
Active writers: `2` (1 Supervisor + 1 adversarial certification worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- Consent/authorization, sender-identity readiness, canonical suppression and direct-marketing objection remain higher-order authority; reputation, routing or campaign configuration cannot recreate permission.
- Missing, invalid, stale, contradictory, malformed, foreign-workspace or unsupported policy/evidence never becomes implicit allow.
- Marketing versus transactional purpose is explicit input and cannot be inferred from score, provider, route or campaign metadata.
- Frequency policy is workspace scoped and explicit for message purpose plus recipient scope. Windows, thresholds, counters, evaluation time and replay/idempotency semantics are deterministic.
- Provider reputation/health evidence preserves provider, source, version, effective date and observation time. Provider-specific bulk/high-volume semantics remain versioned evidence, never global invented constants.
- Outcomes are explicit allow, deny, review or unknown with stable reason codes suitable for audit/observability.
- Retry, failover or concurrency must not bypass frequency authority or suppression/objection authority.
- No anti-abuse evasion, provider-limit circumvention, fake-account rotation, deceptive headers, automated consent creation or scraping authorization is introduced.

## Workstreams and merge order

1. **Foundation A — Frequency policy (`worker-1/TASK-0028`)**: merged to protected main after exact-head continuity, application and security gates passed.
2. **Foundation B — Reputation/health (`worker-2/TASK-0028`)**: merged to protected main after exact-head continuity, application and security gates passed.
3. **Composition — Safe sending (`worker-3/TASK-0028`)**: merged to protected main after exact-head continuity, application and security gates passed; TASK-0027 authority remains upstream and downstream health signals cannot promote non-allow outcomes.
4. **Certification — Adversarial/PostgreSQL (`worker-4/TASK-0028`)**: active from protected main `c7d531f010433f3a245d5d9896d3bc63878d8317`; prove replay/concurrency, failover resistance, cross-workspace isolation, stale/contradictory evidence, missing context and suppression precedence.
5. **Final acceptance**: exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0028 acceptance or TASK-0029 activation.

## Exact next action

Land this certification control activation, then add only the allocated PostgreSQL integration and adversarial security coverage on `worker-4/TASK-0028` from protected main `c7d531f010433f3a245d5d9896d3bc63878d8317`. Exercise cross-workspace isolation, replay/concurrency, provider-failover attempts, stale/contradictory evidence, missing policy context and canonical suppression precedence without adding evasion or provider-limit bypass behavior. Merge only after exact-head continuity, application and security gates pass, then perform TASK-0028 final acceptance and release all remaining leases.
