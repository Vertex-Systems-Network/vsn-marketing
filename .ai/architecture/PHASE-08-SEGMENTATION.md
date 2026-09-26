# PHASE-08 Segmentation Architecture

Status: TASK-0044 AST/compiler accepted; TASK-0045 provider-neutral proposal boundary active, 2026-09-26.

## Runtime boundary

An authenticated request resolves TenantContext from membership middleware. SegmentDefinition is a versioned, canonical document with no organization/workspace field. The compiler receives TenantContext as a separate required argument and injects workspace equality into the contacts base scan and each registered relation. Browser or model payloads never provide scope.

Natural-language proposals are untrusted structured AST. The same validator and field/event registries apply to typed rules and AI proposals. The model cannot select SQL, identifiers, joins, event properties, functions, or permissions.

## Canonical AST v1

The envelope is schema_version=1, subject=contact, and root. Nodes are group (all|any), not, attribute (registered field/type/operator/value), membership (workspace-owned list/tag id), and event (registered canonical event name, mode and bounded window).

Empty groups, unknown keys, unknown nodes, coercion, null-as-value, raw expressions, JSON paths, arbitrary sort/group, regex, and free-form event properties are rejected. Null is explicit via is_set and is_not_set. Attribute operators are type-matched. Membership is represented by canonical list/tag IDs. Event windows are bounded, half-open and UTC normalized.

Groups are normalized recursively. Commutative children sort by canonical JSON; serialization uses sorted object keys, unescaped Unicode/slashes, and a stable SHA-256 definition hash. The evaluation instant is a required compiler input and is included in the evaluation fingerprint.

## Server-owned registries

The field registry maps stable field IDs to static type and operator metadata. Current fields are contact.created_at, company.name, and company.domain. Personal identity, consent evidence, suppression, raw payload and hidden attributes are not targetable. Event names are registered per workspace in event_types; there are no segmentable event-property paths until a reviewed schema registry exists. Contact/list/tag/event relation names and join keys are code-owned constants.

## Query compilation

The compiler produces a Laravel query builder for distinct contact IDs. It binds values. Tenant scope is independently injected from TenantContext. Event and membership registries are workspace-owned and validated before a safe plan can be accepted.

## Versioning and authorization

A segment definition belongs to exactly one workspace. Each saved version is immutable, numbered monotonically, and stores canonical AST plus hash. Draft edits append a version; published versions cannot be overwritten. Evaluations refer to a pinned definition version/hash and evaluation instant. Send eligibility, consent and suppression remain separate canonical gates at the sending boundary. PHASE-09 execution is excluded.

Operator routes require authenticated membership plus contact.read. Save requires contact.write. AI proposals require ai.execute and contact.read. All operations use the resolved TenantContext and actor from middleware, never request-supplied tenant or author IDs. Preview responses exclude identity fields by default.

## TASK-0045 proposal contract

The model-facing provider port accepts only the operator's bounded intent and an allowlisted schema description: stable field IDs/types/operators and canonical event names for the authenticated workspace. It receives no workspace id, tenant id, customer rows, customer identifiers, event payloads, consent/suppression evidence, list/tag IDs, provider credentials, or SQL metadata. AI list/tag predicates remain unsupported until a server-owned name-to-ID picker is available.

Provider responses are proposals only. Schema normalization, current workspace event validation, permission checks and cost validation run after the provider call. Ambiguous requests remain clarification-only; invalid or hallucinated fields/events fail closed. Obvious email and credential literals are rejected before dispatch and also rejected if returned inside a proposed text value. The default provider binding is unavailable, has no retry, and never calls an external provider.

Operator review exposes the canonical structured proposal in an editable JSON editor; saving requires contact.read + contact.write and an explicit confirmation flag. Audit events store only the intent SHA-256, safe outcome code, canonical definition hash and saved segment/version identity. Prompts, raw values and customer data are never written to audit evidence.

## Threat controls

- SQL/identifier injection: closed AST, static identifier maps, bound values.
- Cross-workspace reads/joins/counts: independent tenant context and workspace equality at every relation.
- Sensitive targeting: absent from the server registry by default.
- DoS/AST bombs: bounded input length, recursion, nodes, event window and database timeout.
- Hallucinated event/field: static field allowlist and current workspace event registry.
- Version confusion: definition hash and immutable versions.
- Logging leakage: audit fingerprints and safe reason codes, never raw prompts, values or customer rows.
