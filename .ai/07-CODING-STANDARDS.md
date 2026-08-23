# Coding Standards

These rules are language-neutral until TASK-0002 locks the initial stack.

- Keep modules cohesive and dependencies explicit.
- Prefer typed contracts/value objects for canonical domain data.
- No provider SDK calls outside connector/infrastructure adapters.
- No business decisions in controllers/UI handlers.
- State-changing workflows should be transaction-safe and emit domain/outbox events reliably.
- Use idempotency for external side effects.
- Make retries explicit and bounded.
- Use UTC internally and preserve contact/workspace timezone separately.
- Avoid magic strings for lifecycle states, message types, permissions, provider capabilities, and canonical events.
- Migrations are forward-safe and tested; destructive migrations require a migration/rollback plan.
- Public interfaces and architectural decisions require concise documentation.
- Every bug fix includes a regression test when technically reasonable.
