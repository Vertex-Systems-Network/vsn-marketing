# Master Roadmap

Phases are ordered. A later phase cannot become active until prior phase exit gates are satisfied or an approved ADR explicitly changes sequencing.

| Phase | Weight | Purpose |
|---|---:|---|
| PHASE-00 | 4 | Constitution, architecture, AI continuity, stack decision |
| PHASE-01 | 7 | Platform foundation: auth, tenancy, RBAC, audit, secrets, queues |
| PHASE-02 | 7 | Customer data core: contacts, identity, lists, consent, events |
| PHASE-03 | 7 | Provider framework and initial adapters |
| PHASE-04 | 7 | Delivery engine: queue, throttle, idempotency, retry, routing, failover |
| PHASE-05 | 6 | Domains, sender identity, suppression, deliverability controls |
| PHASE-06 | 6 | Canonical template/content studio and provider template sync |
| PHASE-07 | 7 | Campaign engine, approvals, scheduling, snapshots |
| PHASE-08 | 5 | Segmentation and natural-language-to-validated-segment compiler |
| PHASE-09 | 7 | Journey/automation engine |
| PHASE-10 | 8 | AI gateway and specialized marketing agents |
| PHASE-11 | 5 | Experimentation and adaptive optimization |
| PHASE-12 | 6 | Analytics, funnels, attribution, revenue/LTV |
| PHASE-13 | 5 | Omnichannel adapters: SMS, WhatsApp, push, etc. |
| PHASE-14 | 5 | AI Connector Factory |
| PHASE-15 | 4 | Bounded autonomous marketing loops |
| PHASE-16 | 4 | Enterprise: SSO, SCIM, governance, residency, white-label |

Weights sum to 100 and are used for roadmap-level progress only after each phase publishes measurable exit criteria. Future phase task breakdowns are created before the phase is activated, not guessed ad hoc during implementation.

## Sequence principle

Foundation → Data → Provider Abstraction → Delivery → Deliverability → Templates → Campaigns → Segmentation → Journeys → AI → Optimization → Analytics → Omnichannel → Connector Factory → Autonomous Marketing → Enterprise.
