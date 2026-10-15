# TASK-0095 — Auth0 development tenant interoperability

Date: 2026-10-15. Scope: non-production Regular Web Application selected by the operator. No credentials, tenant identifiers, or user records are stored here.

Auth0's authorization-code flow redirects to an exactly registered application callback and exchanges the code server-side. A confidential web application authenticates the exchange. The transaction must bind a one-use state and nonce to the initiating browser session; PKCE binds its code verifier to the authorization request. The ID token must be checked for issuer, audience, time, nonce, signature and trusted signing key before the existing workspace subject binding can admit it. No email/domain/group claim grants membership or privilege.

Auth0 publishes RS256 signing public keys at its tenant JWKS endpoint and can rotate them. Its tokens contain a key ID. The existing offline verifier rejects every `kid`, so a narrowly pinned server-selected key ID and PEM pair is a prerequisite. A future live adapter needs bounded JWKS caching, strict issuer host allowlisting, key selection from the trusted tenant endpoint only, rotation handling and fail-closed refresh. No token-selected URL is trusted.

The operator's application currently has empty Application URI fields. There is no VSN callback route or deployed origin yet, so no callback URL can be registered accurately and no live interoperability or subject-linking acceptance can be claimed. Keep live SSO disabled while implementing and testing the route and approved binding workflow. The tenant/application establishes a test candidate but does not resolve the missing authorized test identity, binding approval, deployment, key custody and full SCIM interoperability.

Classification: TASK-0095 prerequisite and acceptance work. ADR-0005 remains PROPOSED. The pin-compatible verifier change is an offline slice, not provider certification or activation.

Official sources (retrieved 2026-10-15):

- https://auth0.com/docs/get-started/authentication-and-authorization-flow/authorization-code-flow/add-login-auth-code-flow
- https://auth0.com/docs/secure/tokens/id-tokens
- https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets
- https://auth0.com/docs/get-started/tenant-settings/signing-keys/rotate-signing-keys
