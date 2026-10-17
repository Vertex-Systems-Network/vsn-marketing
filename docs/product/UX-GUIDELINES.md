# VSN Marketing — UI/UX guidelines

| Field | Value |
|---|---|
| Version | 1.0 — 2026-10-17 |
| Owner task | TASK-0147 |
| Design file | Figma [VSN Marketing — Product App](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN), team folder `637889245`; frames are indexed in [DESIGN-INDEX](DESIGN-INDEX.md) |
| Token source | Figma `JqukmwKhVyObh1SQ6gsKR0` (page `🎨 Design System`) → variable collection `VSN Tokens` → `packages/ui-tokens` |
| Screen specs | [SCREEN-SPECS](SCREEN-SPECS.md) |
| Requirements | [SRS](SRS.md): UI-01..03, NFR-ACC-01, NFR-I18N-01, FR-I18N-* |

## 1. Design principles

1. **Clear before clever.** One primary action per screen. Secondary actions are outline buttons, and destructive actions use the dark style plus a confirmation.
2. **Honest numbers.** Show real opens and real clicks first, with machine and bot counts available on demand. Never round in the customer's favour, and label every estimate.
3. **Safe by default.**
   - Sending, publishing and spending always show who approves and what the cap is.
   - Irreversible actions need typed confirmation (delete workspace, refund).
4. **Fast to value.** Every empty state has one action that fills it, and the onboarding checklist stays until it is complete or dismissed.
5. **Compact density.**
   - Base text is 13 px in panels and 14–16 px on the website.
   - Tables have 11 px vertical cell padding.
   - Cards use a 14 px radius.
6. **Real imagery, no placeholders.** Use the image library, `Icon/*` components and initials avatars (DESIGN-INDEX "no-placeholder rule").

## 2. Tokens

Code uses token names only. Hex values are listed for reference and change only through Figma.

| Group | Tokens |
|---|---|
| Brand | `brand/gradient-start #625ba8`, `brand/gradient-mid #6188c6`, `brand/gradient-end #5ac8d6`; hover `#544d96/#5277b4/#47b5c3`; pressed `#47418a/#4a6ba3/#3e9fad` |
| Ink | `brand/white #ffffff`, `brand/ink #000000`, `brand/ink-2 #3f4245`, `text/muted #7e8083` |
| Surface | `surface/cloud #ebedf4` (app background), `surface/field #f4f5f9` (inputs, table headers), `surface/night #1a1c20` (code, dark mode base) |
| Lines | `line/light #EEF0F5` (card and row borders), `line/strong #DCDFE7` (chips, off toggles) |
| State | `state/error #d14b5a`. Success, warning and info use badge styles (tinted `surface/cloud` with ink text) to keep contrast ≥ 4.5:1 |
| Focus | Buttons: 3 px `#5ac8d6` ring. Inputs: 1.5 px `#625ba8` border plus a 4 px `rgba(98,91,168,.18)` ring |
| Disabled | Opacity .38 for buttons, .55 for fields |
| Type | Plus Jakarta Sans 400/500/600/700/800. Urdu headings: Noto Nastaliq Urdu. Urdu UI text: Noto Sans Arabic |
| Type scale (panel) | 22 page title · 15 card title · 13 body · 12 secondary · 11 caption/label |
| Type scale (website) | 56/40/28 headings · 18 lead · 16 body |
| Radius | `radius/field 12`, card 14, panel 16, pill 999 |
| Spacing | 4-pt grid: 4, 8, 10, 12, 16, 20, 24, 32 |
| Button heights | 58 (L, website) · 48 (M, default) · 40 (S) |

Dark mode is a planned addition (TASK-0138 visual QA). It maps `surface/cloud → surface/night` and inks to white, and must keep every contrast ratio from §5.

## 3. Component inventory

Components already in Figma (Components page) and the shadcn/ui primitive each maps to in code:

