# AI-Native Parallel Plan — TASK-0028 Safe Sending Policy

Status: **active — composition wave**. TASK-0027 suppression and objection authority remains certified on protected `main`. TASK-0028 frequency-cap and provider-versioned reputation/deliverability-health foundations are now merged on protected `main` at `6897f1f6e360a30ccf1aae49bfc27628a72449af`; safe-sending composition is the only active worker lane.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0028`  
Parent task: `TASK-0028`  
Branch baseline: `6593e6f6fd57adadaf80c2d84613a07b7a469a2b`  
Composition baseline: `6897f1f6e360a30ccf1aae49bfc27628a72449af`  
Active writers: `2` (1 Supervisor + 1 safe-sending worker)  
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
3. **Composition — Safe sending (`worker-3/TASK-0028`)**: active from the combined protected-main foundation baseline; composes base eligibility, TASK-0027 suppression authority, sender readiness, frequency and reputation/health into stable outcomes/reasons.
4. **Certification — Adversarial/PostgreSQL (`worker-4/TASK-0028`)**: remains staged and starts only after safe-sending composition lands; proves replay/concurrency, failover resistance, cross-workspace isolation, stale/contradictory evidence and suppression precedence.
5. **Final acceptance**: exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0028 acceptance or TASK-0029 activation.

## Exact next action

Land this control activation, then implement the safe-sending composition only on `worker-3/TASK-0028` from protected main `6897f1f6e360a30ccf1aae49bfc27628a72449af`. Preserve TASK-0027 canonical suppression/objection authority and sender-authentication evidence semantics; combine them with frequency and reputation outcomes without ever promoting deny, review or unknown to allow. Merge only after exact-head continuity, application and security gates pass, then activate the adversarial certification lane.
