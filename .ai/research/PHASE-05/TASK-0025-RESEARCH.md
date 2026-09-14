# TASK-0025 Research Evidence — Deliverability, Sender Authentication, Provider and Jurisdiction Requirements

Date: 2026-09-15
Status: staged official-source research for PHASE-05 activation
Task: TASK-0025
Scope: permission-based email sender identity, authentication, unsubscribe/suppression, deliverability policy, and jurisdiction-aware direct-marketing controls. This is engineering/compliance research, not legal advice.

## Research questions

1. What sender-authentication and bulk-sender requirements are currently enforced by major mailbox providers?
2. What protocol-level one-click unsubscribe behavior must the platform support?
3. Which suppression/opt-out timelines and direct-marketing distinctions materially affect the product contract?
4. Which rules must remain provider- or jurisdiction-versioned instead of becoming universal constants?
5. Which PHASE-05 implementation requirements follow from the evidence without pulling later-phase capability forward?

## Official sources reviewed

| Source | Authority | Accessed | Material finding |
|---|---|---|---|
| https://support.google.com/mail/answer/81126 | Google Gmail Help — Email sender guidelines | 2026-09-15 | All senders to personal Gmail need SPF or DKIM, valid forward/reverse DNS, TLS, RFC 5322 formatting, and spam rate below 0.3%. Senders above 5,000/day must use SPF and DKIM, publish DMARC (p=none acceptable), align From with SPF or DKIM, and provide one-click + visible unsubscribe for marketing/subscribed mail. |
| https://support.google.com/mail/answer/14229414 | Google Gmail Help — sender guidelines FAQ | 2026-09-15 | Gmail aggregates the ~5,000/day threshold by primary domain; once classified as a bulk sender the classification does not expire. Enforcement ramped up from November 2025. Google recommends keeping user-reported spam below 0.1% and preventing it from reaching 0.3%. |
| https://senders.yahooinc.com/best-practices/ | Yahoo Sender Hub | 2026-09-15 | All senders need SPF or DKIM, low complaints, DNS hygiene and RFC compliance. Bulk senders need SPF + DKIM, passing DMARC with at least p=none, From alignment, one-click/list unsubscribe, a visible body unsubscribe, unsubscribe handling within 2 days, and spam below 0.3%. Yahoo's reviewed page does not define a universal numeric bulk-volume threshold; the product must not invent one. |
| https://techcommunity.microsoft.com/blog/microsoftdefenderforoffice365blog/strengthening-email-ecosystem-outlook%E2%80%99s-new-requirements-for-high%E2%80%90volume-senders/4399730 | Microsoft Defender for Office 365 Blog | 2026-09-15 | Outlook.com consumer domains require SPF, DKIM and DMARC for domains sending more than 5,000 messages/day. Non-compliant high-volume mail is rejected with 550 5.7.515; enforcement took effect May 5, 2025. |
| https://www.rfc-editor.org/rfc/rfc8058.html | IETF RFC 8058 via RFC Editor | 2026-09-15 | One-click uses List-Unsubscribe with an HTTPS URI plus List-Unsubscribe-Post: List-Unsubscribe=One-Click. A valid DKIM signature must cover both headers. The POST must not depend on cookies/HTTP authorization or prior web context. |
| https://www.ftc.gov/business-guidance/resources/can-spam-act-compliance-guide-business | US Federal Trade Commission | 2026-09-15 | Commercial email must avoid deceptive headers/subjects, identify advertising as required, include a valid physical postal address and a clear opt-out. Opt-out must remain available for at least 30 days and be honored within 10 business days; responsibility cannot simply be contracted away. |
| https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-direct-marketing-using-electronic-mail/ | UK Information Commissioner's Office | 2026-09-15 | Guidance updated 28 April 2026. PECR distinguishes individual and corporate subscribers, consent/soft-opt-in cases, bought/public lists, and electronic-mail marketing responsibilities. |
| https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-direct-marketing-using-electronic-mail/how-do-we-comply-with-the-pecr-electronic-mail-marketing-rules/ | UK Information Commissioner's Office | 2026-09-15 | Unsolicited marketing to individual subscribers needs consent or a valid soft opt-in; unsolicited marketing to corporate subscribers does not use the same consent/soft-opt-in rule. Sender identity must not be hidden and a valid opt-out contact is required for both. Bought-list consent must specifically cover the sender and channel; soft opt-in requires direct collection. |
| https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A02002L0058-20091219 | EU ePrivacy Directive consolidated text, Article 13 | 2026-09-15 | Electronic-mail direct marketing generally requires prior consent, with an existing-customer similar-products/services exception only when a free/easy objection is offered at collection and in each message. Sender identity cannot be concealed and a valid cessation address is required. National implementation still matters. |
| https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=OJ%3AL%3A2016%3A119%3AFULL | EU GDPR, Article 21 | 2026-09-15 | A data subject may object to direct-marketing processing at any time; after objection, personal data must no longer be processed for that direct-marketing purpose. The right must be clearly brought to the person's attention. |

## Evidence-backed product requirements

### R1 — Authentication state is evidence, not a boolean

Sender identity must model SPF, DKIM, DMARC, From-domain alignment, forward/reverse DNS and TLS readiness as separately observable evidence with timestamps/source/version. A generic `verified=true` flag is insufficient because provider requirements differ and change over time.

Disposition: prerequisite/acceptance criterion for TASK-0026.

### R2 — Bulk-sender classification is provider-specific

Google and Outlook currently publish a 5,000-message/day high-volume boundary for the covered consumer services, but semantics differ. Gmail aggregates by primary domain and permanently retains bulk-sender classification once assigned. Yahoo's reviewed official best-practices page specifies bulk requirements without publishing a universal numeric threshold.

