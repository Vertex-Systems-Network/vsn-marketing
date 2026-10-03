# PHASE-12 — Analytics, Funnels, Attribution, Revenue, and LTV

Status: researched task sequence staged. Weight: 6% of roadmap. No production effectiveness or scale claim follows from registration.

1. TASK-0068 — dated official analytics, privacy and database research.
2. TASK-0069 — canonical fact lineage, authorization, privacy, dedupe, aggregation and freshness.
3. TASK-0070 — ordered funnels, cohorts, lifecycle, content/channel performance and retention.
4. TASK-0071 — versioned attribution, integer revenue/refunds, observed LTV and incrementality-ready references.
5. TASK-0072 — accessible operator dashboard, durable scheduled snapshots, anomaly and AI explanation validation.
6. TASK-0073 — quality monitors and source reconciliation with unknown totals disclosed.
7. TASK-0074 — metric/privacy/reconciliation/PostgreSQL/scale/SLO/browser/evaluation certification.

PostgreSQL remains canonical. ClickHouse/OpenSearch require measured need and ADR. Event-time UTC ordering and receipt-time freshness are separate. Identity changes never silently rewrite historical cohorts. Reports disclose definition/version, source lineage, watermark, model, incomplete horizon, unknown totals and estimation. Rule-based attribution does not establish causal incrementality. Existing customer-event data does not automatically authorize new tracking or third-party sharing.
