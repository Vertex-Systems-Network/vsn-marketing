# PHASE-08 Segmentation Architecture

Status: TASK-0044 AST/compiler and TASK-0045 provider-neutral proposal boundary accepted; TASK-0046 preview/count UX active, 2026-09-26.

## Runtime boundary

An authenticated request resolves TenantContext from membership middleware. SegmentDefinition is a versioned, canonical document with no organization/workspace field. The compiler receives TenantContext as a separate required argument and injects workspace equality into the contacts base scan and each registered relation. Browser or model payloads never provide scope.

Natural-language proposals are accepted only as untrusted structured AST. The same validator and field/event registries apply to typed rules and AI proposals. The model cannot select SQL, identifiers, joins, event properties, functions, or permissions.

## Canonical AST v1

Envelope keys are exactly schema_version=1, subject=contact, and root. Nodes are:
- group: operator all|any and children
- not: one child
- attribute: registered field, typed operator and value
- membership: kind list|tag, workspace-owned id and operator in|not_in
- event: registered canonical event name, mode exists|not_exists|count|first|last, and bounded window

Empty groups, unknown keys, unknown nodes, coercion, null-as-value, raw expressions, JSON paths, arbitrary sort/group, regex, and free-form event properties are rejected. Null is explicit via is_set and is_not_set. Attribute operators are type-matched: text supports equals/not_equals; timestamp supports before/after/on_or_before/on_or_after and is_set/is_not_set. Membership is represented by canonical list/tag IDs. Event count uses a bounded minimum count; first/last mean earliest/latest workspace-scoped event of that registered type within the requested half-open UTC interval.

Groups are normalized recursively. Commutative children sort by canonical JSON; serialization uses sorted object keys, unescaped Unicode/slashes, and a stable SHA-256 definition hash. The evaluation instant is a required compiler input and is included in the evaluation fingerprint, not allowed to drift with wall-clock time.

## Server-owned registries

The field registry maps stable field IDs to static table/column/type/operator metadata. Initial fields are contact.created_at, company.name, and company.domain; personal identity, consent evidence, suppression, raw payload, and hidden attributes are not targetable. Event names are registered per workspace in event_types; there are no segmentable event-property paths until a reviewed schema registry exists. Contact/list/tag/event relation names and join keys are code-owned constants.

## Query compilation

The compiler produces a Laravel query builder for distinct contact IDs. It binds all values. The base predicate is contacts.workspace_id = TenantContext.workspaceId. Company join conditions include workspace equality. List/tag correlated subqueries bind workspace, contact and selected membership ID. Event predicates join event_types on both event type ID and workspace ID, and bind canonical event name and UTC time bounds. The compiler rejects a missing/foreign event name and missing/foreign list/tag ID.

Default complexity limits are configuration-backed and deliberately provisional until representative production benchmarks exist: maximum depth 8, 100 nodes, one registered UUID per membership predicate (the node limit bounds predicate count), relative or absolute event-window span 365 days, preview limit 50, synchronous exact-count timeout 3 seconds, compiler cost score 100. They are conservative application defaults, not production SLO claims. Statement timeout is applied only on PostgreSQL and query cancellation bubbles without a success-shaped count.

## Versioning and authorization

A segment definition belongs to exactly one workspace. Each saved version is immutable, numbered monotonically, and stores canonical AST plus hash. Draft edits append a version; published versions cannot be overwritten. Evaluations refer to definition ID, version number/hash, tenant and pinned evaluation instant. Send eligibility, consent and suppression remain independent checks at the eventual sending boundary. PHASE-09 execution is excluded.

Operator and preview routes require authenticated membership plus contact.read and analytics.read as applicable; save requires contact.write, and AI proposals require ai.execute. All operations use the resolved TenantContext and actor from middleware, never request-supplied tenant or author IDs. Preview response policy is separately implemented in TASK-0046 and excludes identity fields by default.

## Threat controls

- SQL and identifier injection: closed AST, static identifier maps, bound values.
- Cross-workspace reads/joins/counts: independent tenant context plus workspace equality at every relation and composite schema keys.
- Sensitive targeting: fields absent from registry by default.
- DoS/AST bombs: bounded recursion, nodes, lists, event window, preview rows and configurable database timeout.
- Hallucinated event/field: database-backed registered event name and static field registry validation.
- Version confusion/cache leakage: hash includes canonical definition; evaluation identity includes workspace, brand scope, version/hash, and pinned instant.
- Logging leakage: record IDs, hashes and safe reason codes, never raw values or customer rows.


## TASK-0045 proposal contract

The model-facing provider port accepts only the operator's bounded intent and an allowlisted schema description: stable field IDs/types/operators and canonical event names for the authenticated workspace. It receives no workspace id, tenant id, customer rows, customer identifiers, event payloads, consent/suppression evidence, list/tag IDs, provider credentials, or SQL metadata. AI list/tag predicates remain unsupported until a server-owned name-to-ID picker is available.

Provider responses are proposals only. Schema normalization, current workspace event validation, permission checks and cost validation run after the provider call. Ambiguous requests remain clarification-only; invalid or hallucinated fields/events fail closed. Obvious email and credential literals are rejected before dispatch and also rejected if returned inside a proposed text value. The default provider binding is unavailable, has no retry, and never calls an external provider.

Operator review exposes the canonical structured proposal in an editable JSON editor; saving requires contact.read + contact.write and an explicit confirmation flag. Audit events store only the intent SHA-256, safe outcome code, canonical definition hash and saved segment/version identity. Prompts, raw values and customer data are never written to audit evidence.

Provider implementations must use the repository's approved AI Gateway, schema-constrained structured output, fixed policy, no tool calling, and bounded time/token budgets. The application does not retry provider requests; no provider-specific client or secret is registered by this task.

TASK-0045 acceptance also rejects obvious authority-seeking instructions before the provider call and requires clarification for undefined audience labels such as “high value” and “recently active.” The model still cannot confer authority: current registered fields/events, policy, workspace membership, AST shape and cost are independently checked before any proposal is saved. A provider failure is a single attempt with a safe status; the default unconfigured route remains unavailable.
## TASK-0046 bounded preview and immutable publication

The operator preview is count-only. The compiler supplies the tenant predicate independently of the AST, and the evaluator counts at most `max_count_probe + 1` matched IDs in a derived query. Counts above the probe are labeled capped with a lower bound, never exact. PostgreSQL evaluation uses a transaction-local statement timeout; other drivers are test-only and have no equivalent production timeout guarantee. Query errors return an unavailable status without the SQL, bound values, or member identities. The numeric probe and timeout are conservative configurable defaults, not measured production SLOs.

Each preview carries a canonical hash, evaluation fingerprint, instant and optional pinned version. Source freshness is unknown and explicitly labeled; edits invalidate the displayed result. Preview does not materialize membership, cache counts or export contacts. Any later cache must include workspace, brand, pinned version/hash, evaluation identity and permission/policy revision, and reauthorize on retrieval. Contact eligibility is not inferred from membership: send admission must recheck canonical consent and suppression.

Versions append under a workspace-scoped row lock. A nullable published version pointer pins the immutable version chosen by an authorized operator; later edits leave that pointer unchanged. The additive migration has a nullable column, no backfill, and a guarded repeat application. Rollback drops the pointer and must be used only after an operator confirms no published references depend on it. PostgreSQL plan and cardinality evidence remain required before production thresholds are approved.
