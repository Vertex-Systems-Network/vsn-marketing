# PHASE-08 Segmentation Architecture

Status: frozen for TASK-0044 implementation, 2026-09-26.

## Runtime boundary

An authenticated request resolves TenantContext from membership middleware. SegmentDefinition is a versioned, canonical document with no organization/workspace field. The compiler receives TenantContext as a separate required argument and injects workspace equality into the contacts base scan and each registered relation. Browser or model payloads never provide scope.

Natural-language proposals will be accepted only as untrusted structured AST. The same validator and field/event registries apply to typed rules and AI proposals. The model cannot select SQL, identifiers, joins, event properties, functions, or permissions.

## Canonical AST v1

Envelope keys are exactly schema_version=1, subject=contact, and root. Nodes are:
- group: operator all|any and children
- not: one child
- attribute: registered field, typed operator and value
- membership: kind list|tag, workspace-owned id and operator in|not_in
- event: registered canonical event name, mode exists|not_exists|count|first|last, and bounded window

Empty groups, unknown keys, unknown nodes, coercion, null-as-value, raw expressions, JSON paths, arbitrary sort/group, regex, and free-form event properties are rejected. Null is explicit via is_set and is_not_set. Attribute operators are type-matched: text supports equals/not_equals; timestamp supports before/after/on_or_before/on_or_after and is_set/is_not_set. Membership is represented by canonical list/tag IDs. Event count uses a bounded minimum count; first/last mean earliest/latest workspace-scoped event of that registered type, tested against the requested half-open UTC interval.

Groups are normalized recursively. Commutative children sort by canonical JSON; serialization uses sorted object keys, unescaped Unicode/slashes, and a stable SHA-256 definition hash. The evaluation instant is a required compiler input and is included in the evaluation fingerprint, not allowed to drift with wall-clock time.

## Server-owned registries

The field registry maps stable field IDs to static table/column/type/operator metadata. Initial fields are contact.created_at, company.name, and company.domain; personal identity, consent evidence, suppression, raw payload, and hidden attributes are not targetable. Event names are registered per workspace in event_types; there are no segmentable event-property paths until a reviewed schema registry exists. Contact/list/tag/event relation names and join keys are code-owned constants.

## Query compilation

The compiler produces a Laravel query builder for distinct contact IDs. It binds all values. The base predicate is contacts.workspace_id = TenantContext.workspaceId. Company join conditions include workspace equality. List/tag correlated subqueries bind workspace, contact and selected membership ID. Event predicates join event_types on both event type ID and workspace ID, and bind canonical event name and UTC time bounds. The compiler rejects a missing/foreign event name and missing/foreign list/tag ID.

Default complexity limits are configuration-backed and deliberately provisional until representative production benchmarks exist: maximum depth 8, 100 nodes, membership list size 50, relative event window 365 days, preview limit 50, synchronous exact-count timeout 3 seconds. They are conservative application defaults, not production SLO claims. Statement timeout is applied only on PostgreSQL and query cancellation bubbles without a success-shaped count.

## Versioning and authorization

A segment definition belongs to exactly one workspace. Each saved version is immutable, numbered monotonically, and stores canonical AST plus hash. Draft edits append a version; published versions cannot be overwritten. Evaluations refer to definition ID, version number/hash, tenant and pinned evaluation instant. Send eligibility, consent and suppression remain independent checks at the eventual sending boundary. PHASE-09 execution is excluded.

Routes require authenticated membership and contact.read permission. Save/preview operations use the resolved TenantContext and actor from middleware, never request-supplied tenant or author IDs. Preview response policy is separately implemented in TASK-0046 and excludes identity fields by default.

## Threat controls

- SQL and identifier injection: closed AST, static identifier maps, bound values.
- Cross-workspace reads/joins/counts: independent tenant context plus workspace equality at every relation and composite schema keys.
- Sensitive targeting: fields absent from registry by default.
- DoS/AST bombs: bounded recursion, nodes, lists, event window, preview rows and configurable database timeout.
- Hallucinated event/field: database-backed registered event name and static field registry validation.
- Version confusion/cache leakage: hash includes canonical definition; evaluation identity includes workspace/version/hash/instant.
- Logging leakage: record IDs, hashes and safe reason codes, never raw values or customer rows.

