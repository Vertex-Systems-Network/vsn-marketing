# TASK-0090 Human-Authenticated Offline Approval Recorder

Status: implementation staged; not CI certified or deployed.

## Trust chain

1. A caller must be a **real authenticated user**. The service enforces `Auth::id() === approver.id`, rather than accepting an AI-supplied user identity.
2. The approver must differ from the original run/requesting actor.
3. The approver's *current* workspace membership must independently grant `ai.approve`. The AI cannot add a role, create a user, set a permission or grant itself approval.
4. The submitted preview must already be tenant-bound, offline-only and execution-disabled. The audience, content, destination, cost, volume, validity and snapshot fingerprints must pass a strictly bounded schema.
5. Each approved, rejected or revoked decision is inserted as a fresh row with a database sequence and server-generated decision ID. There is no UPDATE or DELETE method. Revocation requires an existing decision.
6. The independent read source resolves only the latest decision and rechecks the role and exact immutable binding. Prior approvals never silently resurrect after revocation or material changes.
7. These records authorize **only offline operator evidence**; they do not enable external execution, sending, billing, payment, publishing, promotion or connector activation.

## Tests

- Human session versus claimed user identity.
- Missing `ai.approve` permission and same-actor approval refusal.
- Resource cost bounds and malformed outcomes.
- Append-only approval then latest revocation.
- Independently read back the recorded decision, followed by revocation or role removal.

## Remaining task scope

A future controller must still perform CSRF, authenticated user resolution, report/snapshot lookups, form permission checks and audit logging before exposing this to operators. Final stop-aware last-side-effect authorization and unknown real-provider outcome reconciliation remain outside this staging slice.
