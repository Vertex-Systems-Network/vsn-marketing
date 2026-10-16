# Options Bank — features and differentiators (research 2026-10-17)

Built from `SYSTEMS-BANK.md`. Each option: what it is, which market system shows it, how VSN does it better, priority (P1 = launch, P2 = soon after, P3 = later) and target phase. Options are candidates; each phase research task re-verifies them before engineering.

## 1. Positioning — why VSN instead of competitors

**"One affordable, AI-native workspace for email + social + ads, with bring-your-own provider and white-label for agencies."**

| Market gap (Systems Bank §G) | VSN answer |
|---|---|
| Contact/seat/channel price jumps | Plans priced by workspace + usage bands; unlimited seats; transparent limits meter in UI |
| Email and social in separate paid tools | One app: email, social, ads, AI, reports |
| Shrinking free tiers | Bring-your-own provider (SMTP, Gmail, Brevo, Resend, SES, Mailjet…) with automatic failover and free-tier limit tracking |
| Fake opens (Apple MPP, scanners) | "Honest analytics": machine opens and bot clicks flagged and separated; real-engagement score |
| Thin AI wrappers | AI grounded in the user's own results, brand kit and history; human approval before anything goes live |
| Agencies stuck with GoHighLevel complexity | Simple white-label reseller panel on TASK-0097 delegation |
| Cluttered UI, slow support | Premium Next.js UI, guided wizards, in-app AI help |

## 2. Email options

| # | Option | Seen in | VSN better by | Pri | Phase |
|---|---|---|---|---|---|
| E1 | Multi-provider connections (SMTP, Gmail, Brevo, Resend, SendGrid, Mailgun, SES, Postmark, Mailjet, MailerSend) | Single-provider tools | Use several at once, cheapest/free first, failover | P1 | 18 |
| E2 | Free-tier limit tracker per provider (daily/monthly remaining) | None prominently | Live meter + auto-route when one provider hits cap | P1 | 18 |
| E3 | Drag-and-drop template builder + provider template import | Mailchimp, Brevo | Import existing provider templates; AI generate from brief | P1 | 18 |
| E4 | Contacts/lists with CSV import, dedupe, tags, active/bounced/suppressed health | All | Automatic list-health score and cleanup suggestions | P1 | 18 |
| E5 | Campaign wizard (audience → content → schedule → approve → send) | All | Pre-send checklist: spam score, links, consent, provider limits | P1 | 18 |
| E6 | Tracking: delivered, bounce, open, click, unsubscribe, complaint | All | Machine-open/bot-click separation, real-engagement score | P1 | 18 |
| E7 | Automations / journeys (welcome, abandoned cart, re-engagement) | Klaviyo, ActiveCampaign | Visual builder already backed by PHASE-09 engine | P2 | 18 |
| E8 | A/B testing subject/content | Mailchimp, Klaviyo | Statistical guardrails from PHASE-11 (no false winners) | P2 | 18 |
| E9 | Send-time optimization | Klaviyo, Brevo | Per-contact, based on real (non-machine) engagement | P3 | 20 |
| E10 | Deliverability centre (SPF/DKIM/DMARC checker, reputation) | Postmark, Brevo | Guided DNS setup with live verification | P2 | 18 |

## 3. Social media options

| # | Option | Seen in | VSN better by | Pri | Phase |
|---|---|---|---|---|---|
| S1 | Connect Facebook, Instagram, LinkedIn, X, TikTok, YouTube, Pinterest | Buffer, Hootsuite | Flat pricing, not per channel | P1 | 19 |
| S2 | Composer with per-network preview and auto-resize | Later, Buffer | AI adapts one idea to each network's format | P1 | 19 |
| S3 | Content calendar, scheduling, queue, best-time | All | Shared calendar with email campaigns | P1 | 19 |
| S4 | Duplicate / near-duplicate post check across networks and history | Rare | Built-in check before publish (owner requirement R12) | P1 | 19 |
| S5 | Approval workflow for teams/clients | Sprout, Hootsuite | Included in all plans | P1 | 19 |
| S6 | AI post generation from URL/product/brief with images | Predis, Ocoya | Uses brand kit + past top posts; no credit-metering surprises | P1 | 19/20 |
| S7 | Post performance analytics + competitor tracking | Metricool, Predis | Unified with email in one report | P2 | 19/23 |
| S8 | Conditional follow-up posting (repost/boost if it performs) | FeedHive | Rule-based with human approval | P3 | 19/21 |
| S9 | Social inbox (comments/DMs) | Hootsuite, Sprout | PHASE-13 community inbox foundation | P2 | 19 |
| S10 | Account data download/update/delete | Rare | Privacy centre per connected account (R14) | P1 | 19 |

## 4. Ads / boosting options

| # | Option | Seen in | VSN better by | Pri | Phase |
|---|---|---|---|---|---|
| A1 | Boost a published post (Meta, TikTok, LinkedIn, Google) | Social Champ, Zernio API | Hard budget caps + human approval before spend | P2 | 21 |
| A2 | Organic + paid results in one view | Metricool, Zernio | Includes email results too | P2 | 21 |
| A3 | AI suggests which post to boost | Few | Based on real engagement score | P3 | 21 |

## 5. AI options

| # | Option | VSN better by | Pri | Phase |
|---|---|---|---|---|
| I1 | Choose ChatGPT, Gemini or Claude; bring-your-own key | No lock-in, cost control | P1 | 20 |
| I2 | AI writer: subjects, emails, posts, rewrite, translate (incl. Urdu) | Brand voice from brand kit | P1 | 20 |
| I3 | AI campaign planner (goal → email + social plan) | Uses PHASE-10/15 bounded agents with approvals | P2 | 20 |
| I4 | AI insights on reports ("why did this drop?") | Grounded in real data | P2 | 23 |
| I5 | AI usage meter and monthly cap per plan | Transparent cost | P1 | 20/22 |

## 6. Business, panels and packages

| # | Option | Seen in | VSN better by | Pri | Phase |
|---|---|---|---|---|---|
| B1 | Plans: Free, Starter, Pro, Agency; usage bands not contact jumps | Brevo (send-based) | Predictable pricing | P1 | 22 |
| B2 | Super Admin panel: tenants, plans, usage, revenue, support | All SaaS | AI anomaly alerts | P1 | 17 |
| B3 | Reseller / agency white-label panel with client sub-accounts | GoHighLevel | Simpler setup, strict client isolation (TASK-0097) | P1 | 17/22 |
| B4 | Easy self-serve cancellation and export | Users complain about others | Trust signal | P1 | 22 |
| B5 | Default roles + custom roles screen | All | 22-permission model already built | P1 | 17 |

## 7. Platform and UX

| # | Option | Pri | Phase |
|---|---|---|---|
| U1 | Next.js premium UI, dark/light, fast dashboards | P1 | 17 |
| U2 | Android + iOS apps: approvals, scheduling, live stats, push | P2 | 24 |
| U3 | Onboarding wizard (connect provider → import contacts → first campaign/post) | P1 | 17/18 |
| U4 | Global search and filters on every list; saved views | P1 | 23 |
| U5 | Reports centre: cross-channel, scheduled, CSV/PDF export | P1 | 23 |
| U6 | Multi-language UI incl. Urdu (RTL) | P2 | 17/23 |
| U7 | In-app help and AI assistant | P2 | 20 |

## 8. Next step

Owner reviews priorities; each phase research task re-verifies the cited systems on official sources and records the final selected options in its `TASK-xxxx-RESEARCH.md`.
