# AI-Native Parallel Plan — TASK-0030 PHASE-05 Certification

Status: **active — PHASE-05 certification wave**. TASK-0025 through TASK-0029 are completed on protected `main`; TASK-0030 is the only active PHASE-05 task. One focused certification worker is active alongside Supervisor control.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0030`  
Parent task: `TASK-0030`  
Branch baseline: `4f105d5c124b64e57f67bb79f952c5d95393639c`  
Active writers: `2` (1 Supervisor + 1 certification worker)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen invariants

- Canonical suppression and objection are absolute pre-routing authority; phase certification must prove zero bypass.
- Consent/authorization, sender readiness, frequency and provider policy remain deterministic higher-order controls.
- Deliverability/reputation telemetry may restrict or recommend but cannot create permission, erase suppression or override deny/review/unknown.
- Provider-specific authentication/bulk-sender semantics remain versioned/effective-dated evidence; no universal provider threshold is invented.
- Applicable RFC 8058 one-click unsubscribe remains independently testable and accepted canonical suppression becomes immediately authoritative; downstream provider sync is separate reconciled state.
- All sender/suppression/reputation/deliverability state is workspace isolated and replay/idempotency safe.
- No spam-rate gaming, fake-account rotation, deceptive headers, suppression bypass or provider-limit circumvention.
- No PHASE-06+ product implementation is pulled into certification.

## Workstreams

1. **Supervisor control (`supervisor/TASK-0030`)** — active. Own control-plane sequencing, final acceptance and phase closeout.
2. **PHASE-05 certification (`worker-1/TASK-0030`)** — active. Add only dedicated phase-wide Security and PostgreSQL certification tests covering the sender/suppression/provider/frequency/reputation/deliverability matrix.
3. **Final acceptance** — after worker certification merges, reconcile AC-1 through AC-8 and require exact-head AI Continuity, Application Foundation and Security Supply Chain green before PHASE-05 completion.

## Exact next action

Merge this activation after exact-head continuity, application and security gates pass. Then implement only the two allocated TASK-0030 certification test files on `worker-1/TASK-0030`. Reuse canonical TASK-0026 through TASK-0029 contracts; do not add new product behavior merely to make certification pass. Merge certification only after PostgreSQL integration, backend, static analysis, formatting, E2E and security gates pass.
