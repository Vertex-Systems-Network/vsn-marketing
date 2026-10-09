# TASK-0090 Independent Offline Approval Source

- PR #533 exact-head tests passed and merged as `1112bb913f0f093174282a8fe10d592ec2be9d2e`.
- PR #534 stages append-only decision rows and read-only latest-decision resolution by workspace+run, with live `AI_APPROVE` permission rechecked through WorkspaceAuthorizer.
- An absent decision, removed role/permission, deleted user, cross-workspace scope, self approval, latest revocation, conflicting immutable fingerprint, expired approval or missing policy fails closed.
- No API for an AI model to write approvals is introduced; decisions are test fixtures / externally originated trusted records only.
- A successful offline review is not an execution, promotion, send, spend or provider activation authorization.
- TASK-0090 remains IN_PROGRESS. Actual stop-reconciliation, last-side-effect gates, result uncertainty and final certification remain unproven.
