# TASK-0031 Research Evidence — Content Editors, Templates, Assets, Rendering Security, Accessibility, and Channel Media

Date: 2026-09-19
Status: staged official/product-source research for PHASE-06 activation
Task: TASK-0031
Scope: provider-neutral content/template authoring, reusable components, creative asset lifecycle, responsive email rendering, safe custom-code pathways, accessibility, previews/testing, and versioned channel media capabilities.

## Research questions

1. Which editor workflows are common across mature marketing/content products?
2. What should VSN store canonically versus compile/export for a target channel?
3. How should reusable templates/components, brand defaults, variables and revisions behave?
4. What asset metadata and transformation lineage are required for safe reuse and provider variants?
5. Which rendering/preview boundaries are required for user-authored HTML and external resources?
6. Which accessibility expectations should be enforceable by editor and certification gates?
7. How should channel media constraints be represented so provider differences do not leak into core content semantics?

## Sources reviewed

| Source | Accessed | Material finding |
|---|---:|---|
| https://mailchimp.com/help/design-an-email-new-builder/ | 2026-09-19 | Mailchimp's current builder uses drag/drop content blocks and layouts, reusable templates, direct inline editing, dynamic content, and desktop/mobile-oriented design controls. |
| https://mailchimp.com/help/create-a-template-with-the-template-builder/ | 2026-09-19 | Templates are reusable starting points; users can use saved/prebuilt designs or custom HTML and preview on desktop/mobile and across inbox clients. |
| https://mailchimp.com/help/use-code-content-blocks-new-builder/ | 2026-09-19 | Custom HTML is supported but detectable JavaScript is removed; embedded/inline CSS is favored because external CSS is unreliable in email. |
| https://mailchimp.com/help/use-the-content-studio/ | 2026-09-19 | Asset libraries organize uploaded/connected content; image edits create a new uploaded version rather than modifying the original source asset. |
| https://knowledge.hubspot.com/marketing-email/create-and-send-marketing-emails | 2026-09-19 | HubSpot exposes use-case templates, brand-kit application, visual editing, preview/testing and permissioned publishing boundaries. |
| https://knowledge.hubspot.com/design-manager/create-page-email-and-blog-templates-in-the-layout-editor | 2026-09-19 | Templates define reusable structure through drag/drop modules; email templates allow custom HTML/inline styles but do not support attached CSS/JavaScript files. |
| https://knowledge.hubspot.com/design-manager/structure-and-customize-template-layouts | 2026-09-19 | Modules are compositional building blocks; template changes can affect dependents; clone/source/revision-history/dependency visibility are explicit workflow concepts. |
| https://knowledge.hubspot.com/design-manager/create-and-edit-modules | 2026-09-19 | Reusable modules can be local or global; editing global content updates all uses, showing the need for explicit scope/impact semantics. Email modules do not include CSS or JavaScript. |
| https://help.brevo.com/hc/en-us/articles/360016831820-Overview-of-the-Drag-Drop-email-editor | 2026-09-19 | Brevo separates sections/layout structure from content blocks, supports prebuilt templates, global style controls, HTML blocks and real-time visual editing. |
| https://help.brevo.com/hc/en-us/articles/31611992483346-About-the-new-drag-and-drop-email-editor | 2026-09-19 | Current editor workflow includes saved sections, brand/style controls, inline HTML editing and unified repeatable/dynamic data sources. |
| https://plugin.stripo.email/editor-configuration/modules-library | 2026-09-19 | Stripo models reusable modules at multiple structural levels (stripe/structure/container) to support design-system reuse and consistent branding. |
| https://documentation.mjml.io/ | 2026-09-19 | MJML demonstrates a semantic component model compiled to responsive HTML, includes accessibility-oriented output and strict validation. Current MJML 5 documentation disables file includes by default unless explicitly enabled, highlighting include-scope security concerns. |
| https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html | 2026-09-19 | When users author HTML, OWASP recommends HTML sanitization rather than relying only on output encoding; post-sanitization mutation can invalidate safety and sanitizer dependencies must be kept patched. |
| https://www.w3.org/TR/WCAG22/ | 2026-09-19 | WCAG 2.2 provides enforceable accessibility expectations including reflow at 320 CSS px and restrictions on images of text, alongside text alternatives/contrast/semantic requirements. |
| https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-01 | 2026-09-19 | LinkedIn's versioned Posts API exposes different organic/sponsored support by content type and requires asset upload identities for image/video/document content, demonstrating that channel media rules are provider capabilities rather than universal constants. |
| https://developers.google.com/workspace/gmail/api/guides/sending | 2026-09-19 | Gmail sends MIME messages as encoded raw message resources, reinforcing that channel delivery artifacts can differ from the canonical authoring representation. |

