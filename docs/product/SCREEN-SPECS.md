# VSN Marketing — Screen specifications

| Field | Value |
|---|---|
| Owner task | TASK-0147 |
| Covers | every screen in [PRODUCT-SCREEN-MAP](../../.ai/roadmap/PRODUCT-SCREEN-MAP.md) and the Figma frames in [DESIGN-INDEX](DESIGN-INDEX.md) |
| Rules | [UX-GUIDELINES](UX-GUIDELINES.md); requirements in [SRS](SRS.md) |

## How to read a spec row

- **Roles** use the role numbers of SRS §2.2:
  1. Super Admin
  2. Support
  3. Owner
  4. Admin
  5. Marketer
  6. Approver
  7. Analyst
  8. Viewer
  9. Reseller
  10. Vendor
  11. Affiliate
  12. Agency client

  "W" means workspace roles 3–8 and 12, following the SRS §3.11 matrix.
- **States**: every screen implements the standard set from UX-GUIDELINES §8:
  - **L** loading
  - **E** empty
  - **Er** error
  - **P** permission
  - **O** offline

  The row lists only the screen's extra states.
- **Copy** gives the English and Urdu text of the primary action. The rest of the copy follows the tone guide (§7).
- **Data** names the main API resources from [API-V1](API-V1.md).

## 1. Public website and auth (screen map §1)

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Landing | Website / Landing | public | video unavailable fallback | Start free / مفت شروع کریں | `GET /billing/plans` (teaser) | FR-SHL-04 |
| Features ×7 (Email, Social, AI, Ads, Analytics, Integrations, Mobile) | Website / Features — * | public | — | Try demo / ڈیمو آزمائیں | static CMS | FR-SHL-04 |
| Pricing | Website / Pricing | public | yearly/monthly toggle; calculator | Choose plan / پلان منتخب کریں | `/billing/plans` | FR-BL-01 |
| Solutions ×4 | Website / Solutions — * | public | — | Book a demo / ڈیمو بک کریں | CMS | FR-SHL-04 |
| Marketplace (public) | Website / Marketplace | public | no results | View item / آئٹم دیکھیں | `/marketplace/listings` | FR-MK-02 |
| Integrations directory | Website / Integrations | public | — | Connect / جوڑیں | static | FR-SHL-04 |
| Blog, Blog post, Help centre, Help article, Changelog, Status | Website / * | public | article not found | Read more / مزید پڑھیں | CMS | FR-SHL-04 |
| About, Contact, Careers, Affiliate program, Reseller program | Website / * | public | form sent; form error | Send message / پیغام بھیجیں | `POST /contact` (CMS) | FR-SHL-04 |
| Legal ×6 + Cookie consent | Website / Legal — *, Cookie consent | public | consent saved | Accept selected / منتخب قبول کریں | consent cookie | FR-SHL-04 |
| 404 | Website / 404 | public | — | Go home / ہوم پر جائیں | — | FR-SHL-03 |
| Sign up | Auth / Sign up | public | email taken; weak password | Create account / اکاؤنٹ بنائیں | `POST /auth/register` | FR-IAM-01 |
| Log in | Auth / Log in | public | locked (rate limit); 2FA required | Log in / لاگ ان | `POST /auth/login` | FR-IAM-01, NFR-SEC-04 |
| Forgot / Reset password | Auth / Forgot, Reset | public | link expired | Send link / لنک بھیجیں | `/auth/password/*` | FR-IAM-01 |
| Verify email | Auth / Verify email | new user | resent; expired | Resend email / دوبارہ بھیجیں | — | FR-IAM-01 |
| Two-factor | Auth / Two-factor | all | wrong code; recovery code | Verify / تصدیق کریں | `/auth/2fa/verify` | FR-IAM-01 |
| Single sign-on | Auth / Single sign-on | Agency plan | domain not found | Continue / جاری رکھیں | SSO | FR-IAM-01 |
| Accept invite | Auth / Accept invite | invited | invite expired/revoked | Join workspace / ورک اسپیس جوائن کریں | `/invites/{token}/accept` | FR-IAM-03 |
| Try demo | Auth / Try demo | public | demo capacity full | Open demo / ڈیمو کھولیں | `POST /demo/sessions` | FR-DEMO-03 |
| Onboarding 1–5 + Done | Onboarding / * | 3, 4 | step skipped; resume | Continue / جاری رکھیں | workspace, providers, imports, social | FR-SHL-05 |

