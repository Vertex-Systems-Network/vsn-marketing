# TASK-0095 — Workspace provisioning credential lifecycle

Date: 2026-10-14. Sources: SCIM protocol security considerations https://www.rfc-editor.org/rfc/rfc7644 ; OWASP Secrets Management Cheat Sheet https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html .

Decision: workspace administrators with `identity.manage` may issue a short-lived random credential, replace it atomically, and revoke it. Only a SHA-256 digest persists; plaintext is returned once with `no-store`, never in audit. Rotation immediately invalidates the predecessor and serializes against concurrent workspace mutations. The SCIM deactivation adapter remains default-disabled and action-limited; credential issuance does not activate provider support. Classification: TASK-0095 prerequisite, not complete SCIM interoperability or production activation.

2026-10-15 revalidation: RFC 7644 Section 7.4 requires bounded bearer lifetime and treats possession as authority to change resources. The deactivation endpoint now takes the same workspace row lock as credential rotation/revocation and rechecks the enabled, unexpired digest inside the mutation transaction. A request admitted before revocation may finish before the revoke commits; a request that acquires the lock afterward must be denied. The initial credential check remains before parsing so unauthenticated callers do not receive operation feedback. This is local concurrency hardening, not provider interoperability evidence.

The deactivation transaction also removes that member's workspace federation subject bindings. A later local membership re-add therefore requires a fresh, explicit subject link before a signed assertion can be admitted. The subject link in a different workspace remains intact.