| Component | Figma | Code (shadcn/Radix) | Variants and states |
|---|---|---|---|
| Button | 5:13 | `Button` | Primary / Outline / Dark × L/M/S; default, hover, pressed, focus, disabled, loading |
| Nav item | 5:20 | sidebar `NavLink` | Default, Active, Collapsed, Collapsed active; icon swap; tooltip when collapsed |
| KPI card | 5:21 | `Card` + `Stat` | label, value, delta (up / down / neutral) |
| Badge | 5:25 | `Badge` | Neutral; status words (Active, Paused, Failed, Paid…) |
| Input | 5:27 | `Input` / `Select` / `Textarea` | default, focus, filled, error with message, disabled |
| Tooltip | 33:212 | `Tooltip` | top/right; used for collapsed nav and icon buttons |
| Icons (39) | 33:3 | lucide-react equivalents | 20 px, 1.6 stroke, `currentColor` |
| Logo / Mark | 47:10 | `Logo` | mark-only (26/28 px), mark + wordmark; white backplate on gradients |
| Table | screens | TanStack Table + `Table` | header, row, selectable, status column, pagination, empty, loading skeleton |
| Tabs, Stepper, Toggle, Meter, Chart card, Alert, Toast, Dialog, Command palette, Date picker, Upload, Code block, Plan card, Payment card | screens and States page | `Tabs`, custom `Stepper`, `Switch`, `Progress`, Recharts, `Alert`, `Sonner`, `Dialog`, `Command`, `Calendar`, dropzone, `pre`, custom | see the States & Overlays page |

New components must first be added to Figma, then to `packages/ui`, each with every state.

## 4. Layout and navigation

- **Web panel:**
  - Sidebar 240 px; collapses to 72 px, showing icons with tooltips. The collapse state is remembered per user.
  - Top bar holds search (⌘K), notifications, a help (?) icon, the user name and an avatar.
  - Content padding is 24 px, with a 16 px gap between blocks.
- **Panels:**
  - Buyer, Super Admin and Seller (reseller, vendor, affiliate) share one shell.
  - The panel tag under the logo names the workspace and plan, or the panel type.
  - The workspace switcher lives in the user menu.
- **Page header:** breadcrumb (11 px) → title (22 px) and subtitle → actions on the right (maximum 1 primary and 2 outline).
- **Wizards:** a stepper on top; Back/Continue in the header or at the bottom; progress is saved after each step; step 1 is never a dead end.
- **Breakpoints:**
  - ≥ 1280 px: full layout.
  - 1024–1279 px: the sidebar collapses by default.
  - 768–1023 px: tablet; the sidebar becomes a drawer.
  - < 768 px: mobile web, with single-column cards and tables turned into card lists.
- **Mobile app:**
  - 390 × 844 base frame.
  - Bottom tab bar: Home, Approvals, Compose, Inbox, Me.
  - Other areas are reached through the drawer.
  - 18 px side padding.

## 5. Accessibility (WCAG 2.2 AA)

| Rule | Requirement |
|---|---|
| A-01 Contrast | Text ≥ 4.5:1 (large text ≥ 3:1); UI component boundaries and focus indicators ≥ 3:1 against adjacent colours. `text/muted` is used only on white or `surface/field` |
| A-02 Keyboard | Every flow is completable by keyboard; logical tab order; Esc closes overlays; no keyboard traps; skip-to-content link |
| A-03 Focus visible | 3 px ring (2.4.7). The focused element is never fully hidden by sticky headers (2.4.11) |
| A-04 Target size | Pointer targets ≥ 24 × 24 CSS px (2.5.8); mobile touch targets ≥ 44 × 44 |
| A-05 Dragging | Every drag action (calendar reschedule, builder, sorting) has a non-drag alternative (2.5.7) |
| A-06 Forms | Visible labels; errors in text next to the field and summarized on top; no colour-only meaning; autocomplete attributes; no re-entry of data already provided in the same flow (3.3.7) |
| A-07 Authentication | No cognitive-test-only login; paste and password managers allowed; passkeys and 2FA supported (3.3.8) |
| A-08 Status | Toasts and live counters use `aria-live=polite`; errors use `assertive` |
| A-09 Motion | Respect `prefers-reduced-motion`; nothing flashes more than 3 times per second |
| A-10 Media | Alt text required on uploaded images in the composer; video captions where provided |
| A-11 Charts | Every chart has a data table or summary text alternative |
| A-12 Help | Help is in the same place on every screen (3.2.6): the top-bar "?" and the AI assistant |
| A-13 Testing | axe has 0 serious/critical issues per screen in the Playwright E2E suite, plus a manual screen-reader pass (VoiceOver and NVDA) before each phase certification |