## 2. Buyer panel (screen map §3)

### 2.1 Home and global

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Dashboard / Home | Dashboard / Home | W | demo banner; onboarding checklist | Create campaign / کیمپین بنائیں | `/analytics/overview`, `/approvals` | FR-SHL-01 |
| Getting started checklist | Buyer / Getting started checklist | 3, 4 | all done → hide | Continue setup / سیٹ اپ جاری رکھیں | onboarding progress | FR-SHL-05 |
| Notifications centre | Global / Notifications centre | W | all read | Mark all read / سب پڑھا ہوا | `/notifications` | FR-SHL-01 |
| Search results | Global / Search results, Overlay / Command palette | W | no results | Open / کھولیں | `/search` | FR-FL-01 |
| Help & AI assistant | Global / Help & AI assistant | W | AI unavailable | Ask / پوچھیں | `/ai/generations` | FR-AI-04 |
| My profile | Buyer / My profile | all | email change pending | Save / محفوظ کریں | `/me` | FR-I18N-03 |

### 2.2 Email

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Campaigns list | Dashboard / Email campaigns | W | pending approval rows | New campaign / نئی کیمپین | `/campaigns` | FR-EM-05 |
| Campaign wizard | Email / Campaign wizard | 3, 4, 5, 12 | audience 0; spam-score warning; needs approval | Schedule / شیڈول کریں | `/campaigns/*`, `/segments/preview` | FR-EM-05, FR-EM-16 |
| Campaign report | Email / Campaign report | W | sending in progress (live) | Export / ایکسپورٹ | `/campaigns/{id}/report` | FR-EM-08, FR-EM-09 |
| Automations | Email / Automations | W | paused | New automation / نئی آٹومیشن | `/automations` | FR-EM-13 |
| Automation builder | Email / Automation builder | 3, 4, 5 | invalid node; unsaved changes | Activate / فعال کریں | `/automations/{id}` | FR-EM-13 |
| Automation recipes | Buyer / Automation recipes | 3, 4, 5 | — | Use recipe / ریسیپی استعمال کریں | templates | FR-EM-13 |
| Templates | Email / Templates | W | — | New template / نیا ٹیمپلیٹ | `/templates` | FR-EM-07 |
| Template editor | Email / Template editor | 3, 4, 5, 12 | autosave; render error | Save template / ٹیمپلیٹ محفوظ کریں | `/templates/{id}` | FR-EM-07 |
| Provider templates | Email / Provider templates | 3, 4, 5 | provider not connected | Import / امپورٹ کریں | `/email/providers/{id}/templates` | FR-EM-06 |
| A/B tests | Email / A/B tests | W | winner pending | New test / نیا ٹیسٹ | `/experiments` | FR-EM-14 |
| Deliverability | Email / Deliverability | 3, 4 | DNS missing; reputation drop | Verify DNS / DNS کی تصدیق کریں | `/sending-domains` | FR-EM-15 |
| Email providers | Dashboard / Email providers | 3, 4 | limit 80% warning; failover active | Add provider / پرووائیڈر شامل کریں | `/email/providers` | FR-EM-01..03 |
| Connect provider | Buyer / Connect provider | 3, 4 | test failed (with provider message) | Test & save / ٹیسٹ اور محفوظ کریں | `POST /email/providers`, `/test` | FR-EM-01 |

### 2.3 Audience

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Contacts | Dashboard / Contacts | W | filtered empty | Import contacts / رابطے امپورٹ کریں | `/contacts` | FR-EM-10 |
| Contact profile | Audience / Contact profile | W | suppressed; privacy request open | Edit / ترمیم کریں | `/contacts/{id}`, `/timeline` | FR-EM-10, FR-PR-02 |
| Lists, Tags | Audience / Lists, Tags | W | — | New list / نئی فہرست | `/lists`, `/tags` | FR-EM-10 |
| Segments list, Segment builder | Buyer / Segments list, Audience / Segment builder | 3, 4, 5 | count updating | Save segment / سیگمنٹ محفوظ کریں | `/segments`, `/segments/preview` | FR-EM-12 |
| Imports | Audience / Imports | 3, 4, 5, 12 | mapping errors; consent source missing (blocked); processing (202) | Start import / امپورٹ شروع کریں | `/imports` | FR-EM-10, C-03 |
| Suppressions | Audience / Suppressions | 3, 4 | — | Add suppression / سپریشن شامل کریں | `/suppressions` | FR-EM-15 |
| Health analysis | Audience / Health analysis | 3, 4, 5, 7 | — | Suppress selected / منتخب کو روکیں | `/audience/health` | FR-EM-11 |

