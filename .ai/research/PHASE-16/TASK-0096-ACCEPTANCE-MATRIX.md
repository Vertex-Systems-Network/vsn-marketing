# TASK-0096 acceptance matrix

Status: implementation carrier. Region policy is EU-only per ADR-0006. No regulatory compliance certification is claimed; the controls are tenant-scoped, auditable and fail closed.

| Criterion | Implementation | Evidence |
| --- | --- | --- |
| AC-1 data map across database, queue, files, logs, backups and AI/provider egress with fail-closed routing | `config/residency.php` declares all eight surfaces from `.ai/research/PHASE-16/WORKSPACE-DATA-LOCATION-INVENTORY.md`; unset/non-EU values are denied by `RegionalDataAdmission`; `DataResidencyMap::map/assertRoutable` | `DataSubjectRightsTest`: maps every surface; unset AI egress, US backups, US provider egress, unknown surface and non-supported workspace region are denied |
| AC-2 export, deletion, retention and legal-hold precedence tenant scoped and auditable | `DataSubjectRights` (`privacy.manage` permission): export, all-or-nothing anonymizing erasure, per-workspace event retention, workspace/contact legal holds; `data_subject_requests` ledger and `privacy.*` audit events | export/erasure/hold/retention tests assert workspace scoping, request ledger and audit actions |
| AC-3 cross-region, partial-deletion and hold-conflict adverse tests without unsupported claims | Erasure reports retained append-only evidence (consent, suppression, preference, immutable delivery snapshots) instead of claiming deletion | cross-region export denial; simulated mid-erasure storage failure rolls back fully; hold blocks erasure and retention until released; foreign-workspace contacts untouched |

Residency is enforced by configuration attestation; an operator must set each `RESIDENCY_*_REGION` to `eu` only when the real deployment surface is in the EU.
