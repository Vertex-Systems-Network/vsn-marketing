# Identity foundation audit — 2026-10-14

TASK-0095 remains in progress. The current carrier is a narrow first-party entry and governance foundation. It does not enable live SSO, general SCIM, privilege synchronization from an IdP, production provisioning credential issuance, or session revocation across transports.

## Findings and changes

- The existing POST `/auth/login` and logout had no browser entry. An accessible sign-in page now submits the first-party credentials with the server CSRF token, displays throttling and validation errors, and navigates to a membership-derived workspace directory.
- Workspace directory rows are bounded to 25 per page and read only the authenticated user's live memberships. Module links come only from current local role grants in the same workspace; the protected routes recheck tenant membership and permission.
- Audit metadata export is workspace scoped, permission gated and paginated. Raw event evidence is excluded.
- Role assignments require a separate administrator approval, a current role permission fingerprint, and a one-use apply action. The actor must hold every delegated permission.
- Federation assertion admission is an offline post-verification boundary, with explicit workspace issuer/subject binding and database replay protection. It accepts a trusted `signatureVerified` flag from a future verifier; no public token endpoint or login is wired.
- The narrow SCIM PATCH `active=false` endpoint is disabled by default. When explicitly enabled, it requires a workspace-bound hashed bearer credential, valid expiry and exactly one supported operation. Malformed JSON and unsupported shapes return a bounded SCIM 400. No general SCIM compatibility is claimed.

## Migration review

The two additive migrations create only new tables: federation bindings/replays/role proposals and workspace provisioning credentials. They do not rewrite existing rows. New unique keys make replay and credential hashes one-use and deterministic under retries. Schema creation occurs through the framework migration ledger, which marks a completed migration; a failed partial DDL on a database without transactional DDL requires inspection and restoring the pre-migration snapshot before retry. The `down` methods drop these new tables and their recorded data, so rollback requires a backup/snapshot and explicit operator decision. Existing production backup, restore and concurrent migration behavior have not been exercised in this local workspace. The PostgreSQL migration/integration gate on the exact PR head is required before merge.

## Remaining TASK-0095 acceptance work

- Pin and test a cryptographic OIDC verifier with trusted configuration, state/nonce, key rotation and provider interoperability before live federation.
- Define durable per-workspace session revision and administrative revoke controls; membership removal blocks tenant routes but does not terminate the global cookie.
- Add credential issuance/rotation and SCIM provider interoperability, concurrency and operational controls before enabling the adapter.
- Exercise browser keyboard and assistive flow, PostgreSQL concurrency, backup/restore and the protected exact-head gates. Keep ADR-0005 PROPOSED until reviewed.