### 2.4 Social

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Calendar | Social / Calendar | W | drag disabled for viewer | New post / نئی پوسٹ | `/calendar` | FR-SO-04 |
| Composer | Social / Composer | 3, 4, 5, 12 | per-network limit exceeded; duplicate warning; needs approval | Schedule / شیڈول کریں | `/posts`, `/posts/duplicate-check` | FR-SO-03, FR-SO-07 |
| Queue, Published, Drafts | Social / Queue, Published, Drafts | W | account unhealthy → paused rows | Reschedule / دوبارہ شیڈول | `/posts?status=` | FR-SO-04 |
| Post detail | Buyer / Post detail | W | metrics syncing | Boost post / پوسٹ بوسٹ کریں | `/posts/{id}/metrics` | FR-SO-10 |
| Approvals | Social / Approvals | 3, 4, 6 | own item (cannot self-approve) | Approve / منظور کریں | `/approvals` | FR-SO-05 |
| Accounts (health) | Social / Accounts, Connect social / Reconnect — token expiring | 3, 4 | expiring; revoked; plan limit | Connect account / اکاؤنٹ جوڑیں | `/social/accounts` | FR-SO-02, FR-SO-08 |
| Connect social 1–5 | Connect social / 1–5 | 3, 4, 9 | network unavailable (app review pending → sandbox) | Continue with Facebook / فیس بک کے ساتھ جاری رکھیں | `/social/connect`, `/candidates`, `/accounts` | FR-SO-01 |
| Connect error — missing permission | Connect social / Error — missing permission | 3, 4 | — | Try again / دوبارہ کوشش کریں | — | FR-SO-01 |
| Account data (download / delete) | Social / Accounts (row menu) | 3, 4 | export preparing (202); delete confirm typed | Download data / ڈیٹا ڈاؤن لوڈ کریں | `/social/accounts/{id}/data-export` | FR-SO-08 |
| Inbox | Social / Inbox | 3, 4, 5 | AI suggestion; assigned to other | Reply / جواب دیں | `/inbox/threads` | FR-SO-09 |
| Duplicate checker | Social / Duplicate checker | 3, 4, 5 | none found | Check text / متن چیک کریں | `/posts/duplicate-check` | FR-SO-07 |

### 2.5 Ads

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Ad accounts | Ads / Ad accounts | 3, 4, 6, 7 | live mode off (sandbox badge) | Connect ad account / ایڈ اکاؤنٹ جوڑیں | `/ads/accounts` | FR-AD-01 |
| Connect ad account & cap | Connect ads / Ad account & spend cap | 3, 4 | no payment method on ad account | Connect / جوڑیں | `/ads/accounts`, `/ads/spend-policy` | FR-AD-01, FR-AD-02 |
| Boost post | Ads / Boost post | 3, 4, 5 | budget > cap (blocked); pending approval | Submit for approval / منظوری کے لیے بھیجیں | `/ads/boosts` | FR-AD-03 |
| Campaigns, Campaign detail | Ads / Campaigns, Buyer / Ad campaign detail | W | paused at cap | Edit budget / بجٹ بدلیں | `/ads/boosts` | FR-AD-03 |
| Budgets & caps | Ads / Budgets & caps | 3, 4 | — | Save caps / حد محفوظ کریں | `/ads/spend-policy` | FR-AD-02 |
| Results | Ads / Results | W | — | Export / ایکسپورٹ | `/ads/results` | FR-AD-05 |
| Ad wallet top-up, success | Ads wallet / * | 3, 4 | payment failed | Top up / ٹاپ اپ کریں | `/ads/wallet/top-ups` | FR-AD-04 |