## Market workflow synthesis

### 1. Visual blocks + reusable modules are the dominant authoring primitive

Mailchimp, HubSpot, Brevo and Stripo all expose a hierarchy of reusable content/layout primitives rather than treating an email as one mutable HTML string. The naming differs (blocks, layouts, modules, sections, structures, containers), but the common shape is a typed composition tree with editable properties and reusable subtrees.

**VSN requirement:** canonical content must support typed component instances and reusable component references. Provider/export HTML is a render artifact, not the canonical editor state.

### 2. Multiple authoring modes should converge on one canonical contract

Mature products commonly support prebuilt templates, drag/drop editing, reusable blocks and an advanced code path. The code path should not create a second source of truth with different version semantics.

**VSN requirement:** visual editing, safe code/import and future AI-assisted generation must resolve to the same validated content/template version model or to an explicitly opaque safe-code component with bounded capabilities.

### 3. Template/component scope and revision impact must be explicit

HubSpot exposes local/global module scope, dependent content, cloning and revision history. Stripo emphasizes reusable shared modules. This means a naive "edit template in place" model is unsafe for reproducibility.

**VSN requirement:** execution-pinned/published versions are immutable. Changes create new versions. Reusable components carry explicit scope and dependency references; tooling must be able to identify impacted dependents before promotion.

### 4. Brand defaults are separable from content

HubSpot and Brevo apply brand kits/libraries and global styles around reusable content. Brand tokens are not the same thing as campaign content.

**VSN requirement:** brand/style tokens should be versioned references consumed by templates/components, with deterministic resolution at render time. PHASE-06 may own brand knowledge/components; PHASE-07 continues to own campaign execution.

### 5. Asset libraries need immutable originals and derived variants

Mailchimp's asset workflow keeps uploaded content in a library and creates a new upload when an image is edited rather than mutating the original source. Channel-specific creative inevitably needs resized/cropped/transcoded variants.

**VSN requirement:** store immutable original asset identity plus content hash, MIME, dimensions/duration where applicable, workspace ownership, rights/provenance and storage reference. Derived variants record transform parameters, parent asset/version, output metadata and renderer/processor version.

### 6. Email should be compiled from canonical content

MJML demonstrates the value of a semantic component source compiled to responsive HTML; Gmail ultimately transports MIME/raw message content. Mature email builders also expose preview/client testing because output compatibility varies.

**VSN requirement:** canonical content is not raw provider HTML. Email render artifacts should be deterministically compiled from pinned content/template/component/asset/brand versions and a pinned renderer configuration. The chosen renderer is an implementation decision for TASK-0034; TASK-0031 does not lock MJML as mandatory.

### 7. Custom HTML is a security boundary

Mailchimp removes JavaScript from custom code; HubSpot email templates/modules do not support JavaScript; OWASP recommends sanitizing user-authored HTML and warns that modifying sanitized output can reintroduce vulnerabilities.

**VSN requirement:** authored HTML remains untrusted. Reject scripts, event handlers, unsafe URLs and unsupported markup; sanitize/validate before trusted use; do not mutate sanitized output through unsafe libraries; isolate previews; keep sanitizer/compiler versions patched and pinned in evidence.

### 8. Renderer includes and external resources require explicit policy

Current MJML 5 documentation disables includes by default unless explicitly enabled. Any compiler that can read local files or remote resources creates path traversal, SSRF, secret-access and reproducibility risk.

**VSN requirement:** file includes are denied by default or constrained to an explicit virtual workspace; remote fetches are allowlisted/policy-gated; preview/render processes have no ambient credentials and bounded network/filesystem access.

