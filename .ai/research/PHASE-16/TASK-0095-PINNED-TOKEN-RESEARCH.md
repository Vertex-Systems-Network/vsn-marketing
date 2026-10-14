# TASK-0095 — Pinned offline ID token verification

Date: 2026-10-14. Scope: offline, server configured OIDC ID-token proof before the existing tenant-bound admission; no live SSO endpoint or discovery/JWKS fetch.

## Primary sources

- OpenID Connect Core 1.0, ID Token validation and nonce: https://openid.net/specs/openid-connect-core-1_0.html
- RFC 7519, JWT registered claims: https://www.rfc-editor.org/rfc/rfc7519
- RFC 8725, JWT algorithm/key confusion, issuer/audience and explicit typing guidance: https://www.rfc-editor.org/rfc/rfc8725

## Decision and limits

Accept only compact RS256 tokens with a trusted, preconfigured RSA public key, exact HTTPS issuer, configured client audience, and exact expected nonce. Check issued and expiry times, bounded lifetime and subject. Multi-audience tokens require matching `azp`. Reject untrusted token-selected key URLs, unsupported algorithms and ambiguous JSON. The signed compact token digest serves as the single-use replay identity at the existing persistence boundary; email and groups confer no local authority. Keep keys, nonce issuance, OIDC authorization flow, discovery, provider interoperability, key rotation and session creation out of scope until a provider adapter and operations review are completed. Classification: prerequisite implementation for TASK-0095 AC-1, not task completion.