## 6. Right-to-left (Urdu) rules

1. Set `dir="rtl"` and `lang="ur"` on `<html>`. Use logical CSS properties only (`margin-inline-start`, `padding-inline`, `inset-inline`); never `left` or `right`.
2. **Mirror:**
   - sidebar position (right side);
   - stepper and progress direction;
   - breadcrumbs;
   - back/forward and chevron icons;
   - the collapse/expand icons;
   - toggle knob direction;
   - table column order (the first column sits on the right);
   - tab order.
3. **Do not mirror:**
   - the logo;
   - brand gradient direction;
   - media play controls;
   - chart time axes (time still runs left → right);
   - phone numbers, emails, URLs and code;
   - currency amounts.
4. **Fonts:**
   - Headings use Noto Nastaliq Urdu at line height ≥ 1.6. Increase sizes by about 10%, because Nastaliq looks smaller.
   - Body and UI text use Noto Sans Arabic.
   - Latin fragments inside Urdu stay in Plus Jakarta Sans.
5. **Numbers:** Western digits by default, with Eastern Arabic-Indic digits as an option. Dates use the Gregorian calendar with Urdu month names.
6. **Layout room:** Urdu strings can be up to 40% longer, so never use fixed-width buttons with text; let labels wrap to 2 lines in nav items.
7. RTL screens exist in Figma (Urdu RTL page). Every new panel template needs an RTL screenshot test (NFR-I18N-01).

## 7. Content and tone (English and Urdu)

| Aspect | English | Urdu |
|---|---|---|
| Voice | Friendly, direct, expert; "you" for the user, "we" for VSN | Polite and simple. Use "آپ" (never "تم"). Everyday Urdu; keep common English product terms (email, campaign, post) in Urdu script |
| Buttons | Verb first, ≤ 3 words: "Send campaign", "Connect account", "Pay €811.82" | Verb last: "کیمپین بھیجیں"، "اکاؤنٹ جوڑیں"، "ادائیگی کریں" |
| Errors | What happened + how to fix, no blame: "Your card was declined. Try another card or approve in your banking app." | "آپ کا کارڈ قبول نہیں ہوا۔ دوسرا کارڈ استعمال کریں یا بینک ایپ میں منظوری دیں۔" |
| Empty states | Title + one line + one action: "No campaigns yet — Create your first campaign" | "ابھی کوئی کیمپین نہیں — پہلی کیمپین بنائیں" |
| Money and limits | Always show amount, currency, cap and approver | Same, with the currency symbol kept as-is |
| Numbers | Abbreviate ≥ 10,000 as 10k in KPIs; full numbers in tables | Same digits rule as §6.5 |
| Avoid | Jargon (ESP, MPP) in the main UI (put it in tooltips); exclamation marks in errors; "simply/just" | Overly formal Persianized vocabulary |
| Roman Urdu | Not used in the product UI. It is supported as AI writer input and output (FR-I18N-04) | — |

Legal pages are professionally translated, not machine-only (SRS §8).

## 8. State patterns (every screen)

| State | Pattern |
|---|---|
| Loading | Skeletons that match the final layout; no spinners for more than 300 ms of content |
| Empty | Illustration (image library) + title + one-line reason + primary action; a separate variant for filters with no results ("Clear filters") |
| Error | An inline alert for a failed block with "Retry"; a full-page error (500) with a support link and request ID |
| Permission | 403 page naming the missing permission and who can grant it |
| Offline | Top banner on web; an offline screen on mobile with cached data and the last synced time |
| Limit reached | Upgrade dialog with current usage, next plan and price |
| Pending approval | Badge "Pending approval" + approver name; actions are disabled with a tooltip |
| Success | Toast for small actions; a result page for money and connections (payment success, accounts connected) |
| Destructive | Dialog with consequences; typed confirmation for irreversible actions |

## 9. Motion

- Durations: 150 ms for hover and press, 200 ms for overlays, 250 ms for page transitions.
- Easing: `cubic-bezier(.2,.8,.2,1)`.
- No parallax in the product UI. Website hero animations stop under `prefers-reduced-motion`.
