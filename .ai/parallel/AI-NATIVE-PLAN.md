# AI-Native Parallel Plan — TASK-0029 Deliverability Observability

Status: **active — remediation recommendations wave**. TASK-0029 telemetry/evidence and deterministic diagnostics are merged on protected `main`; bounded remediation recommendations are now the only active worker stream alongside Supervisor control. Adversarial/PostgreSQL certification remains dependency-gated.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0029`  
Parent task: `TASK-0029`  
Branch baseline: `5befc8ddefc640f5e73910966ce5ec3c4dba3991`  
Active writers: `2` (1 Supervisor + 1 remediation worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- TASK-0027 suppression/objection and TASK-0028 consent/authorization, sender readiness, frequency and safe-sending outcomes remain higher-order authority.
- Deliverability telemetry, diagnostics and recommendations cannot create permission, erase suppression, bypass a frequency/provider-policy decision, or promote deny/review/unknown to allow.
- Observations remain workspace scoped and preserve provider, source, version, effective/observed time and provenance. Missing, stale, contradictory, malformed, foreign-workspace or untrusted evidence stays explicit.
- Provider-specific thresholds and semantics remain versioned policy/evidence; no universal provider threshold is invented.
- Diagnostics remain deterministic and explainable with stable reason codes and explicit Unknown/Review states.
- Remediation output is proposal-only and side-effect free. Risky DNS, sender identity, routing/provider, suppression, frequency or provider-policy changes require explicit human/policy approval before any separate execution path may act.
- Recommendations cannot create consent, recreate authorization, erase suppression, increase sending authority, rotate accounts to evade provider controls, or bypass provider/frequency policy.
- No anti-abuse evasion, spam-rate gaming, fake-account rotation, deceptive headers, provider-limit circumvention, automated consent creation or scraping authorization is introduced.

## Workstreams and merge order

1. **Telemetry/evidence (`worker-1/TASK-0029`)** — completed and merged.
2. **Diagnostics (`worker-2/TASK-0029`)** — completed and merged.
3. **Remediation recommendations (`worker-3/TASK-0029`)** — active. Produce bounded auditable proposals with explicit scope, rationale, evidence, risk and approval requirements; no self-execution.
4. **Adversarial/PostgreSQL certification (`worker-4/TASK-0029`)** — staged until remediation contracts are merged. Prove cross-workspace isolation, replay/duplicate behavior, stale/contradictory telemetry handling, recommendation non-execution and upstream authority precedence.
5. **Final acceptance** — exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0029 acceptance or TASK-0030 activation.

## Exact next action

Merge this remediation activation control after exact-head continuity, application and security gates pass. Then implement only the allocated remediation recommendation files on `worker-3/TASK-0029` from protected main. Recommendations must be deterministic, bounded, auditable, evidence-backed and side-effect free; risky categories must carry explicit human/policy approval requirements, while unknown/review diagnostics produce investigation or evidence-refresh proposals rather than direct infrastructure or sending mutations. Merge that worker only after exact-head required suites pass, then activate adversarial/PostgreSQL certification.
