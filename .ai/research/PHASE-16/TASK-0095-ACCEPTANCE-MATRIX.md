# TASK-0095 acceptance matrix

Status: ACCEPTED under ADR-0006 (owner decision 2026-10-16). Provider interoperability evidence is the automated local OIDC IdP exercise; SSO/SCIM remain disabled by default.

| Criterion | Evidence |
| --- | --- |
| AC-1 federation/provisioning reject replay, cross-tenant confusion, unauthorized privilege change | `FederationAssertionConsumptionTest`, `EnterpriseOidcCallbackTest`, `EnterpriseOidcLocalIdpInteropTest` (replay, foreign key/kid, nonce, issuer, audience, expiry, foreign-tenant and unbound subject), `PinnedOidcTokenVerifierTest`, `FederationClaimAdmissionTest`, `ScimProvisioningAdmissionTest`, `ScimDeactivationEndpointTest`, `GovernedSubjectBindingTest`, `GovernedRoleAssignmentTest` |
| AC-2 least-privilege role/session/admin controls with revocation and recoverable audit | `IdentityTenancyRbacTest`, `GovernedRoleAssignmentTest`, `WorkspaceSessionRevocationTest`, `WorkspaceMemberDeactivationTest`, `ProvisioningCredentialLifecycleTest`, `AuditExportSecurityTest` |
| AC-3 adversarial integration and accessible operator flows with unsupported providers disabled | Disabled-by-default assertions in `EnterpriseOidcCallbackTest`/`ScimDeactivationEndpointTest`; `e2e/enterprise-audit-operator.spec.ts`, `e2e/workspace-entry.spec.ts`; `resources/js/pages/enterprise/overview.test.tsx`, `auth/workspaces.test.tsx`, `auth/login.test.tsx`; PostgreSQL/Redis integration job |

Exact-head protected gates on the carrier PR are the final proof.
