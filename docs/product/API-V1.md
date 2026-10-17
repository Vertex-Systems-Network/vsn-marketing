# `/api/v1` design draft

Owner task: TASK-0146. Requirements: [SRS](SRS.md) FR-API-01..06. The machine-readable OpenAPI 3.1 document is generated in TASK-0103 from this draft.

## Conventions

| Topic | Rule |
|---|---|
| Base URL | `https://api.<domain>/api/v1` (web also uses `/api/v1` on the same site) |
| Auth | Sanctum: SPA cookie with CSRF for web, bearer personal access token for mobile. `X-Workspace-Id` selects the tenant; the server checks membership |
| Format | JSON, `snake_case` fields, ISO 8601 UTC timestamps, ULID ids, money as `{ "amount_minor": 81182, "currency": "EUR" }` |
| Lists | `GET /things?filter[status]=active&sort=-created_at&page[size]=50&page[cursor]=…` returns `{ data: [...], meta: { next_cursor } }` |
| Errors | RFC 9457 `application/problem+json`: `{ type, title, status, code, detail, errors: { field: [msg] }, request_id }` |
| Idempotency | `Idempotency-Key` header required on send, publish, spend, checkout, refund and payout POSTs; kept for 24 h |
| Versioning | Additive changes only within v1; breaking changes require `/api/v2`. Deprecations use the `Sunset` header |
| Rate limits | `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`; 429 with `Retry-After` |
| Async | Long jobs return `202 { job_id }`; progress via `GET /jobs/{id}` and the Reverb channel |
| i18n | `Accept-Language` sets the locale of validation messages; content resources carry `locale` |

## Resources (draft)