### 2.6 AI studio

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Writer | AI / Writer | 3, 4, 5, 6, 12 | cost cap reached (402); provider down | Generate / بنائیں | `/ai/generations` | FR-AI-04 |
| Post generator | AI / Post generator | 3, 4, 5 | — | Generate posts / پوسٹس بنائیں | `/ai/generations` | FR-SO-06 |
| Campaign planner | AI / Campaign planner | 3, 4, 5 | drafts created (never sent) | Create drafts / ڈرافٹ بنائیں | `/ai/plans` | FR-AI-05 |
| Brand kit | AI / Brand kit | 3, 4 | — | Save brand kit / برانڈ کٹ محفوظ کریں | `/ai/brand-kit` | FR-AI-06 |
| Usage & limits | AI / Usage & limits | 3, 4, 7 | 80% alert | Raise cap / حد بڑھائیں | `/ai/usage`, `/ai/keys` | FR-AI-02, FR-AI-03 |

### 2.7 Analytics

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Overview, Email, Social, Ads | Analytics / * | W | data delayed banner | Change range / مدت بدلیں | `/analytics/*` | FR-RP-01 |
| Funnels, Attribution | Analytics / Funnels, Attribution | 3, 4, 7 | not enough data | Save view / ویو محفوظ کریں | `/analytics/funnels` | FR-RP-01 |
| Report builder | Buyer / Report builder | 3, 4, 7, 9 | preview rendering | Save & schedule / محفوظ اور شیڈول | `/reports` | FR-RP-02 |
| Scheduled reports, Exports | Analytics / Scheduled reports, Exports | 3, 4, 7 | export processing (202) | New schedule / نیا شیڈول | `/reports/{id}/schedules` | FR-RP-02 |

### 2.8 Marketplace (buy)

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Browse, Item detail | Marketplace / Browse, Item detail | 3, 4, 9 | already owned | Buy / خریدیں | `/marketplace/listings` | FR-MK-02 |
| Checkout, Purchase successful | Marketplace / Checkout, Purchase successful | 3, 4, 9 | payment failed; 3-D Secure | Buy for €34.51 / €34.51 میں خریدیں | `/marketplace/orders` | FR-MK-03 |
| Purchases, Installed | Marketplace / Purchases, Installed | 3, 4 | refund window open | Request refund / ریفنڈ کی درخواست | `/marketplace/purchases` | FR-MK-03 |

### 2.9 Settings and billing

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Workspace, Brands | Settings / Workspace, Brands | 3, 4 | — | Save / محفوظ کریں | `/workspace` | FR-I18N-03 |
| Team & roles, Role editor, Invites | Settings / Team & roles, Invites, Admin / Role editor | 3, 4 | seat limit; escalation blocked | Invite member / ممبر مدعو کریں | `/workspace/members`, `/roles`, `/workspace/invites` | FR-IAM-02, FR-IAM-03 |
| Integrations & API, Webhooks | Settings / Integrations & API, Webhooks | 3, 4 | key shown once | Create key / کلید بنائیں | `/webhook-endpoints` | FR-API-06 |
| Notifications | Settings / Notifications | all | — | Save / محفوظ کریں | `/notification-preferences` | FR-SHL-01 |
| Security | Settings / Security | all (enforce: 3, 4) | 2FA enforced | Enable 2FA / 2FA فعال کریں | `/me/sessions`, `/auth/2fa/*` | FR-IAM-04 |
| Privacy | Settings / Privacy | 3 (export: 4) | deletion scheduled (30-day undo) | Export data / ڈیٹا ایکسپورٹ کریں | `/privacy/*` | FR-PR-01 |
| Billing overview | Settings / Billing | 3 (read: 4) | trial; past due | Change plan / پلان بدلیں | `/billing/subscription` | FR-BL-01 |
| Change plan | Buyer / Change plan | 3 | downgrade over-limit warning | Confirm change / تبدیلی کی تصدیق | `/billing/subscription/change` | FR-BL-03 |
| Checkout 1–4, Processing, Success, Failed | Pay / * | 3 | 3-D Secure timeout; coupon invalid | Pay €811.82 / ادائیگی کریں | `/billing/checkout` | FR-BL-02 |
| Payment methods, Add card | Billing / Payment methods, Add card | 3 | expired card | Add payment method / ادائیگی کا طریقہ شامل کریں | `/billing/payment-methods` | FR-BL-04 |
| Invoice detail, Payment history | Buyer / Invoice detail, Billing / Payment history | 3, 4 (read) | refund row | Download PDF / PDF ڈاؤن لوڈ کریں | `/billing/invoices`, `/billing/payments` | FR-BL-04 |
| Trial ending, Past due | Billing / Trial ending, Past due — payment retry | 3 | grace period ended (sending paused) | Pay now / ابھی ادا کریں | `/billing/subscription` | FR-BL-05 |
| Cancel subscription | Billing / Cancel subscription | 3 | offer accepted (pause/downgrade) | Confirm cancellation / منسوخی کی تصدیق | `/billing/subscription/cancel` | FR-BL-03 |
| Audit log | Settings / Audit log | 3, 4 | — | Export / ایکسپورٹ | `/audit-events` | FR-AU-01 |
| Limit reached dialog | Overlay / Limit reached — upgrade | W | — | Upgrade / اپ گریڈ کریں | entitlements | FR-BL-06 |

