# Canonical Email Provider Contract

The application depends on this conceptual contract; concrete signatures are implemented in the stack chosen by ADR.

## Required operations

- `capabilities()`
- `verifyConnection()`
- `send(message)`
- `sendBatch(messages)` when capability is declared
- `quota()` when discoverable
- `health()`
- `handleWebhook(rawRequest)` → canonical events

Template/domain/suppression operations are optional capabilities and must return explicit unsupported results when absent.

## Canonical message minimum

- stable internal message ID
- idempotency key
- workspace/brand IDs
- classification: transactional | marketing | lifecycle | security | system | sales
- sender identity
- recipients
- subject
- HTML and/or text content
- headers/tags/metadata
- attachments by secure reference
- tracking policy
- unsubscribe/consent context where applicable
- scheduled time where application-managed scheduling is requested

## Capability keys

- `email.send.transactional`
- `email.send.marketing`
- `email.send.batch`
- `email.schedule.remote`
- `email.attachments`
- `email.tags`
- `template.remote.create|update|delete|sync`
- `domain.remote.verify`
- `suppression.remote.read|write`
- `webhook.delivered|bounced|complained|opened|clicked|unsubscribed`
- `quota.remote.read`

## Canonical errors

- AUTHENTICATION_FAILED
- AUTHORIZATION_FAILED
- INVALID_REQUEST
- INVALID_RECIPIENT
- SENDER_NOT_VERIFIED
- RATE_LIMITED
- QUOTA_EXCEEDED
- PROVIDER_UNAVAILABLE
- TRANSIENT_PROVIDER_ERROR
- PERMANENT_PROVIDER_ERROR
- UNSUPPORTED_CAPABILITY
- WEBHOOK_SIGNATURE_INVALID

Provider-specific errors must map to these stable categories while preserving sanitized diagnostic metadata.
