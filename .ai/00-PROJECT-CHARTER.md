# Project Charter — VSN Marketing

## Mission

Build an AI-native, provider-agnostic marketing operating system that can use multiple SMTP/API/mailbox/marketing providers through explicit adapters, own and synchronize templates, orchestrate permission-based campaigns and journeys, analyze outcomes, and safely add future providers without rewriting the core.

## Product identity

VSN Marketing is not a Mailchimp clone and not merely an email sender. It is a modular marketing operating system with a customer/event data core, communication infrastructure, canonical content/template layer, automation, analytics, and an AI control plane.

## Permanent invariants

- Providers are plugins; core logic is provider-neutral.
- The canonical customer, consent, message, template, event, campaign, and journey models belong to VSN Marketing.
- Marketing and transactional traffic are logically distinct.
- Consent, suppression, permissions, frequency, quota, approval, and compliance checks are deterministic gates.
- AI cannot bypass deterministic gates.
- Provider webhooks normalize into canonical events.
- Secrets are references to a secure secret store, never normal application data.
- Multi-tenancy is Organization → Workspace → Brand.
- Every state-changing action is auditable.
- Delivery operations are idempotent and retry-safe.
- Work must be resumable, testable, auditable, and reversible.

## Initial delivery strategy

Start as a modular monolith with event-driven boundaries. Do not prematurely split into microservices. Extract services only when measured scale, isolation, or deployment needs justify it via ADR.

## Out of scope as a product capability

- Spam or unsolicited bulk messaging.
- Circumventing provider anti-abuse limits.
- Rotating fake accounts to aggregate free tiers.
- Scraping/copying proprietary template libraries without authorization.
- Autonomous infrastructure/security changes without policy and approval gates.