## 3. Super Admin panel (screen map §4)

All screens are for role 1. Support (2) gets the same screens read-only, except impersonation, which it may start with a reason.

| Screen | Figma | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|
| Platform dashboard | Admin / Platform dashboard | incident banner | View incidents / واقعات دیکھیں | `/admin/*` metrics | FR-SA-01 |
| Tenants, Tenant detail, Create tenant | Admin / Tenants, Tenant detail, Create tenant | suspended | Suspend / معطل کریں | `/admin/tenants` | FR-PNL-02 |
| Impersonation | Admin / Impersonation | active session banner, 60-min timer | Start session / سیشن شروع کریں | `/admin/impersonations` | FR-PNL-02 |
| Users, User detail | Admin / Users, User detail | locked | Send reset link / ری سیٹ لنک بھیجیں | `/admin/users` | FR-PNL-02 |
| Plans & pricing, Plan editor, Coupons, Subscriptions, Invoices, Refunds | Admin / * | plan version in use | Save plan / پلان محفوظ کریں | `/admin/plans`, `/admin/coupons`, `/admin/refunds` | FR-BL-01 |
| Payment detail | Admin / Payment detail | disputed | Refund / ریفنڈ | `/admin/subscriptions` | FR-BL-04 |
| Usage & quotas, Provider health, AI usage & costs | Admin / * | provider degraded | Open provider / پرووائیڈر کھولیں | `/admin/provider-health`, `/admin/ai-usage` | FR-SA-01 |
| Marketplace sellers, Listings review, Disputes, Seller payouts | Admin / * | listing flagged | Approve listing / لسٹنگ منظور کریں | `/admin/marketplace/*` | FR-MK-02 |
| Partners, Commission rules | Admin / Partners, Commission rules | — | Save rule / قاعدہ محفوظ کریں | partners | FR-RS-01, FR-RS-02 |
| Content CMS, Announcements, Changelog | Admin / * | draft vs published | Publish / شائع کریں | CMS | FR-SA-01 |
| Feature flags, System settings, System emails, Logs, Backups, Security events | Admin / * | restore drill overdue | Save / محفوظ کریں | `/admin/feature-flags`, `/admin/security-events` | FR-SA-01, NFR-AVL-02 |
| Support tickets | Admin / Support tickets | SLA breach | Reply / جواب دیں | `/admin/tickets` | FR-SA-01 |

