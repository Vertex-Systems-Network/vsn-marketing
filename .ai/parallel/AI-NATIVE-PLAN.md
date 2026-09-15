# AI-Native Parallel Plan — TASK-0026 Sender Domain & Identity Foundation

Status: **active — dependency-safe parallel implementation**. TASK-0026 is the canonical active task. This cycle implements workspace-scoped sender domains and identities, separately versioned authentication evidence, provider-versioned mailbox policy context, deterministic verification/synchronization and adversarial fail-closed coverage. It must not perform live DNS mutation or production sender activation.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0026`  
Branch creation baseline: `91589bcae6faf1defee58e52d058223d7cc612ee`  
Parent task: `TASK-0026`  
Dependency evidence: accepted `TASK-0025` provider/authentication research  
Writer target: `10` logical lanes (1 Supervisor + 9 workers)  
Hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Sender-domain and sender-identity state is workspace scoped; composite persistence boundaries must prevent cross-workspace references.
- No canonical `verified` boolean exists. SPF, DKIM, DMARC, From-domain alignment, forward DNS, reverse DNS and TLS are independent evidence dimensions with version, observation time and freshness.
- Unknown, stale, contradictory or malformed evidence fails closed; absence of negative evidence is never equivalent to verified.
- Mailbox-provider policy is effective-dated, provider-versioned and provenance-bearing. Gmail and Outlook thresholds remain provider-specific; Yahoo classification remains configurable when official evidence does not publish a universal numeric threshold.
- Canonical sender records never contain DNS credentials or private signing material. Existing approved secret-reference/provider-connection boundaries are reused.
- Verification and synchronization are deterministic and idempotent, preserve timeout/ambiguous outcomes, and emit auditable evidence.
- Record creation, evidence refresh and verification do not mutate production DNS and do not activate production sending implicitly.
- Sender eligibility may expose purpose/provider/policy context for later safe-sending evaluation but must not create consent or bypass suppression/objection authority reserved for later tasks.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0026-SUPERVISOR-FOUNDATION | TASK-0026 control plane + Supervisor-only sender schema foundation | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0026` | squash | merge latest main before resume |
| 10 | WS-0026-DOMAIN-AGGREGATES | Sender-domain/identity aggregates and lifecycle/purpose context | `occupied` | `worker-task0026-domain` | `active` | `worker-1/TASK-0026` | squash | merge latest main before resume |
| 20 | WS-0026-AUTH-EVIDENCE | Separate versioned SPF/DKIM/DMARC/alignment/DNS/TLS evidence | `occupied` | `worker-task0026-auth-evidence` | `active` | `worker-2/TASK-0026` | squash | merge latest main before resume |
| 30 | WS-0026-PROVIDER-POLICY | Effective-dated provider-versioned mailbox policy | `occupied` | `worker-task0026-provider-policy` | `active` | `worker-3/TASK-0026` | squash | merge latest main before resume |
| 40 | WS-0026-PERSISTENCE | Workspace-isolated sender repositories and evidence persistence | `occupied` | `worker-task0026-persistence` | `active` | `worker-4/TASK-0026` | squash | merge latest main before resume |
| 50 | WS-0026-VERIFICATION | Idempotent verification with timeout/ambiguous outcome and explicit activation gate | `occupied` | `worker-task0026-verification` | `active` | `worker-5/TASK-0026` | squash | merge latest main before resume |
| 60 | WS-0026-SYNCHRONIZATION | Replay-safe synchronization/reconciliation without implicit activation | `occupied` | `worker-task0026-sync` | `active` | `worker-6/TASK-0026` | squash | merge latest main before resume |
| 70 | WS-0026-SECURITY-ADVERSARIAL | Cross-workspace, secret-leakage and fail-closed adversarial tests | `occupied` | `worker-task0026-security` | `active` | `worker-7/TASK-0026` | squash | merge latest main before resume |
| 80 | WS-0026-DOMAIN-TESTS | Lifecycle/authentication evidence deterministic unit coverage | `occupied` | `worker-task0026-domain-tests` | `active` | `worker-8/TASK-0026` | squash | merge latest main before resume |
| 90 | WS-0026-INTEGRATION-TESTS | Persistence/idempotency/replay/ambiguous-outcome integration coverage | `occupied` | `worker-task0026-integration-tests` | `active` | `worker-9/TASK-0026` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave 0 — Supervisor foundation:** merge the control-plane reconciliation and sender schema only after parallel validation and exact-head CI are green.
2. **Wave 1 — independent contracts:** merge domain aggregates, authentication evidence and provider-policy data after each branch merges latest main and passes its own tests.
3. **Wave 2 — persistence:** merge the repository layer after Wave 1 contracts are present on main.
4. **Wave 3 — verification:** merge deterministic verification after domain/auth/policy dependencies are present.
5. **Wave 4 — synchronization:** merge replay-safe sync/reconciliation after persistence and verification are present.
6. **Wave 5 — adversarial acceptance:** merge security, unit and integration coverage after their dependencies are present; no test may weaken fail-closed semantics to pass.
7. **Final acceptance:** require AI continuity, architecture, application, security/supply-chain, parallel validation and exact-head acceptance before any TASK-0027 activation.

## Exact next action

Merge this Supervisor foundation after green exact-head checks, fast-forward all nine worker branches to the resulting `main`, then implement/submit Wave 1 contracts in parallel. Do not perform live DNS mutation, use production credentials, activate a sender, create consent, or bypass suppression/objection controls.
