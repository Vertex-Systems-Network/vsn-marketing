# AI-Native Parallel Plan — TASK-0028 Safe Sending Policy

Status: **active — foundation wave**. TASK-0027 suppression and objection authority is certified on protected `main` at `6593e6f6fd57adadaf80c2d84613a07b7a469a2b`. TASK-0028 now implements deterministic frequency caps plus provider-versioned reputation/deliverability-health evidence before composing the final safe-sending boundary.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0028`  
Parent task: `TASK-0028`  
Branch baseline: `6593e6f6fd57adadaf80c2d84613a07b7a469a2b`  
Active writers: `3` (1 Supervisor + 2 independent foundation workers)  
Repository hard cap: `12`  
Merge strategy: `squash`

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

1. **Foundation A — Frequency policy (`worker-1/TASK-0028`)**: domain/application contracts and focused unit tests for explicit windows, thresholds, counters, workspace/purpose/recipient scope, deterministic replay and fail-closed missing/invalid policy.
2. **Foundation B — Reputation/health (`worker-2/TASK-0028`)**: provider/source/version/effective-date evidence contracts and focused unit tests for freshness, trust, contradiction, workspace isolation and fail-closed evidence evaluation.
3. **Composition — Safe sending (`worker-3/TASK-0028`)**: starts only after both foundations merge to protected main; composes base eligibility, TASK-0027 suppression authority, sender readiness, frequency and reputation/health into stable outcomes/reasons.
4. **Certification — Adversarial/PostgreSQL (`worker-4/TASK-0028`)**: starts only after safe-sending composition lands; proves replay/concurrency, failover resistance, cross-workspace isolation, stale/contradictory evidence and suppression precedence.
5. **Final acceptance**: exact-head AI Continuity, Application Foundation and Security Supply Chain must pass before TASK-0028 acceptance or TASK-0029 activation.

## Exact next action

Land this control admission, then execute the two independent foundation workers from the certified baseline. Merge only exact-head green foundation PRs. Rebase/sync the dependent safe-sending branch to the resulting protected main before beginning composition.