### 9. Accessibility belongs in authoring and certification

WCAG 2.2 requirements such as text alternatives, contrast, semantic structure, reflow and avoidance of images of text cannot be recovered reliably after arbitrary rasterization/export.

**VSN requirement:** canonical components need accessibility fields/semantics; editor validation should surface missing alt text/structure issues; certification should include machine checks plus representative rendered-output review.

### 10. Channel media support must be capability data

LinkedIn's versioned API distinguishes organic and sponsored support for text, image, video, documents, carousels and multi-image content and requires uploaded asset identifiers for media. Other providers will differ and evolve.

**VSN requirement:** content semantics stay canonical while provider/channel capability records describe supported media kinds, cardinality, size/format/aspect constraints, upload prerequisites and effective API versions. Unsupported mappings produce deterministic validation/fallback errors rather than silent content loss.

## Proposed PHASE-06 invariant set

1. Canonical content/template/component data is provider-neutral and versioned.
2. Published/execution-pinned versions are immutable; edits create new versions.
3. Reusable component scope and dependency impact are explicit.
4. Variables/localization have typed schemas; unresolved or incompatible variables fail validation.
5. Asset originals are immutable; variants preserve transform lineage and provenance.
6. Renderer/compiler identity and configuration are part of render artifact provenance.
7. User-authored HTML is untrusted and cannot execute arbitrary JavaScript.
8. Renderer includes/network/filesystem access are deny-by-default or explicitly scoped.
9. Accessibility fields and validation are part of the canonical model, not optional post-processing.
10. Provider media/template requirements are versioned capability data, not core constants.
11. Provider-synchronized templates remain derivatives/reconciled copies; VSN canonical versions remain authoritative.
12. No PHASE-07 publishing/scheduling semantics are pulled into the editor/content model.

## Planned task mapping

- TASK-0031: freeze this research and convert it into reviewed PHASE-06 acceptance contracts.
- TASK-0032: canonical content/template/version/component model, variables/localization and deterministic dependencies.
- TASK-0033: asset originals, provenance/rights metadata, variants/transforms and storage isolation.
- TASK-0034: visual/code editor pathways, sanitizer, renderer/compiler, preview and regression infrastructure.
- TASK-0035: brand kit/knowledge, reusable approved components and provider-template synchronization/reconciliation.
- TASK-0036: adversarial rendering security, accessibility, visual/client regression, asset isolation and provider-sync certification.

## Explicit non-goals for TASK-0031

- No production visual editor implementation.
- No arbitrary JavaScript or executable user code.
- No live remote include/fetch capability.
- No live social/email publication.
- No campaign scheduling, unified calendar or PHASE-07 publishing workflow.
- No segment compiler or journey runtime.
- No provider-owned template becoming canonical VSN state.
- No autonomous AI publishing.

## Open items that must remain research/configuration decisions

- Canonical document representation: normalized JSON/component AST shape and schema-version strategy.
- Whether MJML, an internal compiler, or another engine becomes the email render implementation; no framework is selected by this research alone.
- HTML/CSS allowlist and sanitizer strategy by render target.
- Preview isolation/runtime design and bounded resource policy.
- Email-client compatibility matrix and visual-regression service/tooling.
- Image/video processing libraries, derivative policy and object-storage lifecycle.
- Asset rights/license/provenance taxonomy and retention rules.
- Localization fallback/pluralization/message-format contract.
- Provider-specific media limits, aspect ratios, file sizes, template APIs and API-version lifecycles.
- Approval semantics for shared/global components without pulling PHASE-07 campaign approval forward.

## Research disposition

Plan confirmation: PHASE-06 sequencing remains valid.

New hard acceptance requirements: immutable canonical versions, scoped reusable dependencies, immutable asset originals with variant lineage, deterministic renderer provenance, deny-by-default rendering security, accessibility-aware component contracts and provider-versioned media capabilities.

No ADR is required at this staging point because the evidence confirms the already-planned Assets/provider-neutral boundary. An ADR becomes necessary if TASK-0031 chooses a renderer/storage architecture that changes established module boundaries or introduces a new privileged execution service.
