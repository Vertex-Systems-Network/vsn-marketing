# Canonical Module Registry

Module names are stable architecture vocabulary. Renaming, merging, or splitting requires an ADR.

1. Core
2. Identity
3. Tenancy
4. RBAC
5. Contacts
6. Consent
7. Segments
8. Events
9. Providers
10. Connectors
11. Domains
12. Delivery
13. Suppressions
14. Templates
15. Content
16. Campaigns
17. Journeys
18. Experiments
19. AI
20. Deliverability
21. Analytics
22. Attribution
23. Webhooks
24. Integrations
25. Billing
26. Notifications
27. Audit
28. Security
29. Settings
30. Connector Factory
31. Assets
32. Publishing
33. Community

## Accepted boundary additions

`Assets`, `Publishing`, and `Community` were accepted by ADR-0002 under TASK-0013 after current provider/social/market research. Appending them preserves every pre-existing module identifier and does not authorize their implementation ahead of their planned phases.

- `Assets` owns canonical media/creative assets, variants, metadata, provenance, rights, and transformations. `Content` owns communicative content/template semantics; it may reference assets but does not own binary/media lifecycle.
- `Publishing` owns channel-neutral publication targets, schedules, attempts, status reconciliation, and supported edit/delete/cancel lifecycle. `Campaigns` owns campaign orchestration/snapshots/approvals and uses Publishing for external publication execution.
- `Community` owns normalized comments, mentions, conversations/inbox workflows, moderation/response proposals, and listening/community signals where providers permit them. `Notifications` remains first-party/system notification behavior rather than external social/community conversation state.

## Dependency direction

Domain modules may depend on Core contracts, not concrete infrastructure. Connectors implement contracts. Delivery orchestrates provider-neutral message sending. Publishing orchestrates channel-neutral publication lifecycle through connector capabilities. Community normalizes provider community surfaces through connector capabilities. AI calls application tools/contracts rather than reaching directly into provider SDKs or database tables.

Provider/network-specific behavior remains behind `Connectors`; adding a new provider must not require a new canonical module merely to name that provider.
