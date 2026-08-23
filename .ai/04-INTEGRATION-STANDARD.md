# Integration Standard

## Adapter-first rule

Every external communication provider implements a canonical capability contract. No core module may contain provider-name conditionals for behavior that belongs in an adapter/capability check.

## Provider manifest

Every connector declares:

- provider ID/version/class
- authentication methods
- capabilities
- rate/quota discovery method
- template support
- webhook/event support
- domain/sender support
- API version and documentation source
- sandbox/test behavior
- known limitations

## Required connector components

```text
connectors/<provider>/
  manifest
  auth
  client
  mapper
  templates (when supported)
  webhooks
  errors
  tests
  README
  CHANGELOG
```

## Canonical behavior

- Normalize provider errors into stable error categories.
- Normalize delivery events into canonical events.
- Expose capabilities rather than making callers infer them.
- Respect provider terms, quotas, rate limits, and anti-abuse policies.
- Store credentials through a secret-reference abstraction.
- Verify webhook authenticity when supported.
- Retries must be idempotent and classify retryable vs permanent failure.

## Future providers

A future API must be attachable by implementing the same contracts and passing connector contract tests. Core domain modules must not require modification merely to recognize a new provider.
