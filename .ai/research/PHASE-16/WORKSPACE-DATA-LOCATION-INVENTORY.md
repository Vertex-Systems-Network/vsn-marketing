# Phase 16 workspace data location inventory — 2026-10-13

Status: **SOURCE INVENTORY / NO RESIDENCY CLAIM**. Configuration defaults describe code behavior, not where any deployed copy, backup, log or third party actually resides.

| Surface | Current repository source | Location evidence still needed |
| --- | --- | --- |
| Canonical database | `config/database.php`: PostgreSQL default, host/port/name from environment; SQLite in local tests | Actual managed database region, replicas, snapshots, WAL, support access and restore location |
| Queues and cache | `config/queue.php`: Redis default; `config/database.php`: Redis connections | Primary/replica regions, failed jobs, dead letters, persistence, failover and backup copies |
| Files and media | `config/filesystems.php`: S3 default and configurable endpoint/region; local disks also defined | Real bucket/object region, replication, CDN cache, temporary upload and deletion behavior |
| Application and infrastructure logs | `config/logging.php`: stack/single and other channels | Log collector, retention, regional forwarding, access and incident copies |
| Backups | No production tenant-separated backup/restore adapter in Phase 16 | Storage region, encryption key custody, replication, isolation and measured restore evidence |
| Analytics and reports | Workspace-scoped canonical tables and generated report flows under `app/Modules/Analytics` | Materialized exports, scheduled report copies, delivery destinations and retention |
| AI | `AiRoutePolicy` checks server-owned route `data_regions`; proposal adapters are offline by default | Chosen model provider processing, telemetry, prompt retention, egress region and subprocessors |
| External delivery/provider | Provider connection has optional region; connector catalog contains provider-specific capabilities | Per-provider account region, webhooks, logs, suppression data, failover and downstream copies |

An approved workspace region must cover **all eight surfaces** for a claim that every copy stays in-region. `RegionalDataAdmission` denies unknown/mismatched destinations and can represent AI/provider egress as disabled. No supported-region list, approved processing map or real environment attestations exist yet. Do not activate regional routing, delete data or promise residency from the default `AWS_DEFAULT_REGION` string. The same inventory is needed for a complete data-subject export/erasure plan.
