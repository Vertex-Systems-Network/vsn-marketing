# ADR-0005 — Enterprise federation and provisioning boundary

Status: **PROPOSED**
Date: 2026-10-13

## Context

VSN currently authenticates first-party sessions and authorizes workspace membership and roles from its own database. Enterprise federation and SCIM introduce external identities, lifecycle signals and potentially stale sessions. An IdP assertion cannot be allowed to confer local owner, billing or provider authority simply because it contains a group name or email address. The first offline policy gates are intentionally not wired to public routes.

## Proposed decision

1. Keep authentication proof, tenant binding, provisioning lifecycle and local authorization separate. A configured, workspace-bound provider adapter verifies signature/key, algorithm, issuer, audience, nonce or replay, timestamps and subject before invoking local admission.
2. Link external subject to a local user through an explicit workspace/provider mapping. Never infer identity solely from mutable email or organization domain; ambiguous matches require human review.
3. SCIM credentials are workspace scoped and action limited. Deactivation must immediately remove workspace access at the tenant resolver and permission layer, while local sessions at other workspaces follow their own membership. Define a durable revision or revocation mechanism before claiming session termination across transports.
4. Provisioning cannot grant owner, billing, provider-credential or other privileged permissions by IdP group text. Administrative elevation remains an independently audited VSN action.
5. Keep the narrow SCIM deactivation adapter disabled by default. It accepts only a workspace-bound hashed credential and `active=false`; do not activate or describe it as general SCIM support until credential issuance/rotation, provider interoperability, concurrency and operational controls are verified. Expose no live SSO endpoint until a selected cryptographic adapter, key custody, nonce/replay store, audit, concurrency and provider interoperability tests are in place.

## Alternatives and consequences

- Mapping all IdP groups directly to local roles is rejected because group synchronization or tenant confusion could elevate privileges.
- Matching user identities by email alone is rejected because emails change, can be re-used and can collide across tenants.
- Deleting the global user on one workspace's SCIM deactivate is rejected because a person can belong to other workspaces.
- Merely deleting a browser cookie is inadequate for server-side or concurrent sessions. Session revocation requires a durable policy checked on every protected request.

This proposal changes no live authentication path. The local default-disabled SCIM deactivation route is a partial adapter, not a production activation decision. It requires a reviewed persistence and migration safety plan before user-to-IdP mapping or session revision is implemented.
