# PHASE-06 — Canonical Content, Template, Creative, and Asset Studio

Status: **COMPLETED — TASK-0036 PHASE-06 certification accepted; PHASE-07 research successor activated.**

## Purpose

Own reusable channel content and creative assets independently of providers. VSN must preserve one canonical, versioned source of truth that can be edited through safe visual/code workflows and rendered into provider/channel-specific outputs without allowing provider templates, unsafe authored markup, destructive asset edits, or later publishing concerns to leak into the core model.

## Preplanned task sequence

1. `TASK-0031` — Research current content editors, template formats, asset workflows, rendering security, and channel media requirements.
2. `TASK-0032` — Implement canonical content/template/version/component model.
3. `TASK-0033` — Implement canonical asset library and variant pipeline.
4. `TASK-0034` — Implement safe editor/render/compiler pipeline.
5. `TASK-0035` — Implement brand knowledge/kit, reusable components, approvals, and provider template synchronization.
6. `TASK-0036` — Certify PHASE-06.

Only TASK-0031 may be activated by the next guarded phase transition. Later tasks remain non-executable until their dependencies and research gates are satisfied.

## Research-derived invariants

The dated evidence pack is `.ai/research/PHASE-06/TASK-0031-RESEARCH.md`.

- VSN canonical content is provider-neutral. Drag/drop, code/import and future AI-assisted authoring must converge on the same validated canonical document/version model.
- Templates and reusable components are compositional references with explicit version/dependency provenance. A published or execution-pinned version is immutable; editing creates a new version rather than rewriting historical execution inputs.
- Brand kit/default styles and reusable modules are separate concerns from campaign/publishing state. Global/reusable components must have explicit scope and impact visibility.
- Email is a compiled target, not the canonical storage format. Responsive/client-specific output may use a renderer/compiler, but the chosen renderer version and configuration must be reproducible and security constrained.
- Authored HTML is untrusted input. Executable script, event-handler and unsafe URL/markup behaviors are not permitted in canonical email output or preview. Sanitization/validation occurs before trusted rendering, and post-sanitization mutation must not reintroduce unsafe markup.
- Preview is not permission to execute arbitrary authored code. Preview/render workloads require isolation, bounded resources, deterministic inputs and no implicit secret access.
- Asset originals are immutable and content-addressable or hash-verifiable. Edits/transforms create derived variants with lineage, MIME/dimension metadata, workspace ownership, rights/provenance and storage references.
- Accessibility is a first-class validation boundary: semantic structure, meaningful text alternatives, contrast/reflow expectations and avoidance of text-as-image must be testable.
- Channel/media constraints are provider/version/effective-date capabilities. One provider's supported content types, dimensions or upload workflow must not become a global constant.
- Preview/test matrices are explicit. At minimum, responsive email output requires device/client-oriented testing, while other channels require capability-aware previews and deterministic fallback behavior.

## Security and privacy boundaries

- No arbitrary JavaScript from authored content.
- No unbounded remote includes or filesystem path traversal in renderer/compiler inputs.
- No secret material embedded in canonical templates, components or assets.
- No cross-workspace asset/template/component references without explicit authorized sharing semantics.
- No provider credentials are exposed to browser-side editor code.
- No destructive overwrite of historical/published template versions or original assets.
- No HTML sanitizer bypass via later mutation of already-sanitized output.
- No live channel publication in TASK-0031.

## Canonical model direction to validate in TASK-0031

The research should converge on contracts for:

- content document and immutable content version;
- template and immutable template version;
- typed component/block tree plus reusable component references;
- variable schema, localization slots and deterministic data-binding contract;
- brand/style tokens separate from content data;
- asset/original/variant metadata with provenance and transformation lineage;
- render target and render artifact identity pinned to source versions and renderer version;
- preview/test result identity and validation findings;
- provider/channel capability evidence with version/effective date;
- approval/readiness metadata without pulling PHASE-07 campaign scheduling forward.

The exact persistence/schema implementation remains TASK-0032/TASK-0033 scope.

## Phase completion evidence

PHASE-06 cannot be certified merely because a visual editor renders successfully. TASK-0036 must prove at minimum:

- immutable/reproducible versioning and dependency resolution;
- cross-workspace template/component/asset isolation;
- deterministic renderer/compiler outputs from pinned inputs;
- adversarial HTML/rendering security and preview isolation;
- asset provenance, transform lineage and safe variant generation;
- accessibility validation plus representative visual/client regression coverage;
- provider-versioned channel/template synchronization without provider-authoritative source drift;
- exact-head continuity, backend/integration, static-analysis, frontend/browser and security/supply-chain gates.

## Explicitly out of scope

- PHASE-07 campaign/publishing approvals, scheduling and calendar execution;
- PHASE-08 segment compiler;
- PHASE-09 journey runtime;
- later autonomous AI publishing;
- live provider posting during TASK-0031;
- provider-owned templates becoming the VSN canonical source;
- unrestricted executable HTML/JavaScript authoring.
