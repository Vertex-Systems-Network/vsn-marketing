# AI Execution Contract

Product AI returns structured proposals; deterministic application services execute allowed actions.

## Every AI execution records

- workspace/brand scope
- agent type and purpose
- model/provider/version when available
- prompt/template version
- tool/schema version
- referenced input IDs rather than unnecessary raw secrets/PII
- structured output
- confidence and reason codes when relevant
- policy evaluation
- approval result when required
- token/cost/latency telemetry when available
- resulting action/audit IDs

## Decision shape principle

Prefer enums and typed payloads such as:

```json
{
  "decision": "SEND_EDUCATIONAL_EMAIL",
  "confidence": 0.87,
  "reason_codes": ["HIGH_INTENT", "LOW_TRUST"],
  "inputs_used": ["segment:123", "offer:45"]
}
```

Free-form content can exist as a field, but it cannot define arbitrary executable commands.

## Tool boundaries

AI tools are narrow and authorization-aware. A tool call passes through tenant/RBAC/policy/consent/suppression/quota/approval checks as applicable. AI never receives database superuser, unrestricted shell, provider master credentials, or a generic execute-anything marketing action in production.
