# Phase 13 messaging capability boundary

Canonical messaging candidates are `sms`, `whatsapp`, `rcs`, `push`, and `in_app`. A candidate has an explicit recipient identity type, provider key, source URL, observed/freshness timestamps, required scopes, support state, and readiness state. `unknown`, `unsupported`, missing scopes and stale evidence refuse preparation. These are research-backed **offline** adapter candidates, not live provider bindings.

An intent is scoped to workspace, brand, account workspace, provider and channel. The evaluator requires channel-specific purpose authorization, canonical delivery eligibility, canonical suppression/objection, provider opt-out, approved account, rate budget, matching recipient identity, matching provider policy and current capabilities. It emits an explainable denial. Existing delivery policy remains authoritative; provider state can never restore a suppressed recipient.

`prepare()` can return an offline decision when synthetic authorization evidence is present. `dispatch()` has no network path and always denies. Account grant, production identity, credential reference, webhook signature, durable idempotency reservation, recipient token lifecycle, quota accounting, endpoint-specific body validation, ambiguous outcome reconciliation and real integration are still required before live activation. The remaining TASK-0076 acceptance criteria must not be marked complete on this source slice alone.

No automatic RCS-to-SMS fallback, WhatsApp-to-SMS permission reuse, or generic cross-channel purpose conversion exists. A provider HTTP acknowledgement cannot be treated as recipient delivery or display. Revalidate official provider policies and versions before adding each concrete live adapter.
