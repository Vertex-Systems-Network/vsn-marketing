# AI-Native Parallel Plan — TASK-0029 Deliverability Observability

Status: **active — diagnostics wave**. TASK-0029 telemetry/evidence foundation is merged on protected `main`; deterministic diagnostics is now the only active worker stream alongside Supervisor control. Remediation recommendations and adversarial certification remain dependency-gated.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0029`  
Parent task: `TASK-0029`  
Branch baseline: `c49a5e4ae7af3a94265ef0846f2ea9428fbeb2e5`  
Active writers: `2` (1 Supervisor + 1 diagnostics worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- TASK-0027 suppression/objection and TASK-0028 consent/authorization, sender readiness, frequency and safe-sending outcomes remain higher-order authority.
- Deliverability telemetry, diagnostics and recommendations cannot create permission, erase suppression, bypass a frequency/provider-policy decision, or promote deny/review/unknown to allow.
- Observations are workspace scoped and preserve provider, source, version, effective/observed time and provenance. Missing, stale, contradictory, malformed, foreign-workspace or untrusted evidence must remain explicit rather than being silently treated as healthy.
- Provider-specific thresholds and semantics remain versioned policy/evidence; no universal provider threshold is invented.
- Diagnostics must be deterministic and explainable from trusted evidence only, with stable reason codes and explicit Unknown/Review outcomes where evidence is absent, stale, contradictory or unsupported.
- Recommendations remain bounded, auditable proposals only. Risky DNS, sender, routing or provider changes require explicit human/policy approval and are never self-executed by this task.
- No anti-abuse evasion, spam-rate gaming, fake-account rotation, deceptive headers, provider-limit circumvention, automated consent creation or scraping authorization is introduced.

## Workstreams and merge order

1. **Telemetry/evidence (`worker-1/TASK-0029`)** — completed and merged. Provider-versioned, workspace-isolated observations/evidence preserve provenance and replay safety without authorization semantics.
2. **Diagnostics (`worker-2/TASK-0029`)** — active. Produce deterministic explainable diagnostics with stable reason codes and explicit Unknown/Review states from trusted telemetry only.
3. **Remediation recommendations (`worker-3/TASK-0029`)** — staged until diagnostics is merged. Produce bounded auditable recommendations with explicit approval requirements and no self-execution.
4. **Adversarial/PostgreSQL certification (`worker-4/TASK-0029`)** — staged until remediation contracts are merged. Prove cross-workspace isolation, replay/duplicate behavior, stale/contradictory telemetry handling, recommendation non-execution and upstream authority precedence.
5. **Final acceptance** — exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0029 acceptance or TASK-0030 activation.

## Exact next action

Merge this diagnostics activation control after exact-head continuity, application and security gates pass. Then implement only the allocated diagnostics files on `worker-2/TASK-0029` from protected main. Diagnostics must consume trusted TASK-0029 telemetry without inventing provider thresholds, preserve workspace/provider/purpose context, emit stable evidence-backed reason codes, and fail closed to Unknown/Review for missing, stale, contradictory, foreign or unsupported evidence. Merge that worker only after exact-head required suites pass, then activate bounded remediation recommendations.