| Area | Endpoints |
|---|---|
| Auth & identity | `POST /auth/register`, `/auth/login`, `/auth/logout`, `/auth/password/forgot`, `/auth/password/reset`, `/auth/2fa/enable`, `/auth/2fa/verify`, `GET /me`, `PATCH /me`, `GET /me/sessions`, `DELETE /me/sessions/{id}` |
| Workspaces & team | `GET/PATCH /workspace`, `GET /workspace/members`, `POST /workspace/invites`, `DELETE /workspace/invites/{id}`, `POST /invites/{token}/accept`, `GET/POST/PATCH /roles`, `GET /permissions` |
| Email providers | `GET/POST /email/providers`, `POST /email/providers/{id}/test`, `GET /email/providers/{id}/limits`, `PATCH /email/providers/priority`, `GET /email/providers/{id}/templates`, `POST /email/providers/{id}/templates/{ext}/import` |
| Audience | `GET/POST /contacts`, `GET/PATCH/DELETE /contacts/{id}`, `GET /contacts/{id}/timeline`, `POST /imports` (202), `GET/POST /lists`, `GET/POST /tags`, `GET/POST /segments`, `POST /segments/preview`, `GET /audience/health`, `POST /audience/health/actions`, `GET/POST /suppressions` |
| Templates | `GET/POST /templates`, `GET/PATCH /templates/{id}`, `POST /templates/{id}/test-send`, `POST /templates/{id}/render` |
| Campaigns | `GET/POST /campaigns`, `GET/PATCH /campaigns/{id}`, `POST /campaigns/{id}/schedule`, `POST /campaigns/{id}/send`, `POST /campaigns/{id}/cancel`, `GET /campaigns/{id}/report`, `GET /campaigns/{id}/recipients` |
| Automations & tests | `GET/POST /automations`, `PATCH /automations/{id}`, `POST /automations/{id}/activate`, `GET/POST /experiments`, `GET /experiments/{id}/results` |
| Deliverability | `GET/POST /sending-domains`, `POST /sending-domains/{id}/verify`, `GET /deliverability/reputation` |
| Social | `GET /social/networks`, `POST /social/connect`, `GET /social/callback`, `GET /social/connect/{session}/candidates`, `POST /social/accounts`, `GET /social/accounts`, `POST /social/accounts/{id}/refresh`, `GET /social/accounts/{id}/data-export` (202), `DELETE /social/accounts/{id}` |
| Posts & calendar | `GET/POST /posts`, `GET/PATCH /posts/{id}`, `POST /posts/{id}/schedule`, `POST /posts/{id}/publish`, `POST /posts/duplicate-check`, `GET /calendar?from&to`, `GET /posts/{id}/metrics` |
| Approvals | `GET /approvals?status=pending`, `POST /approvals/{id}/approve`, `POST /approvals/{id}/reject` |
| Inbox | `GET /inbox/threads`, `GET /inbox/threads/{id}`, `POST /inbox/threads/{id}/reply`, `POST /inbox/threads/{id}/assign` |
| AI | `POST /ai/generations`, `GET /ai/generations/{id}`, `GET/PATCH /ai/brand-kit`, `GET /ai/usage`, `GET/POST /ai/keys`, `POST /ai/plans` (campaign planner, returns drafts) |
| Ads | `GET/POST /ads/accounts`, `GET/PATCH /ads/spend-policy`, `GET/POST /ads/boosts`, `POST /ads/boosts/{id}/pause`, `GET /ads/results`, `GET /ads/wallet`, `POST /ads/wallet/top-ups` |
| Billing | `GET /billing/plans`, `POST /billing/checkout`, `GET /billing/subscription`, `POST /billing/subscription/change`, `POST /billing/subscription/cancel`, `POST /billing/subscription/pause`, `GET/POST/DELETE /billing/payment-methods`, `GET /billing/invoices`, `GET /billing/invoices/{id}.pdf`, `GET /billing/payments` |
| Partners | `GET/POST /partner/clients`, `PATCH /partner/clients/{id}`, `GET/PATCH /partner/white-label`, `GET /partner/commissions`, `GET /partner/payouts`, `GET/POST /affiliate/links`, `GET /affiliate/stats` |
| Marketplace | `GET /marketplace/listings`, `GET /marketplace/listings/{id}`, `POST /marketplace/orders`, `GET /marketplace/purchases`, `POST /marketplace/orders/{id}/refund`, `POST /marketplace/orders/{id}/reviews`; vendor: `GET/POST/PATCH /vendor/listings`, `GET /vendor/orders`, `POST /vendor/onboarding` (Stripe Connect link), `GET /vendor/payouts`, `GET /vendor/disputes` |
| Analytics & reports | `GET /analytics/overview`, `GET /analytics/{channel}`, `GET /analytics/funnels`, `GET/POST /reports`, `POST /reports/{id}/export` (202), `GET/POST /reports/{id}/schedules` |
| Search & filters | `GET /search?q=`, `GET/POST /saved-filters` |
| Notifications | `GET /notifications`, `POST /notifications/read`, `GET/PATCH /notification-preferences`, `POST /devices` (push token) |
| Privacy & audit | `POST /privacy/workspace-export` (202), `POST /privacy/workspace-deletion`, `POST /contacts/{id}/privacy-requests`, `GET /audit-events` |
| Admin (platform) | `/admin/tenants`, `/admin/tenants/{id}/suspend`, `/admin/impersonations` (reason, ttl), `/admin/users`, `/admin/plans`, `/admin/coupons`, `/admin/subscriptions`, `/admin/refunds`, `/admin/provider-health`, `/admin/ai-usage`, `/admin/marketplace/listings/{id}/moderate`, `/admin/feature-flags`, `/admin/system-emails`, `/admin/security-events`, `/admin/tickets` |
| Webhooks in | `POST /webhooks/{provider}` (signature verified, 5-minute replay window) |
| Webhooks out | `GET/POST /webhook-endpoints`, `POST /webhook-endpoints/{id}/test`; events `campaign.sent`, `email.delivered`, `email.opened`, `post.published`, `payment.succeeded`, `contact.unsubscribed` |
| Demo | `POST /demo/sessions` (public, creates an isolated demo tenant), `POST /demo/reset` |

## Status codes

`200` OK · `201` created · `202` accepted (job) · `204` no content · `400` malformed · `401` unauthenticated · `402` plan limit or cost cap reached (`code: limit_reached`) · `403` forbidden or approval required (`code: approval_required`) · `404` not found or not in tenant · `409` conflict or duplicate (`code: duplicate_post`) · `422` validation · `429` rate limited · `503` provider unavailable (failover exhausted).
