# AI-Native Parallel Plan — TASK-0027 Suppression & Objection Authority

Status: **active — five-writer Shipping Mode implementation**. TASK-0027 is the canonical active task. This cycle implements deterministic suppression/objection authority, RFC 8058 one-click unsubscribe contracts, explicit jurisdiction-aware delivery eligibility inputs, and provider-neutral bounce/complaint/unsubscribe feedback/reconciliation primitives. It must not implement TASK-0028 frequency caps, reputation scoring or deliverability-health policy.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0027`  
Shipping integration branch: `ship/week-1`  
Branch creation baseline: `ea9d86a473cb96f7d74fe1cb5186a3ed823f841c`  
Parent task: `TASK-0027`  
Dependency evidence: certified `TASK-0026` plus accepted `TASK-0025` suppression/RFC8058/jurisdiction research  
Writer target and Shipping Mode cap: `5` (1 Supervisor + 4 workers)  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Suppression and direct-marketing objection are deterministic authority. AI, provider failover, deliverability/reputation signals, campaign configuration and admin convenience cannot recreate permission.
- Accepted unsubscribe or objection evidence becomes effective inside VSN immediately; provider synchronization is separate reconciliation state and its delay, failure, timeout or ambiguity cannot restore eligibility.
- Canonical suppression/feedback state is workspace scoped, idempotent/replay-safe and evidence preserving. Cross-workspace references fail closed.
- Marketing versus transactional purpose is explicit. Eligibility also carries jurisdiction, subscriber/person type, solicitation/relationship basis, consent or soft-opt-in evidence and applicable suppression/objection context.
- Missing or unsupported policy context yields deny, review or unknown; absence of a suppression is never by itself permission to send.
- UK ordinary commercial soft opt-in and the 2026 charitable-purposes soft opt-in remain distinct effective-dated policy inputs.
- Applicable one-click unsubscribe follows RFC 8058: HTTPS `List-Unsubscribe`, `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, independently testable DKIM/header eligibility, opaque scoped tokens, and no cookie, HTTP-auth or prior browser-session dependency.
- Bounce, complaint and unsubscribe provider feedback preserves source/provider provenance, observation time, version/classification and replay identity. Malformed, contradictory, stale, foreign-workspace or untrusted evidence fails closed.
- Bought/public-list provenance never becomes generic permission. No scraping authorization, anti-abuse evasion, account rotation, deceptive-header behavior or provider-limit circumvention is introduced.
- TASK-0028 frequency/reputation/health policy is explicitly out of scope for this cycle.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0027-SUPERVISOR-CONTROL | Five-writer control, merge-wave coordination and final acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0027-SUPPRESSION-CORE | Canonical suppression/objection/preference/bounce/complaint evidence + persistence boundary | `occupied` | `worker-task0027-suppression` | `active` | `worker-1/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 20 | WS-0027-RFC8058 | Opaque scoped one-click unsubscribe and RFC 8058 header/endpoint semantics | `occupied` | `worker-task0027-rfc8058` | `active` | `worker-2/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 30 | WS-0027-ELIGIBILITY-POLICY | Explicit purpose/jurisdiction/relationship/consent/suppression eligibility decisions | `occupied` | `worker-task0027-eligibility` | `active` | `worker-3/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 40 | WS-0027-PROVIDER-FEEDBACK | Provider-neutral bounce/complaint/unsubscribe evidence and reconciliation primitives | `occupied` | `worker-task0027-provider-feedback` | `active` | `worker-4/TASK-0027` | squash | merge latest ship/week-1 before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave 0 — control activation:** activate this five-lane registry on `ship/week-1` only after the exact-head Shipping Fast Gate passes. No product code is included in this activation.
2. **Wave 1 — independent contracts in parallel:** the four worker lanes implement only their assigned non-overlapping contracts from the certified integration baseline. Each PR targets `ship/week-1` and must pass the exact-head Shipping Fast Gate.
3. **Wave 2 — canonical integration:** after suppression core and the relevant contracts are present on `ship/week-1`, admit a later integration lane only after writer capacity is freed. Wire service-provider/application boundaries, immediate pre-routing suppression authority and provider reconciliation without weakening fail-closed behavior.
4. **Wave 3 — adversarial/PostgreSQL/RFC acceptance:** use freed capacity for cross-workspace, replay, duplicate/concurrent opt-out, provider ambiguity, PII/secret leakage, unknown-jurisdiction and real PostgreSQL acceptance coverage.
5. **Wave 4 — integration certification:** require the full Application Foundation CI on `ship/week-1`, including PostgreSQL/Redis and Playwright where applicable. Failed chains block only their dependent work until reconciled.
6. **Final promotion:** promote the certified integration baseline to protected `main` and require AI Continuity, Application Foundation, Security Supply Chain, Release Integrity, OpenSSF Scorecard and Persistent Supervisor evidence before marking TASK-0027 complete or activating TASK-0028.

## Exact next action

Merge the control activation into `ship/week-1` after a green Shipping Fast Gate, fast-forward all five registered branches to that certified integration baseline, then execute the four independent Wave 1 product lanes in parallel. Do not implement TASK-0028 reputation/frequency policy, weaken suppression/objection authority, or infer permission from missing negative evidence.
