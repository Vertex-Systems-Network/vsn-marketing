# ADR-0006 — Phase 16 owner decisions for AI-only execution

Status: **ACCEPTED**
Date: 2026-10-16
Decision owner: repository owner (recorded in the 2026-10-16 Claude Code session, `marketing@pellaglobal.net`)

## Context

The repository owner stated that all Phase 16 work is performed by AI with no human developer, operator or reviewer available. Phase 16 recorded human-authority prerequisites in `.ai/state/BLOCKERS.md`: live Auth0 interoperability with a locally held secret (TASK-0095), approved processing regions (TASK-0096), billing provider and financial authority (TASK-0098), and an isolated restore environment with human-approved RPO/RTO (TASK-0099). The owner answered each decision explicitly.

## Decision

1. **TASK-0095 interoperability evidence.** Live third-party IdP exchange is replaced by an automated, in-process local OIDC identity provider exercising the real authorization-code + PKCE route (`tests/Feature/Identity/EnterpriseOidcLocalIdpInteropTest.php`). Enterprise SSO and SCIM remain disabled by default; connecting a real provider in production is a later configuration activity, not a Phase 16 acceptance input.
2. **TASK-0096 residency.** Customer data is stored and processed in the **EU only**. Any other region is unsupported and must fail closed until added by a later ADR.
3. **TASK-0098 billing.** Build deterministic billing/entitlements against **Stripe in test mode only**. Live charges stay disabled; enabling live mode is a separate future owner action.
4. **TASK-0099 recovery objectives.** **RPO 24 hours / RTO 4 hours.** Restore is exercised and measured automatically in the repository's CI/local container environment.

## Consequences

- The corresponding BLOCKERS entries are superseded for acceptance purposes; no secret, live payment, or production activation is implied.
- Acceptance criteria are not weakened: each task still requires adversarial tests, accessible operator flows and exact-head protected gates. Only the evidence source for provider/environment inputs is defined here.
- Any future production activation (real IdP, non-EU region, Stripe live mode, production backup target) requires a new owner decision.
