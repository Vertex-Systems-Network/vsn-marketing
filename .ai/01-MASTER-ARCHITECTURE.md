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

The initial implementation stack is locked by `.ai/adr/ADR-0001-initial-application-stack.md`.

Baseline: Laravel 13 modular monolith; PHP 8.3 compatibility floor with PHP 8.5 as the reference production runtime; React 19 + TypeScript + Inertia 3; Node.js 24 LTS; PostgreSQL 18; Redis 8.x; S3-compatible object storage; durable PostgreSQL outbox; Laravel Queue/Horizon; Reverb/SSE where realtime is required; Pest 4/PHPUnit 12, Vitest and Playwright for testing.

This stack does not weaken the permanent provider-neutral rule. First-party presentation transport, queue drivers, database drivers and external provider SDKs remain replaceable infrastructure behind canonical module contracts. New microservices, ClickHouse, OpenSearch, Octane, Kubernetes or a dedicated vector database require measured need and a later ADR.

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
