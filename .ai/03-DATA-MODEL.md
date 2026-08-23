# Canonical Data Model

## Tenant hierarchy

Organization → Workspace → Brand.

All tenant-owned records carry a workspace boundary; brand-scoped records also carry brand identity where applicable.

## Core entities

- User, Role, Permission
- Organization, Workspace, Brand
- Contact, ContactIdentity, Company
- ConsentRecord, SuppressionRecord
- List, Tag, Segment, SegmentDefinition
- Event, EventType
- Provider, ProviderConnection, ProviderCapability, ProviderQuota
- SenderDomain, SenderIdentity
- CanonicalMessage, DeliveryAttempt, DeliveryEvent
- Template, TemplateVersion, TemplateComponent
- Campaign, CampaignSnapshot, CampaignRecipient
- Journey, JourneyVersion, JourneyEnrollment, JourneyNodeExecution
- Experiment, Variant, Assignment, Outcome
- AIExecution, AIProposal, AIApproval, PromptVersion
- AuditEvent

## Data invariants

- External provider IDs are references, never primary domain identity.
- Consent history is append-oriented and evidence-preserving.
- Suppressions apply before routing.
- Campaign/journey sends use immutable snapshots of material execution inputs.
- Delivery attempts have idempotency keys.
- Webhook raw payloads may be retained securely for debugging, but canonical events drive business behavior.
- AI output that influences execution is versioned/audited with model, prompt, inputs/references, structured result, and approval outcome where allowed.
