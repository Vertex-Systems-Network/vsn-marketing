# TASK-0098 acceptance matrix

Billing provider: Stripe, test mode only (ADR-0006). Live charges are hard-disabled in code.

| Criterion | Implementation | Evidence |
| --- | --- | --- |
| AC-1 tenant-scoped, idempotent, fail-closed metering and entitlements | `DeterministicBilling::meter` (unique workspace+idempotency key, conflicting duplicates rejected, stale >35d / future / unknown metric / unsubscribed rejected); `admits` fails closed without an active subscription, known plan or metric | `DeterministicBillingTest` metering/limits and tenant-scoping cases |
| AC-2 plan/spend changes need independent policy and authorized humans | Plan changes require `billing.manage`; approver must differ from requester; request bound to current plan under row lock; unknown plans rejected | two-person approval test incl. self-approval, foreign workspace and replayed approval |
| AC-3 webhook replay, cross-tenant, concurrency; live charges disabled | Stripe `t=,v1=` HMAC verification with 300s tolerance, `livemode` events rejected, customer→workspace mapping with metadata cross-check, unique event id insert-or-ignore; `createLiveCharge()` always throws | webhook replay/tamper/stale/live/cross-tenant test; repeated delivery applies exactly once |
