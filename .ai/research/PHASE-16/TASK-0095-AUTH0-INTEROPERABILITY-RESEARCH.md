# TASK-0095 — Auth0 development tenant interoperability

Date: 2026-10-15. Scope: non-production Regular Web Application selected by the operator. No credentials, tenant identifiers, or user records are stored here.

Auth0's authorization-code flow redirects to an exactly registered application callback and exchanges the code server-side. A confidential web application authenticates the exchange. The transaction must bind a one-use state and nonce to the initiating browser session; PKCE binds its code verifier to the authorization request. The ID token must be checked for issuer, audience, time, nonce, signature and trusted signing key before the existing workspace subject binding can admit it. No email/domain/group claim grants membership or privilege.

Auth0 publishes RS256 signing public keys at its tenant JWKS endpoint and can rotate them. Its tokens contain a key ID. The existing offline verifier rejects every `kid`, so a narrowly pinned server-selected key ID and PEM pair is a prerequisite. A future live adapter needs bounded JWKS caching, strict issuer host allowlisting, key selection from the trusted tenant endpoint only, rotation handling and fail-closed refresh. No token-selected URL is trusted.

The operator's application currently has empty Application URI fields. There is no VSN callback route or deployed origin yet, so no callback URL can be registered accurately and no live interoperability or subject-linking acceptance can be claimed. Keep live SSO disabled while implementing and testing the route and approved binding workflow. The tenant/application establishes a test candidate but does not resolve the missing authorized test identity, binding approval, deployment, key custody and full SCIM interoperability.

Classification: TASK-0095 prerequisite and acceptance work. ADR-0005 remains PROPOSED. The pin-compatible verifier change is an offline slice, not provider certification or activation.

Implementation contract: VSN exposes `/auth/enterprise/{workspace}/login` and `/auth/enterprise/{workspace}/callback` behind `ENTERPRISE_OIDC_ENABLED=false`. The configured redirect URI must equal the route's absolute HTTPS URL, including the selected workspace UUID, at the actual application origin. The browser transaction stores a one-use state, nonce and PKCE verifier in its server session for at most five minutes; the callback exchanges a code with the server-side client secret, verifies the pinned RS256 key ID and PEM, consumes the signed assertion once, and admits only an explicitly bound active member. Configuration of a fixed PEM means key rotation requires an operator-managed update; this is not automatic JWKS rotation or provider interoperability certification. Secrets remain in an environment secret store, not Auth0 screenshots, source or research documents.

Official sources (retrieved 2026-10-15):

- https://auth0.com/docs/get-started/authentication-and-authorization-flow/authorization-code-flow/add-login-auth-code-flow
- https://auth0.com/docs/secure/tokens/id-tokens
- https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets
- https://auth0.com/docs/get-started/tenant-settings/signing-keys/rotate-signing-keys

Security gate follow-up (2026-10-15): exact-head dependency audit identified Symfony http-foundation CVE-2026-108082 and yaml CVE-2026-109413 in locked 7.4.17. The CI Composer resolver selected 7.4.21 for both. The lock records only these two package updates, whose require constraints remain compatible with the existing polyfill versions. The diagnostic CI workflow change was removed. Protected exact-head dependency audit, Composer install and application tests remain the acceptance evidence for this lock repair.
