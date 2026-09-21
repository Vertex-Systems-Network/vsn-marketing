<?php

namespace App\Modules\Publishing\Domain\Campaign;

enum CampaignApprovalInvalidReason: string
{
    case MissingApproval = 'missing_approval';
    case ApprovalRejected = 'approval_rejected';
    case ApprovalRevoked = 'approval_revoked';
    case ApprovalExpired = 'approval_expired';
    case MaterialRevision = 'material_revision';
    case TargetSetChanged = 'target_set_changed';
    case ApproverAuthorizationRevoked = 'approver_authorization_revoked';
    case ApproverRoleRevoked = 'approver_role_revoked';
    case CapabilityMissing = 'capability_missing';
    case CapabilityUnsupported = 'capability_unsupported';
    case CapabilityStale = 'capability_stale';
    case CapabilityNotEffective = 'capability_not_effective';
    case CapabilityIncompatible = 'capability_incompatible';
    case ConnectionUnavailable = 'connection_unavailable';
    case ConnectionStale = 'connection_stale';
    case ConnectionNotEffective = 'connection_not_effective';
    case ConnectionScopeRevoked = 'connection_scope_revoked';
    case ConnectionRoleRevoked = 'connection_role_revoked';
}
