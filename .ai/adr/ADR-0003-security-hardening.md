# ADR-0003 — Authentication and Operational Endpoint Hardening

Status: Accepted

Date: 2026-09-14

## Context

A focused cyber-security audit of the current application baseline identified four concrete gaps before further TASK-0024 work should proceed:

1. `/auth/login` used the first-party session guard correctly but had no explicit brute-force throttle.
2. `/api/runtime`, `/api/metrics`, and `/api/health/ready` were publicly reachable and exposed runtime, traffic, and dependency-state metadata useful for reconnaissance.
3. Application responses did not set a baseline set of browser security headers.
4. `SESSION_SECURE_COOKIE` had no production-safe default, so an omitted deployment variable could leave an authenticated session cookie without the Secure attribute.

The repository already has strong supply-chain controls: immutable GitHub Action pins, PHP SAST, dependency auditing, repository and container secret scanning, container vulnerability scanning, reproducible source/SBOM checks, and strict required CI on `main`. This ADR therefore narrows the change to application attack-surface hardening rather than replacing those controls.

## Decision

### Login throttling

Register a named `login` limiter with two independent limits:

- a normalized-email account bucket; and
- a source-IP bucket.

Both limits apply to the login route. A client cannot evade the complete control merely by rotating account identifiers or source addresses. Rate-limit keys contain only a SHA-256 digest of the normalized email, never the submitted address itself.

### Operational endpoint access

Keep `/api/health/live` public and minimal for external liveness checks.

Protect these detailed operational endpoints with both an operations rate limiter and a dedicated operations-token middleware:

- `/api/runtime`
- `/api/health/ready`
- `/api/metrics`

The token is supplied through `OPERATIONS_TOKEN`, must contain at least 32 characters, and is compared with `hash_equals`. Missing configuration, missing headers, short tokens, and mismatches all fail closed. Token values are not logged or returned. Authorized operational responses use `Cache-Control: no-store`.

### Response headers

Apply a global middleware that adds a conservative baseline without breaking the existing Inertia/Vite frontend:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-XSS-Protection: 0`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'`

In production HTTPS requests, also emit `Strict-Transport-Security: max-age=31536000`. `includeSubDomains` and preload are intentionally not asserted because domain-wide deployment assumptions are not yet certified.

### Session cookie

When `SESSION_SECURE_COOKIE` is not explicitly configured, default the session cookie to Secure in the `production` environment. Local HTTP development may explicitly use `SESSION_SECURE_COOKIE=false`.

## Consequences

- Monitoring and operator tooling must configure a high-entropy `OPERATIONS_TOKEN` and send it as `X-Operations-Token` for runtime, readiness, and metrics requests.
- External smoke checks should use the public liveness endpoint; detailed readiness remains available only to authorized operational clients.
- Login abuse receives HTTP 429 after the configured account or source-IP budget is exhausted.
- This change does not add PHASE-05 capability, change delivery behavior, or infer any performance/SLO threshold.
- Future authentication work should still evaluate MFA, breached-password controls, and risk-based protections when those capabilities enter product scope.