## 4. Seller panel (screen map §5)

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | Data | SRS |
|---|---|---|---|---|---|---|
| Reseller dashboard | Seller / Reseller dashboard | 9 | client near limit | Add client / کلائنٹ شامل کریں | `/partner/clients` | FR-RS-01 |
| Client accounts, Client detail, New client | Seller / * | 9 | client suspended | Open workspace / ورک اسپیس کھولیں | `/partner/clients` | FR-PNL-04 |
| Delegated access | Seller / Delegated access | 9 | — | Grant access / رسائی دیں | `/partner/clients/{id}` | FR-PNL-04 |
| White-label | Seller / White-label | 9 | domain DNS pending | Save branding / برانڈنگ محفوظ کریں | `/partner/white-label` | FR-RS-01 |
| Client billing, Commissions, Payouts | Seller / * | 9 | payout on hold (Connect not verified) | Request payout / ادائیگی کی درخواست | `/partner/commissions`, `/partner/payouts` | FR-RS-01 |
| Vendor dashboard, Listings, Listing editor | Seller / * | 10 | in review; rejected with reason | Submit for review / جائزے کے لیے بھیجیں | `/vendor/listings` | FR-MK-02 |
| Orders, Order detail, Reviews, Disputes | Seller / * | 10 | refund requested | Message buyer / خریدار کو پیغام | `/vendor/orders`, `/vendor/disputes` | FR-MK-03 |
| Payout setup (Stripe Connect), Vendor payouts | Seller / Payout setup, Vendor payouts | 10 | verification pending | Continue to Stripe / Stripe پر جائیں | `/vendor/onboarding`, `/vendor/payouts` | FR-MK-01 |
| Vendor analytics | Seller / Vendor analytics | 10 | — | Export / ایکسپورٹ | vendor stats | FR-RP-01 |
| Affiliate dashboard, Links, Clicks & sign-ups, Earnings, Payouts | Seller / Affiliate * | 11 | no clicks yet | Copy link / لنک کاپی کریں | `/affiliate/*` | FR-RS-02 |

## 5. Mobile app (screen map §6)

All mobile screens use the mobile states: L, E, Er and O (offline with cached data), plus a push-permission prompt state where push is involved.

| Screen | Figma | Roles | Extra states | Primary action (EN / UR) | SRS |
|---|---|---|---|---|---|
| Welcome, Onboarding 1–3 | Mobile / Welcome, Onboarding * | all | — | Get started / شروع کریں | FR-MB-01 |
| Log in, Two-factor, Workspace switcher | Mobile / * | all | biometric unlock | Log in / لاگ ان | FR-MB-01 |
| Home, Drawer menu, Search | Mobile / * | W | — | Open / کھولیں | FR-MB-02 |
| Approvals, Approval detail, Spend approvals | Mobile / * | 3, 4, 6 | self-approval blocked | Approve / منظور کریں | FR-MB-02 |
| Compose, Calendar, Calendar month, Queue | Mobile / * | 3, 4, 5 | camera permission denied | Schedule / شیڈول کریں | FR-MB-03 |
| Inbox, Conversation | Mobile / * | 3, 4, 5 | — | Reply / جواب دیں | FR-MB-03 |
| Email campaigns, Campaign stats, Quick send, New campaign, Templates | Mobile / * | W | quick send only for approved campaigns | Send / بھیجیں | FR-MB-03 |
| Contacts, Contact detail, Analytics, Ad results | Mobile / * | W | — | View / دیکھیں | FR-MB-02 |
| AI writer | Mobile / AI writer | 3, 4, 5 | cap reached | Generate / بنائیں | FR-MB-03 |
| Social accounts, Connect social 1–4 | Mobile / Social accounts, Connect social * | 3, 4 | OAuth in in-app browser cancelled | Continue with Facebook / فیس بک کے ساتھ جاری رکھیں | FR-MB-04 |
| Checkout, Payment successful, Payment failed | Mobile / * | 3 | store IAP required (iOS rules) | Pay / ادائیگی کریں | FR-MB-04 |
| Ad wallet top-up | Mobile / Ad wallet top-up | 3, 4 | — | Top up / ٹاپ اپ کریں | FR-MB-04 |
| Notifications, Notification settings, Profile & settings, Workspace settings, Help | Mobile / * | all | quiet hours | Save / محفوظ کریں | FR-MB-02 |
| Seller mini-panel, Admin mini-panel | Mobile / * | 9, 10, 1 | — | Open / کھولیں | FR-PNL-03 |
| Offline | Mobile / Offline | all | — | Retry / دوبارہ کوشش کریں | FR-SHL-03 |

## 6. Shared overlays and states

The States & Overlays page in Figma holds these shared overlays and states, used by every screen above:
- Loading skeleton
- Empty variants: campaigns, contacts, search
- Error 500
- Permission denied (403)
- Offline
- Toasts
- Confirm delete
- Limit reached — upgrade
- Command palette ⌘K
- Row actions menu
- Form validation
- System banners
- Session expired
- Tooltip & date picker

The Urdu RTL page holds RTL references for the dashboard, campaigns, log in and mobile home. Every other screen derives its RTL layout from UX-GUIDELINES §6.
