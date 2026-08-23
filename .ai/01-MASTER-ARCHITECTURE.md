# Master Architecture

## Logical layers

```text
AI CONTROL PLANE
Strategy • Agents • Planning • Optimization • QA • Connector Factory
        ↓
MARKETING APPLICATIONS
Campaigns • Journeys • Templates • CRM/CDP • Experiments • Analytics
        ↓
COMMUNICATION ENGINE
Policy • Routing • Queue • Rate Limit • Idempotency • Retry • Failover
        ↓
CONNECTOR PLATFORM
SMTP • Delivery APIs • Marketing APIs • Mailbox APIs • Future channels
        ↓
DATA CORE
Identity • Contacts • Consent • Suppression • Events • Metrics • Audit
```

## Core architectural rule

Application modules depend on canonical interfaces. Provider adapters depend on provider SDKs/APIs. Core modules must never import a concrete provider SDK.

## Provider classes

1. Delivery providers: SES, SendGrid, Brevo, Mailgun, Resend, Postmark, MailerSend, custom SMTP, etc.
2. Marketing platforms: Mailchimp, Brevo, ActiveCampaign, Customer.io, Klaviyo, etc.
3. Mailbox providers: Gmail/Google Workspace and Microsoft/Outlook mailbox APIs for mailbox workflows, not as bulk-delivery substitutes.
4. Self-hosted connectors: Mautic, listmonk, Postal and future compatible systems.

## Target stack policy

The exact application stack is not silently assumed in this bootstrap. TASK-0002 locks the first implementation stack through ADR after repository/runtime requirements are verified. Current architectural preference is a modular monolith, relational primary store, durable queue, object storage, event outbox, and independently scalable workers.

## AI architecture

Do not build one unrestricted agent. Product AI is specialized:

- Marketing Director
- Strategy Agent
- Audience Agent
- Content Agent
- Brand Guardian
- Journey Agent
- Experiment Agent
- Deliverability Agent
- Analytics Agent
- Compliance Guard
- Connector Engineer
- QA Agent

Agents emit structured proposals/decisions. Privileged actions go through policy/approval/tool contracts.

## Long-term connector factory

New provider docs/OpenAPI → capability discovery → connector plan → generated adapter → static checks → contract tests → sandbox tests → security review → human approval → canary → active. Generated code never jumps directly to production.