Disposition: provider-policy data must be versioned; no global `bulk_threshold = 5000` constant.

### R3 — Marketing and transactional mail require explicit classification

One-click unsubscribe requirements target marketing/subscribed traffic and are not a blanket rule for transactional messages. PHASE-05 policy therefore needs an explicit message-purpose/classification input rather than inferring purpose from template names or provider routes.

Disposition: acceptance criterion for TASK-0027/TASK-0028; contract dependency on PHASE-04 message-purpose semantics.

### R4 — One-click unsubscribe must implement RFC 8058 semantics

For applicable marketing traffic, the platform must be capable of producing an HTTPS List-Unsubscribe URI plus `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; DKIM must cover those headers. The endpoint must not rely on cookies or HTTP authorization. Recipient/list identification should be represented by opaque scoped tokens rather than requiring an authenticated browser session.

Disposition: implementation requirement for TASK-0027 and certification requirement for TASK-0030.

### R5 — Suppression authority is deterministic and immediate inside VSN

Provider/legal external deadlines differ: Yahoo requires bulk-sender unsubscribes within 2 days while CAN-SPAM requires honoring opt-outs within 10 business days. The safe platform contract is therefore to make accepted unsubscribe/objection evidence effective in VSN eligibility immediately, while separately tracking any downstream provider synchronization/reconciliation state. AI recommendations must never override suppression.

Disposition: architecture/acceptance criterion for TASK-0027; inherited deterministic-policy boundary.

### R6 — Complaint thresholds are telemetry policy, not permission to send

Google and Yahoo both publish 0.3% as an important spam-complaint boundary; Google additionally recommends staying below 0.1%. These values are provider-specific deliverability health inputs, not consent or eligibility substitutes and not evidence that another provider shares the same threshold.

Disposition: TASK-0028 health/policy and TASK-0029 observability requirement.

### R7 — Jurisdiction policy needs subscriber/relationship context

US CAN-SPAM, UK PECR, EU ePrivacy implementations and GDPR rights do not reduce to one global `marketing_consent` flag. The policy boundary must be able to evaluate jurisdiction, subscriber/person type where applicable, solicitation/relationship basis, consent/soft-opt-in evidence, message purpose, sender identity and objections/suppressions. Unknown or unsupported policy context must not silently become permission.

Disposition: TASK-0027/TASK-0028 prerequisite; jurisdiction-specific legal conclusions remain external-policy/legal-review data rather than hardcoded AI judgment.

### R8 — Bought/public lists require explicit provenance and cannot inherit generic permission

ICO guidance makes bought-list validity depend on specific consent naming the sender and channel, and the UK soft opt-in requires direct collection. Public availability alone is not a universal marketing permission signal.

Disposition: inherited provenance/consent rule; no unrestricted list import or scraping authorization is introduced by PHASE-05.

## Proposed PHASE-05 invariant set

1. Suppression/objection is deterministic authority and cannot be bypassed by provider failover, AI, admin convenience or campaign configuration.
2. Sender-domain authentication evidence is versioned and provider-aware; DNS/auth secrets are referenced, never embedded in canonical records.
3. Provider rules are effective-dated data/configuration with provenance, not scattered constants in business logic.
4. Marketing vs transactional purpose is explicit and auditable before delivery eligibility evaluation.
5. One-click unsubscribe tokens are opaque, scoped and non-authenticated-user dependent; replay/abuse and cross-tenant cases are tested.
6. A policy outcome may be `allow`, `deny`, or `review/unknown`; missing jurisdiction/provider evidence never silently becomes `allow`.
7. Deliverability/reputation signals cannot create consent or erase a suppression.
8. No anti-abuse evasion, account rotation, deceptive headers, spam-rate gaming or provider-limit circumvention is permitted.

## Planned task mapping

- TASK-0025: freeze this research and convert it into reviewed PHASE-05 acceptance contracts.
- TASK-0026: sender domain/identity lifecycle, authentication evidence, DNS/reference state and provider synchronization interfaces.
- TASK-0027: deterministic suppression, unsubscribe/preferences, bounce and complaint evidence/state.
- TASK-0028: frequency caps, provider-versioned reputation/health and safe sending policy.
- TASK-0029: deliverability telemetry, diagnostics and bounded human/policy-gated remediation recommendations.
- TASK-0030: adversarial/cross-tenant/provider/jurisdiction matrix and PHASE-05 certification.

## Explicit non-goals for TASK-0025

- No live DNS mutation.
- No sender-domain production activation.
- No provider credentials or secret material.
- No warm-up automation, anti-abuse evasion, fake-account rotation or suppression bypass.
- No legal conclusion that one jurisdiction's rule applies globally.
- No PHASE-06 content studio or PHASE-07 campaign implementation.

## Open items that must stay configurable/researchable

- Yahoo bulk-sender volume classification threshold: do not invent a numeric value from non-authoritative commentary.
- Country/member-state implementations layered on EU ePrivacy/GDPR: policy packs require jurisdiction-specific review/effective dates.
- Provider requirements and enforcement behavior can change; evidence must be revalidated before production activation and periodically thereafter.
- Provider-specific reputation/feedback-loop interfaces must be researched when the corresponding concrete connector is activated.

## Research disposition

Plan confirmation: PHASE-05 sequencing remains valid.

New hard acceptance requirements: provider-versioned authentication/bulk policy, explicit message purpose, RFC 8058 one-click semantics, deterministic immediate internal suppression, jurisdiction/subscriber-context policy, and fail-closed unknown context.

No ADR required at this research stage because these findings refine the existing deterministic-policy/provider-neutral architecture rather than changing module boundaries or stack.
