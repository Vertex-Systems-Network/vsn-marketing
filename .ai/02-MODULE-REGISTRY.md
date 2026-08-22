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

## Dependency direction

Domain modules may depend on Core contracts, not concrete infrastructure. Connectors implement contracts. Delivery orchestrates provider-neutral message sending. AI calls application tools/contracts rather than reaching directly into provider SDKs or database tables.
