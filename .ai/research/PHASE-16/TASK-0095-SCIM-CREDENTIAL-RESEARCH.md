# TASK-0095 — Workspace provisioning credential lifecycle

Date: 2026-10-14. Sources: SCIM protocol security considerations https://www.rfc-editor.org/rfc/rfc7644 ; OWASP Secrets Management Cheat Sheet https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html .

Decision: workspace administrators with `identity.manage` may issue a short-lived random credential, replace it atomically, and revoke it. Only a SHA-256 digest persists; plaintext is returned once with `no-store`, never in audit. Rotation immediately invalidates the predecessor and serializes against concurrent workspace mutations. The SCIM deactivation adapter remains default-disabled and action-limited; credential issuance does not activate provider support. Classification: TASK-0095 prerequisite, not complete SCIM interoperability or production activation.
