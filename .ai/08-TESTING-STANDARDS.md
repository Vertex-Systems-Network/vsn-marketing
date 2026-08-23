# Testing Standards

## Required layers

1. Unit tests for domain rules and deterministic policies.
2. Contract tests for every connector against canonical provider contracts.
3. Integration tests for persistence, queues, webhooks, idempotency, and tenant isolation.
4. Feature/API tests for user workflows.
5. End-to-end tests for critical campaign/journey paths when UI exists.
6. Security regression tests for permissions, tenant isolation, webhook verification, and suppression/consent gates.

## Connector tests

Each provider must test authentication failure, send success, transient failure, permanent failure, rate limiting, idempotency behavior, supported webhooks, malformed webhook, and capability declarations. Unsupported features must fail explicitly, never silently.

## Completion rule

A task cannot be `completed` when a required test suite is known failing. A flaky test is a blocker to diagnose, not a reason to ignore the suite.
