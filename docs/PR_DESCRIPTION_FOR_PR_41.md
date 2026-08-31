Suggested PR description for PR #41 — TASK-0016: Connector contracts

Summary

This PR implements TASK-0016: provider-neutral connector contracts including:

- Connector manifest and capability metadata with explicit unknown/fail-closed behavior
- Stable normalized provider error categories with provider-specific evidence retained
- Multi-scope/window/unit runtime quota signals without hard-coded volatile limits
- Raw-body-preserving webhook verification strategy contracts with replay/deduplication guard
- Asynchronous provider-operation state/reconciliation with idempotency keys and terminal-state protection
- API/version/deprecation/documentation/sandbox provenance metadata
- TASK-0016 research evidence from AWS, Brevo, Gmail, and Google Cloud Pub/Sub documentation

Files changed (high level)

- app/Modules/Providers/Domain/Connectors/* — domain value objects for connector manifests, capabilities, webhook requests, and error evidence
- tests/Feature/Providers/ConnectorContractsTest.php — feature tests for connector contracts
- docs/TASK-0016_CI_SUMMARY.md — CI summary and next steps

CI Status (as of latest commit)

- Application Foundation CI: passing (non-infra checks)
- Security Supply Chain CI: passing
- AI Continuity Guard: passing

Note about infra-gated tests

Several integration and certification tests are intentionally gated behind RUN_INFRA_INTEGRATION=true and require service fixtures (Postgres, message buses, external provider test accounts/secrets). These are skipped in the current PR CI matrix to avoid requiring production-like infrastructure for a code review. To run them in CI, configure the appropriate secrets and set RUN_INFRA_INTEGRATION=true in the workflow matrix.

Behavioral vs. style changes

- The commits in this branch primarily apply project-style formatting (pint) and small, low-risk stylistic adjustments (single-line empty constructors, anonymous-class brace style) to satisfy automated style checks. No functional behavior changes were made beyond the connector contract implementations in scope for TASK-0016.

Testing notes

- Unit/feature tests: 50 passed, 26 skipped (infra-gated)
- Lint/style (pint) issues fixed; formatting committed

Suggested reviewers

- @wpessential (author)
- Core team members responsible for provider adapters and integration tests

Next steps (pick from below)

- Merge: If you are satisfied with the changes and accept that infra-gated tests remain skipped until CI is configured, merge the branch.
- Provider adapter: I can implement a provider-specific verifier/normalizer (e.g., Stripe, SendGrid) next — specify provider.
- Run infra tests: If you want the infra-gated tests run in CI, provide the required secrets and I can re-run CI with RUN_INFRA_INTEGRATION=true.

Action requested

- Copy this suggested PR description into PR #41, or tell me to post it and I will (I currently cannot update the PR body directly without your explicit instruction to operate on the PR). Alternatively, say “Merge” and I’ll prepare the final checklist and mark PR ready for merge.

Timestamp: 2026-08-31T22:18:00Z (UTC)
