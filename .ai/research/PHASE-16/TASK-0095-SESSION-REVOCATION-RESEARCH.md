# TASK-0095 — workspace session revocation research (2026-10-14)

## Sources checked

- NIST SP 800-63B-4, Session Management: https://pages.nist.gov/800-63-4/sp800-63b.html. A session is associated with an authentication event and terminates separately; a presented session secret alone should not preserve authority after an explicit workspace revocation.
- OWASP Session Management Cheat Sheet, Session Expiration and Reauthentication After Risk Events: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html. Enforcement belongs on the server; browser logout alone does not cover concurrent sessions.
- Existing ADR-0005, TASK-0094 research pack and identity source/tenant middleware. ADR-0005 explicitly requires a durable revocation mechanism before claiming session termination across transports.

## Decision for bounded slice

Maintain a monotonic login generation per user and a latest revoked generation per workspace/user. Successful first-party login increments under a locked user row and stores the issued generation in the server-side session. Deactivation and independently authorized admin revocation serialize on the same row, store the current generation, and audit within a database transaction. Tenant middleware denies that workspace when the session has no generation or its generation is not greater than the threshold. Other workspaces remain accessible; a later login gets a greater generation. Directory links reflect the same check. Existing sessions without a revision are admitted only for workspaces with no recorded revocation.

The generation is not a substitute for the framework's normal session lifetime, CSRF, logout or provider-bound token validation. Concurrent HTTP requests already authorized before the transaction commits may complete; subsequent tenant route requests see the revocation. API bearer tokens and live SSO are outside this first-party session boundary.

Classification: TASK-0095 AC-2 implementation slice. ADR-0005 remains PROPOSED. PostgreSQL concurrency, fail-closed restoration and protected exact-head gates remain required acceptance evidence.
