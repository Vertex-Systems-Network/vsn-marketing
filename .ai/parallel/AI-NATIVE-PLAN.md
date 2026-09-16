# AI-Native Parallel Plan — TASK-0027 Suppression & Objection Authority

Status: **active — Wave B five-writer Shipping Mode integration/acceptance**. Wave A is merged and certified at `df163673f004196c718326e1ad3cbb4362234bf3` with Shipping Fast Gate, AI Continuity and Application Foundation green. Wave B closes the public one-click HTTP, persistence/integration, adversarial security and suppression-aware eligibility wiring gaps without implementing TASK-0028 frequency caps, reputation scoring or deliverability-health policy.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0027-wave-b`  
Shipping integration branch: `ship/week-1`  
Wave-B branch creation baseline: `df163673f004196c718326e1ad3cbb4362234bf3`  
Parent task: `TASK-0027`  
Dependency evidence: certified `TASK-0026`, accepted `TASK-0025` research, and certified TASK-0027 Wave-A contracts/schema at `df163673...`  
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
- The public one-click endpoint consumes only opaque scoped token material plus the RFC 8058 POST contract; browser auth/session/cookie state is never an identity source.
- Raw unsubscribe tokens, provider credentials and private signing material are never stored in canonical/audit payloads. Persistence uses digest/scope/version/expiry/consumption evidence only.
- Duplicate and concurrent opt-out/replay paths remain deterministic and idempotent; conflicting replay fails closed.
- Bounce, complaint and unsubscribe provider feedback preserves source/provider provenance, observation time, version/classification and replay identity. Malformed, contradictory, stale, foreign-workspace or untrusted evidence fails closed.
- Provider reconciliation cannot remove or bypass an internally accepted suppression.
- Bought/public-list provenance never becomes generic permission. No scraping authorization, anti-abuse evasion, account rotation, deceptive-header behavior or provider-limit circumvention is introduced.
- TASK-0028 frequency/reputation/health policy is explicitly out of scope.
- Shared paths (`routes/**`, migrations, control files) remain Supervisor-owned.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0027-SUPERVISOR-CONTROL | Wave-B control, shared API route wiring, merge-wave coordination and final acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0027-wave-b` | squash | merge latest ship/week-1 before resume |
| 50 | WS-0027-ONECLICK-HTTP | Public RFC 8058 HTTP/controller boundary and immediate internal opt-out acceptance | `occupied` | `worker-task0027-oneclick-http` | `active` | `worker-5/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 60 | WS-0027-PERSISTENCE-INTEGRATION | Digest-only unsubscribe-token persistence plus PostgreSQL replay/cross-tenant certification | `occupied` | `worker-task0027-persistence` | `active` | `worker-6/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 70 | WS-0027-ADVERSARIAL-SECURITY | Token/replay/cross-workspace/provider/PII adversarial acceptance coverage | `occupied` | `worker-task0027-security` | `active` | `worker-7/TASK-0027` | squash | merge latest ship/week-1 before resume |
| 80 | WS-0027-ELIGIBILITY-WIRING | Immediate suppression-aware pre-routing eligibility authority | `occupied` | `worker-task0027-eligibility-wiring` | `active` | `worker-8/TASK-0027` | squash | merge latest ship/week-1 before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave A — certified foundation (complete):** schema, canonical suppression/preference persistence, RFC 8058 contracts, fail-closed eligibility policy and provider feedback/reconciliation primitives are merged at `df163673...`; exact-head Shipping Fast Gate, AI Continuity and Application Foundation are green.
2. **Wave B1 — persistence + public application boundary:** implement digest-only token scope/consumption persistence and the public one-click application/controller boundary in parallel. Supervisor owns the final `routes/api.php` registration after the controller contract is present.
3. **Wave B2 — eligibility integration + adversarial acceptance:** wire immediate suppression authority into pre-routing eligibility while independently adding cross-workspace, replay, duplicate/concurrent opt-out, provider contradiction, secret/PII and unknown-context adversarial coverage.
4. **Wave B3 — combined integration certification:** merge only exact-head green PRs into `ship/week-1`, then require latest-head Shipping Fast Gate, AI Continuity and full Application Foundation including PostgreSQL/Redis and Playwright. Fix only the affected chain.
5. **Wave B4 — acceptance reconciliation:** verify AC-1 through AC-7 against merged evidence, add any missing integration/security coverage, and keep TASK-0028 out of scope.
6. **Final promotion:** promote the certified integration baseline to protected `main` and require AI Continuity, Application Foundation, Security Supply Chain, Release Integrity, OpenSSF Scorecard and Persistent Supervisor evidence before marking TASK-0027 complete or activating TASK-0028.

## Exact next action

Merge the Wave-B control admission into `ship/week-1` after an exact-head green Shipping Fast Gate/AI Continuity check, fast-forward `supervisor/TASK-0027-wave-b` and `worker-5/TASK-0027` through `worker-8/TASK-0027` to the resulting certified control baseline, then execute the four non-overlapping Wave-B product/acceptance lanes. Supervisor adds the shared one-click API route only after the worker HTTP contract lands. Do not implement TASK-0028 reputation/frequency policy or weaken immediate suppression/objection authority.
