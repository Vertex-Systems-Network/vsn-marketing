# AI-Native Parallel Plan — TASK-0029 Deliverability Observability

Status: **active — persistence and adversarial certification wave**. TASK-0029 telemetry/evidence, deterministic diagnostics, and bounded remediation recommendations are merged on protected `main`. The final worker wave now closes the durable PostgreSQL persistence gap and certifies adversarial behavior before acceptance.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0029`  
Parent task: `TASK-0029`  
Branch baseline: `4bb6dd29c8fd0bb7d7ea87b6227c752affce2301`  
Active writers: `2` (1 Supervisor + 1 persistence/certification worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- TASK-0027 suppression/objection and TASK-0028 consent/authorization, sender readiness, frequency and safe-sending outcomes remain higher-order authority.
- Deliverability telemetry, diagnostics and recommendations cannot create permission, erase suppression, bypass a frequency/provider-policy decision, or promote deny/review/unknown to allow.
- Observations remain workspace scoped and preserve provider, source, version, effective/observed time and provenance. Missing, stale, contradictory, malformed, foreign-workspace or untrusted evidence stays explicit.
- Provider-specific thresholds and semantics remain versioned policy/evidence; no universal provider threshold is invented.
- Diagnostics remain deterministic and explainable with stable reason codes and explicit Unknown/Review states.
- Remediation remains proposal-only and side-effect free. Risky DNS, sender identity, routing/provider, suppression, frequency or provider-policy changes require explicit human/policy approval before any separate execution path may act.
- Recommendations cannot create consent, recreate authorization, erase suppression, increase sending authority, rotate accounts to evade provider controls, or bypass provider/frequency policy.
- Durable observation persistence must be append-only, workspace isolated, replay/idempotency safe, race safe and provenance preserving. Exact replay returns canonical stored evidence; conflicting replay or foreign-workspace identity reuse fails closed.
- No anti-abuse evasion, spam-rate gaming, fake-account rotation, deceptive headers, provider-limit circumvention, automated consent creation or scraping authorization is introduced.

## Workstreams and merge order

1. **Telemetry/evidence (`worker-1/TASK-0029`)** — completed and merged.
2. **Diagnostics (`worker-2/TASK-0029`)** — completed and merged.
3. **Remediation recommendations (`worker-3/TASK-0029`)** — completed and merged.
4. **Persistence + adversarial/PostgreSQL certification (`worker-4/TASK-0029`)** — active. Add durable deliverability observation persistence and prove PostgreSQL workspace isolation, replay/conflict behavior, stale/contradictory telemetry handling, recommendation non-execution and upstream suppression/frequency/provider-policy precedence.
5. **Final acceptance** — exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0029 acceptance or TASK-0030 activation.

## Exact next action

Merge this certification activation after exact-head continuity, application and security gates pass. Then implement only the allocated worker-4 files on `worker-4/TASK-0029`: one append-only deliverability observations migration, one database-backed observation repository, one PostgreSQL integration certification test, and one adversarial security test. Do not modify suppression, safe-sending, consent, frequency or provider-policy authority. Merge worker-4 only after exact-head required suites pass, then run TASK-0029 acceptance certification.
