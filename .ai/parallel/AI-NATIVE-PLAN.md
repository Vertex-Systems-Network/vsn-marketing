# AI-Native Parallel Plan — TASK-0029 Deliverability Observability

Status: **active — telemetry evidence wave**. TASK-0028 frequency, reputation/health and safe-sending policy is certified on protected `main`; TASK-0029 is the active PHASE-05 successor. Only the telemetry/evidence foundation is active alongside Supervisor control. Diagnostics, remediation recommendations and adversarial certification remain dependency-gated.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0029`  
Parent task: `TASK-0029`  
Branch baseline: `72216c79f8c997af816f8bec6d2ad6372097a7f5`  
Active writers: `2` (1 Supervisor + 1 telemetry worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- TASK-0027 suppression/objection and TASK-0028 consent/authorization, sender readiness, frequency and safe-sending outcomes remain higher-order authority.
- Deliverability telemetry, diagnostics and recommendations cannot create permission, erase suppression, bypass a frequency/provider-policy decision, or promote deny/review/unknown to allow.
- Observations are workspace scoped and preserve provider, source, version, effective/observed time and provenance. Missing, stale, contradictory, malformed, foreign-workspace or untrusted evidence must remain explicit rather than being silently treated as healthy.
- Provider-specific thresholds and semantics remain versioned policy/evidence; no universal provider threshold is invented.
- Replay/duplicate observations are deterministic and idempotent. Cross-workspace reuse is forbidden.
- Recommendations are bounded, auditable proposals only. Risky DNS, sender, routing or provider changes require explicit human/policy approval and are never self-executed by this task.
- No anti-abuse evasion, spam-rate gaming, fake-account rotation, deceptive headers, provider-limit circumvention, automated consent creation or scraping authorization is introduced.

## Workstreams and merge order

1. **Telemetry/evidence (`worker-1/TASK-0029`)** — active. Implement provider-versioned, workspace-isolated deliverability observations/evidence and persistence contracts with replay-safe identity and no authorization semantics.
2. **Diagnostics (`worker-2/TASK-0029`)** — staged until telemetry/evidence is merged. Produce deterministic explainable diagnostics with stable reason codes and explicit unknown/review states.
3. **Remediation recommendations (`worker-3/TASK-0029`)** — staged until diagnostics is merged. Produce bounded auditable recommendations with explicit approval requirements and no self-execution.
4. **Adversarial/PostgreSQL certification (`worker-4/TASK-0029`)** — staged until remediation contracts are merged. Prove cross-workspace isolation, replay/duplicate behavior, stale/contradictory telemetry handling, recommendation non-execution and upstream authority precedence.
5. **Final acceptance** — exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0029 acceptance or TASK-0030 activation.

## Exact next action

Merge this control activation after exact-head continuity, application and security gates pass. Then implement only the allocated telemetry/evidence foundation on `worker-1/TASK-0029` from protected main, preserving provider/source/version/time provenance, workspace isolation and replay safety while keeping telemetry non-authoritative for sending permission. Merge that worker only after its exact-head required suites pass, then activate diagnostics as the next dependency-safe wave.
