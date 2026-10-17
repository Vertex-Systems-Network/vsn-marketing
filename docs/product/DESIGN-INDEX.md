# Design index — VSN Marketing Product App (Figma)

Figma file: [VSN Marketing — Product App](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN), in team folder 637889245.
- **Tokens:** every colour comes from `JqukmwKhVyObh1SQ6gsKR0` (PRODUCT-TRACK-PLAN §2.1). All colours are bound to the 14 `VSN Tokens` variables.
- **Fonts:**
  - Latin: Plus Jakarta Sans.
  - Urdu UI text: Noto Sans Arabic.
  - Urdu headings: Noto Nastaliq Urdu.
- **Data:** every screen uses dummy demo data only.

Status (2026-10-17): full first pass, **245 screens and state frames** across 16 pages, plus 5 base components. Every screen in `.ai/roadmap/PRODUCT-SCREEN-MAP.md` §1–§6 has a design. Shared states (loading, empty, error, 403, offline, toasts, modals, command palette, menus, validation, banners) are designed once on **States & Overlays** and apply to every screen. RTL rules are shown on **Urdu RTL**.

These designs are inputs to TASK-0145 (SRS) and TASK-0147 (UI/UX guidelines and per-screen specs).

## Layout rules used everywhere
- **Panels:** 1440 px desktop frame.
- **Sidebar:** 240 px with role-filtered navigation and a 18 px line icon on every item. It collapses to 72 px (icons only), and a tooltip appears on hover or keyboard focus. The collapse state is saved per user.
- **Top bar:** workspace switcher, global search ⌘K, alerts and profile.
- **Content:** 24 px padding. Page titles are 22 px and section titles 15 px; this compact scale is used everywhere.
- **Cards:** 16–20 px radius, light `line/light` (#EEF0F5) border; outline buttons and inputs use `line/strong` (#DCDFE7), `brand/white` surface on a `surface/cloud` page.
- **Primary action:** brand-gradient pill button, top right. Secondary actions use the Outline button.
- **Tables:** title row with filter, sort and export chips; header row on `surface/field`; status badge in the last column; pagination footer.
- **Safety in the UI:** spend, sends and posts always pass through approval and caps. Demo mode and impersonation always show a banner.
- **RTL:** the sidebar moves to the right, text is right-aligned, rows are mirrored and numbers stay left-to-right.
- **Mobile:** 390 × 844 frames, a 5-tab bar with icons (Home, Approvals, Compose, Inbox, Me), a drawer menu for every module, cards with 18 px radius.
- **Website:** every feature and solution page has an "Everything inside" section. Each option there is explained next to a real UI screenshot from the app, and the landing page has a 12-module product tour.


## Components (7) — Design system

| Screen | Node |
|---|---|
| Button | [5:13](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=5-13) |
| Nav Item | [5:20](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=5-20) |
| KPI Card | [5:21](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=5-21) |
| Badge | [5:25](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=5-25) |
| Input | [5:27](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=5-27) |
| Icons (39 icons) | [33:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=33-3) |
| Tooltip | [33:212](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=33-212) |

## Navigation & Menus (7) — Sidebar expand/collapse, tooltips, menus

| Screen | Node |
|---|---|
| Sidebar expanded (240) | [36:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-3) |
| Sidebar collapsed (72) + tooltip | [36:82](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-82) |
| Workspace switcher | [36:271](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-271) |
| User profile | [36:301](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-301) |
| Notifications panel | [36:342](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-342) |
| Quick create (+) | [36:372](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-372) |
| Table filter | [36:411](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=36-411) |

## Website (34) — §1 Public website

| Screen | Node |
|---|---|
| Landing | [9:15](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=9-15) |
| Features — Email marketing | [25:47](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-47) |
| Features — Social media | [25:166](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-166) |
| Features — AI writer | [25:285](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-285) |
| Features — Ads & boosting | [25:404](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-404) |
| Features — Analytics | [25:523](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-523) |
| Features — Integrations | [25:642](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-642) |
| Features — Mobile apps | [25:761](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-761) |
| Pricing | [25:880](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-880) |
| Solutions — Small business | [25:1073](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1073) |
| Solutions — E-commerce | [25:1179](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1179) |
| Solutions — Agencies & resellers | [25:1285](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1285) |
| Solutions — Creators | [25:1391](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1391) |
| Marketplace | [25:1497](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1497) |
| Integrations | [25:1644](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=25-1644) |
| Blog | [27:153](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-153) |
| Blog post | [27:272](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-272) |
| Help centre | [27:355](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-355) |
| Help article | [27:475](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-475) |
| Changelog | [27:564](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-564) |
| Status | [27:657](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-657) |
| About | [27:752](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-752) |
| Contact | [27:852](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-852) |
| Careers | [27:961](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-961) |
| Affiliate program | [27:1045](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1045) |
| Reseller program | [27:1154](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1154) |
| Legal — Terms of Service | [27:1246](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1246) |
| Legal — Privacy Policy | [27:1337](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1337) |
| Legal — Cookie Policy | [27:1428](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1428) |
| Legal — Acceptable Use Policy | [27:1513](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1513) |
| Legal — Data Processing Agreement | [27:1598](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1598) |
| Legal — Refund Policy | [27:1686](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1686) |
| Cookie consent | [27:1771](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1771) |
| 404 | [27:1851](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=27-1851) |

## Auth & Onboarding (15) — §1 Auth + onboarding

| Screen | Node |
|---|---|
| Sign up | [28:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-3) |
| Log in | [28:49](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-49) |
| Forgot password | [28:90](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-90) |
| Reset password | [28:113](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-113) |
| Verify email | [28:144](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-144) |
| Two-factor | [28:163](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-163) |
| Single sign-on | [28:197](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-197) |
| Accept invite | [28:220](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-220) |
| Try demo | [28:250](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-250) |
| 1 Company | [28:294](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-294) |
| 2 Email provider | [28:354](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-354) |
| 3 Contacts | [28:412](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-412) |
| 4 Social | [28:463](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-463) |
| 5 First campaign | [28:529](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-529) |
| Done | [28:585](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=28-585) |

## Buyer — Home & Email (4) — §3 Buyer

| Screen | Node |
|---|---|
| Home | [7:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=7-3) |
| Email campaigns | [12:42](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=12-42) |
| Contacts | [12:164](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=12-164) |
| Email providers | [12:274](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=12-274) |

## Buyer — Email (9) — §3 Email

| Screen | Node |
|---|---|
| Campaign wizard | [13:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-3) |
| Campaign report | [13:165](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-165) |
| Automations | [13:307](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-307) |
| Automation builder | [13:432](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-432) |
| Templates | [13:559](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-559) |
| Template editor | [13:677](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-677) |
| Provider templates | [13:812](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-812) |
| A/B tests | [13:936](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-936) |
| Deliverability | [13:1058](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=13-1058) |

## Buyer — Audience (7) — §3 Audience

| Screen | Node |
|---|---|
| Contact profile | [15:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-3) |
| Lists | [15:152](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-152) |
| Tags | [15:284](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-284) |
| Segment builder | [15:407](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-407) |
| Imports | [15:534](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-534) |
| Suppressions | [15:663](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-663) |
| Health analysis | [15:804](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=15-804) |

## Buyer — Social (9) — §3 Social

| Screen | Node |
|---|---|
| Calendar | [16:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-3) |
| Composer | [16:135](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-135) |
| Queue | [16:284](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-284) |
| Published | [16:408](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-408) |
| Drafts | [16:549](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-549) |
| Approvals | [16:659](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-659) |
| Accounts | [16:777](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-777) |
| Inbox | [16:922](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-922) |
| Duplicate checker | [16:1046](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=16-1046) |

## Buyer — Ads & AI (10) — §3 Ads, AI studio

| Screen | Node |
|---|---|
| Ad accounts | [17:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-3) |
| Boost post | [17:106](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-106) |
| Campaigns | [17:254](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-254) |
| Budgets & caps | [17:372](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-372) |
| Results | [17:509](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-509) |
| Writer | [17:637](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-637) |
| Post generator | [17:749](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-749) |
| Campaign planner | [17:883](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-883) |
| Brand kit | [17:997](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-997) |
| Usage & limits | [17:1109](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=17-1109) |

## Buyer — Analytics, Marketplace & Global (15) — §3 Analytics, Marketplace, global

| Screen | Node |
|---|---|
| Overview | [19:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-3) |
| Email | [19:131](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-131) |
| Social | [19:287](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-287) |
| Ads | [19:426](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-426) |
| Funnels | [19:541](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-541) |
| Attribution | [19:680](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-680) |
| Scheduled reports | [19:786](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-786) |
| Exports | [19:899](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-899) |
| Browse | [19:1012](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1012) |
| Item detail | [19:1155](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1155) |
| Purchases | [19:1251](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1251) |
| Installed | [19:1354](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1354) |
| Notifications centre | [19:1445](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1445) |
| Search results | [19:1566](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1566) |
| Help & AI assistant | [19:1673](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=19-1673) |

## Buyer — Settings (11) — §3 Settings

| Screen | Node |
|---|---|
| Workspace | [20:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-3) |
| Brands | [20:136](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-136) |
| Team & roles | [20:243](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-243) |
| Invites | [20:434](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-434) |
| Integrations & API | [20:560](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-560) |
| Webhooks | [20:734](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-734) |
| Notifications | [20:857](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-857) |
| Security | [20:983](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-983) |
| Privacy | [20:1120](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-1120) |
| Billing | [20:1251](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-1251) |
| Audit log | [20:1416](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=20-1416) |

## More screens (16) — §3–§5 detail screens

| Screen | Node |
|---|---|
| Segments list | [39:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-3) |
| Automation recipes | [39:164](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-164) |
| Post detail | [39:365](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-365) |
| Ad campaign detail | [39:527](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-527) |
| Report builder | [39:690](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-690) |
| Connect provider | [39:880](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-880) |
| Change plan | [39:1052](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1052) |
| Invoice detail | [39:1204](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1204) |
| My profile | [39:1349](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1349) |
| Getting started checklist | [39:1515](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1515) |
| Create tenant | [39:1707](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1707) |
| Plan editor | [39:1864](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-1864) |
| User detail | [39:2044](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-2044) |
| Role editor | [39:2210](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-2210) |
| Client detail | [39:2375](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-2375) |
| Order detail | [39:2507](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=39-2507) |

## Super Admin (29) — §4 Super Admin

| Screen | Node |
|---|---|
| Platform dashboard | [10:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=10-3) |
| Tenants | [10:131](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=10-131) |
| Tenant detail | [21:86](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-86) |
| Impersonation | [21:207](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-207) |
| Users | [21:287](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-287) |
| Plans & pricing | [21:418](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-418) |
| Coupons | [21:533](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-533) |
| Subscriptions | [21:644](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-644) |
| Invoices | [21:783](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-783) |
| Refunds | [21:905](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-905) |
| Usage & quotas | [21:1008](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-1008) |
| Provider health | [21:1127](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-1127) |
| AI usage & costs | [21:1266](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-1266) |
| Support tickets | [21:1382](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=21-1382) |
| Marketplace sellers | [23:492](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-492) |
| Listings review | [23:612](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-612) |
| Disputes | [23:741](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-741) |
| Seller payouts | [23:840](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-840) |
| Partners | [23:962](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-962) |
| Commission rules | [23:1101](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1101) |
| Content CMS | [23:1213](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1213) |
| Announcements | [23:1334](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1334) |
| Changelog | [23:1442](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1442) |
| Feature flags | [23:1544](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1544) |
| System settings | [23:1662](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1662) |
| System emails | [23:1779](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1779) |
| Logs | [23:1898](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1898) |
| Backups | [23:1986](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-1986) |
| Security events | [23:2110](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=23-2110) |

## Seller Panel (21) — §5 Seller

| Screen | Node |
|---|---|
| Reseller dashboard | [11:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=11-3) |
| Marketplace listings | [11:107](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=11-107) |
| Affiliate earnings | [11:210](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=11-210) |
| Client accounts | [24:87](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-87) |
| New client | [24:192](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-192) |
| Delegated access | [24:302](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-302) |
| White-label | [24:396](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-396) |
| Client billing | [24:502](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-502) |
| Commissions | [24:601](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-601) |
| Payouts | [24:672](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-672) |
| Vendor dashboard | [24:756](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-756) |
| Listing editor | [24:858](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-858) |
| Orders | [24:951](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-951) |
| Reviews | [24:1035](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1035) |
| Vendor payouts | [24:1102](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1102) |
| Vendor disputes | [24:1196](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1196) |
| Vendor analytics | [24:1260](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1260) |
| Affiliate dashboard | [24:1348](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1348) |
| Affiliate links | [24:1416](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1416) |
| Clicks & sign-ups | [24:1493](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1493) |
| Affiliate payouts | [24:1579](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=24-1579) |

## Mobile App (38) — §6 Mobile

| Screen | Node |
|---|---|
| Home | [12:383](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=12-383) |
| Approvals | [12:432](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=12-432) |
| Welcome | [29:16](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-16) |
| Log in | [29:31](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-31) |
| Two-factor | [29:57](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-57) |
| Workspace switcher | [29:82](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-82) |
| Compose | [29:109](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-109) |
| Calendar | [29:164](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-164) |
| Queue | [29:221](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-221) |
| Inbox | [29:273](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-273) |
| Conversation | [29:325](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-325) |
| Email campaigns | [29:347](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-347) |
| Campaign stats | [29:396](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-396) |
| Quick send | [29:432](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-432) |
| Spend approvals | [29:460](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-460) |
| AI writer | [29:516](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-516) |
| Notifications | [29:557](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-557) |
| Profile & settings | [29:594](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-594) |
| Help | [29:660](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-660) |
| Seller mini-panel | [29:689](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=29-689) |
| Onboarding 1 | [41:211](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-211) |
| Onboarding 2 | [41:225](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-225) |
| Onboarding 3 | [41:239](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-239) |
| Drawer menu | [41:253](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-253) |
| Search | [41:349](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-349) |
| Contacts | [41:399](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-399) |
| Contact detail | [41:469](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-469) |
| Analytics | [41:526](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-526) |
| Ad results | [41:613](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-613) |
| New campaign | [41:679](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-679) |
| Templates | [41:711](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-711) |
| Social accounts | [41:770](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-770) |
| Calendar month | [41:829](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-829) |
| Approval detail | [41:903](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-903) |
| Notification settings | [41:930](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-930) |
| Workspace settings | [41:969](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-969) |
| Admin mini-panel | [41:1017](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-1017) |
| Offline | [41:1066](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=41-1066) |

## States & Overlays (16) — All screens (states)

| Screen | Node |
|---|---|
| Loading (skeleton) | [30:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-3) |
| Empty — campaigns | [30:32](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-32) |
| Empty — contacts | [30:40](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-40) |
| Empty — search | [30:48](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-48) |
| Error 500 | [30:56](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-56) |
| Permission denied 403 | [30:67](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-67) |
| Offline | [30:75](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-75) |
| Toasts | [30:83](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-83) |
| Confirm delete | [30:103](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-103) |
| Limit reached — upgrade | [30:116](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-116) |
| Command palette ⌘K | [30:127](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-127) |
| Row actions menu | [30:150](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-150) |
| Form validation | [30:165](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-165) |
| System banners | [30:180](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-180) |
| Session expired | [30:191](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-191) |
| Tooltip & date picker | [30:198](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=30-198) |

## Urdu RTL (4) — All screens (RTL rules)

| Screen | Node |
|---|---|
| Dashboard home | [32:3](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=32-3) |
| Email campaigns | [32:115](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=32-115) |
| Log in | [32:211](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=32-211) |
| Mobile home | [32:232](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=32-232) |

## Next (TASK-0147)
- Write a spec for every screen: purpose, data, actions, permissions per role, states and copy in English and Urdu.
- Responsive breakpoints: 1024 / 768 / 390.
- Dark-surface variants.
- Code Connect mapping once `packages/ui` exists.


## Brand assets and the no-placeholder rule (2026-10-17)
- **Logo:** the owner-supplied VSN mark is the `Logo/Mark` component ([47:10](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN?node-id=47-10)). It is used in every sidebar, website header, auth screen, mobile app and RTL screen. On gradient brand panels it sits on a white rounded plate.
- **Image library:** 16 Figma-AI images on the Components page, frame "Image library":
  - Photos: fashion, sale banner, product launch, office, unboxing, café, gym, agency.
  - Illustrations: email, social, AI, ads, 3 onboarding illustrations, blog cover.

  Every former gradient placeholder now uses one of these images or a real UI screenshot.
- **Icons:** placeholder squares were replaced with `Icon/*` line icons on tinted tiles.
- **Avatars:** person avatars use initials. List rows for things that are not people (alerts, steps, guides) use icons.
- **Rule:** new screens must not use gradient placeholders. Use library images, UI screenshots, icons or initials.

## Flows — Connect & Pay (2026-10-17)

The Figma page `Flows — Connect & Pay` (node 73:10) holds the end-to-end wizards the owner reported as missing. It has 27 screens.

- **Social connect wizard:**
  1. Choose network
  2. Sign in & allow (Meta consent and the permissions requested)
  3. Pick pages & accounts
  4. Posting rules
  5. Connected
  - Plus two support screens: Error — missing permission, and Reconnect — token expiring.
- **Ads connect:** choose the ad account, then set a hard spend cap and an approver.
- **Subscription checkout:**
  1. Choose plan
  2. Billing details
  3. Payment method (card, SEPA, PayPal, bank transfer, coupon)
  4. Bank verification (3-D Secure)
  - Then: Processing, Payment successful, Payment failed.
- **Billing:**
  - Payment methods
  - Add card
  - Trial ending
  - Past due (payment retry)
  - Cancel subscription (reason → offer → confirm)
  - Payment history
- **Ad wallet:** Top up, Top-up successful.
- **Marketplace:** Checkout, Purchase successful.
- **Seller:** Payout setup through Stripe Connect.
- **Admin:** Payment detail with an event timeline.

All payment screens represent Stripe test mode. No spend happens without approval and caps.
