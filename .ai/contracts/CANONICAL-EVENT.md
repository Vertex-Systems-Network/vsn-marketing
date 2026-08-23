# Canonical Event Contract

Provider and product events are normalized before domain workflows consume them.

## Envelope

- event_id
- event_type
- occurred_at
- received_at
- workspace_id
- brand_id when applicable
- subject identifiers (contact/message/campaign/journey/etc.)
- source (`internal`, provider ID, integration ID)
- source_event_id when available
- schema_version
- payload
- sanitized source metadata

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

- Event IDs are unique and ingestion is idempotent.
- Provider webhook names never become domain event names directly.
- Schema evolution is versioned and backward-compatible or migrated explicitly.
- Raw provider data is not trusted until signature/shape validation is complete.
