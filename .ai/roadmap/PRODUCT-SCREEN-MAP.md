# Product screen map — web, panels and mobile (PHASE-17..24)

Every screen below is designed (UI/UX stage), built, tested (E2E + accessibility) and certified in its phase. Phase in brackets.

## 1. Public website (Next.js) [P17, content refined P23]
- Home / landing: hero, value proposition, feature highlights (email, social, ads, AI), live demo video, testimonials, pricing teaser, CTA
- Features: Email marketing, Social media, AI writer, Ads & boosting, Analytics, Integrations, Mobile apps
- Pricing (plans comparison, FAQ, calculator)
- Solutions: Small business, E-commerce, Agencies/resellers, Creators
- Marketplace (public catalogue of templates, recipes, services)
- Integrations directory
- Blog / resources, Help centre / docs, Changelog, Status page link
- About, Contact, Careers (optional), Partner/affiliate program, Reseller program
- Legal: Terms, Privacy, Cookie policy, Acceptable use, DPA, Refund policy; cookie consent banner
- Auth: Sign up, Log in, Forgot/reset password, Email verification, 2FA, SSO login, Invite accept
- Onboarding wizard: company profile → connect email provider → import contacts → connect social → first campaign/post

## 2. Roles

| Role | Scope |
|---|---|
| Super Admin | Whole platform |
| Support Agent | Platform, read-mostly, impersonation with audit |
| Owner | One workspace/organization, incl. billing |
| Admin | Workspace settings, team, all channels |
| Marketer | Create campaigns/posts, needs approval if configured |
| Approver/Reviewer | Approve campaigns/posts |
| Analyst | Reports only |
| Viewer | Read only |
| Seller — Reseller/Agency | Own client sub-accounts, white-label, commissions |
| Seller — Marketplace vendor | Own listings, orders, payouts |
| Affiliate | Referral links and earnings |
| Agency client user | Limited access inside delegated client workspace |

Custom roles can be built from the permission catalog.

## 3. Customer / Buyer dashboard [P17–P23]
- Home dashboard: KPIs (sent, delivered, real opens, clicks, followers, engagement, spend), recent activity, tasks/approvals, AI suggestions
- Email: Campaigns (list, create wizard, detail/report), Automations (list, builder), Templates (gallery, editor, provider templates), A/B tests, Deliverability (domains, DNS check, reputation), Providers (connections, limits meter, failover)
- Audience: Contacts (list, profile/timeline), Lists, Tags, Segments (builder, preview), Imports, Suppressions/unsubscribes, Health analysis
- Social: Calendar, Composer, Queue/scheduled, Published, Drafts, Approvals, Accounts (connect, health, data download/delete), Inbox, Duplicate checker
- Ads: Ad accounts, Boost post, Campaigns, Budgets & caps, Results
- AI studio: Writer, Post generator, Campaign planner, Brand kit/voice, Usage & limits
- Analytics: Overview, Email, Social, Ads, Funnels/cohorts, Attribution, Scheduled reports, Exports
- Marketplace (buy): Browse, Item detail, Purchases, Installed items
- Settings: Workspace, Brands, Team & roles, Invites, Integrations/API keys, Webhooks, Notifications, Security (2FA, sessions, SSO), Privacy (export, deletion, legal holds), Billing (plan, usage, invoices, payment method, cancel), Audit log
- Notifications centre, Global search, Help/AI assistant

## 4. Super Admin panel [P17, P22]
- Platform dashboard (MRR, active tenants, sends, errors, queue health)
- Tenants (list, detail, suspend, impersonate with audit), Users
- Plans & pricing, Coupons, Subscriptions, Invoices, Refunds
- Usage & quotas, Provider health, AI usage/costs
- Marketplace moderation (sellers, listings, disputes, payouts), Affiliates & resellers, Commission rules
- Content: landing pages/blog/help CMS, announcements, changelog
- System: feature flags, settings, email templates (system), logs, audit, backups/restore status, security events
- Support tickets

## 5. Seller panel [P17, P22]
- Reseller/Agency: dashboard, client sub-accounts (create, branding, limits), delegated access, white-label settings (logo, domain, colours), client billing, commissions/payouts
- Marketplace vendor: dashboard, listings (create/edit, pricing, versions), orders, reviews, payouts (Stripe Connect), disputes, analytics
- Affiliate: links, clicks/sign-ups, earnings, payouts

## 6. Mobile app (Android + iOS, Expo) [P24]
- Onboarding, Login/SSO, 2FA, Workspace switcher
- Home dashboard (KPIs, alerts)
- Approvals (campaigns, posts, ad spend) with push notifications
- Social: composer (camera/gallery), calendar, queue, inbox
- Email: campaign list and live stats, quick send of approved campaigns
- Ads: boost approvals, spend alerts
- AI writer
- Notifications, Profile & settings, Help
- Seller mini-panel: orders, payouts, client alerts
