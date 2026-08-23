# Canonical Event Contract

Provider and product events are normalized before domain workflows consume them.

## Envelope

Schema version `1` is the initial durable contract. Its JSON field names are stable:

- `event_id`
- `event_type`
- `occurred_at`
- `received_at`
- `workspace_id`
- `brand_id` when applicable
- `subjects` — named canonical subject identifiers such as contact/message/campaign/journey/order IDs
- `source` (`internal`, provider ID, integration ID)
- `source_event_id` when available
- `schema_version`
- `payload`
- `source_metadata` — sanitized source metadata only

Canonical events are written to the transactional outbox with `event_id` as the outbox ID, `event_type` as the topic, and an outbox contract header of `vsn.canonical-event`. A typed canonical event is emitted only after the durable envelope, identity, topic, and schema header validate.

## Versioning rules

- Producers MUST emit the current supported schema version.
- Consumers MUST reject unknown schema versions rather than guessing their shape.
- Additive backward-compatible changes may remain inside a schema version only when existing consumers preserve semantics; otherwise increment `schema_version` and provide an explicit migration/upcaster path before enabling the producer.
- Durable outbox records are immutable event evidence. Replay reuses the stored envelope; it does not silently rewrite historical payloads.
- `occurred_at` describes the domain occurrence. `received_at` describes when VSN accepted the normalized event. Both are UTC timestamps.

## Initial event vocabulary

Customer/product:
- `contact.created`, `contact.updated`
- `consent.granted`, `consent.revoked`
- `product.viewed`, `cart.created`, `cart.abandoned`
- `order.created`, `order.completed`

Messaging:
- `message.queued`, `message.sent`, `message.delivered`
- `message.opened`, `message.clicked`
- `message.bounced`, `message.complained`, `message.unsubscribed`
- `message.failed`

Campaign/journey:
- `campaign.started`, `campaign.completed`
- `journey.enrolled`, `journey.node.completed`, `journey.exited`

## Invariants

- Event IDs are unique and ingestion/execution is idempotent.
- Provider webhook names never become domain event names directly.
- Schema evolution is versioned and backward-compatible or migrated explicitly.
- Raw provider data is not trusted until signature/shape validation is complete.
- Secret-bearing metadata keys such as credentials, authorization tokens, API keys, and private keys are rejected from `source_metadata`.
- Failed asynchronous publication uses bounded retry delays. The terminal attempt is retained as a dead-lettered outbox row and can be explicitly reset for durable replay without deleting the original envelope.
