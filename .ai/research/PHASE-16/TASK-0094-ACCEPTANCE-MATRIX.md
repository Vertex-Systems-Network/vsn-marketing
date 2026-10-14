# TASK-0094 — Research acceptance matrix (local draft)

Status: **LOCAL / NOT ACCEPTED**. The task remains active. Exact-head and resulting-main gates have not run on the Phase 16 carrier.

Publication sequence boundary: the active task requires exact-head and resulting-main gates before TASK-0095 can be canonically activated. The current user instruction holds Git publication until all of Phase 16 is complete. Both conditions cannot be met in that order; retain TASK-0094 as active and do not claim completion or silently bypass the required task gate. The local identity work is an unaccepted draft until this sequence is resolved.

| Criterion | Local evidence | Remaining gate |
| --- | --- | --- |
| AC-1: current primary sources | `TASK-0094-RESEARCH.md` cites NIST SP 800-63-4, RFC 7643/7644, OIDC, OASIS SAML, W3C WCAG 2.2, GDPR, PCI SSC, NIST SP 800-34/53 and Microsoft/Okta provisioning behavior, reviewed 2026-10-13 | Confirm source claims during exact-head review; review provider-specific contracts before any adapter implementation |
| AC-2: supported claims | No supported payment processor, IdP, production region, legal determination, RPO/RTO or live activation inferred; offline-only boundary retained | Confirm no live-capable route or authority is introduced by later implementation |
| AC-3: threats and plan | Tenant confusion, untrusted IdP role mapping, replay, session staleness, cross-region copies, billing duplication and failed restore mapped to TASK-0095..0100; local Identity source inventory complete | Record task-focused adverse evidence and full exact-head/protected-main CI before marking the task complete |

Local checks: `python tools/ai_state.py validate`, `python tools/ai_journal.py validate`, `python tools/ai_policy.py`, `python tools/ai_parallel.py validate` and `python tools/supervisor_contract.py validate` pass. The earlier identity suite passes (13 tests, 76 assertions); the earlier full PHP suite passes (1,079 tests, 154 infrastructure skips, 7,280 assertions). A later portable audit-failure rollback test passes with its focused suite (3 tests, 16 assertions); the full suite has not been rerun since that addition. Focused PHPStan and Pint pass. Offline policy, scoped member removal and redacted audit metadata export are initial TASK-0095-oriented slices. They do not prove SCIM interoperability, SSO, complete session termination or enterprise production certification.
