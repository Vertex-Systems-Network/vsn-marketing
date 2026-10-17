# ADR-0009: Web monorepo tooling and muted-text contrast

- Status: Accepted
- Date: 2026-10-17
- Task: TASK-0104
- Changes: ADR-0008 D4 (package manager and task runner)

## Context

ADR-0008 D4 chose pnpm workspaces with Turborepo. The repository's certified supply-chain gates are built on `package-lock.json`:
- `npm ci` in `application-ci` and `shipping-fast-gate`;
- `npm audit` in `security-ci`;
- SBOM reproducibility;
- lockfile drift.

Switching to pnpm would invalidate those gates and their evidence. With only one web app and two small packages, a task runner adds configuration without any build-time gain.

The Figma token `text/muted` (`#7e8083`) gives 3.39:1 on `surface/cloud`, which fails WCAG 2.2 AA (4.5:1). SRS NFR-ACC-01 and UX-GUIDELINES A-01 require AA.

## Decisions

1. **npm workspaces** (`apps/*`, `packages/*`) replace pnpm. The single root `package-lock.json` stays the source of truth for every existing gate. Workspace scripts are run as `npm run <script> --workspace <name>`.
2. **No Turborepo for now.** Add it only when the build graph has three or more buildable apps or CI build time exceeds 5 minutes. When that happens, set the `packageManager` field together with Turborepo.
3. **`text/muted` becomes `#636669`** in code (light theme; 4.94:1 on cloud, 5.30:1 on field), and `#a3a6ad` in the new dark theme. The Figma `VSN Tokens` variable is updated to match, so Figma stays the visual source.
4. **Focus indicator:** a 3 px outline in `brand/gradient-start` (≥ 3:1 on every surface), plus the cyan `focus` token as a decorative outer glow. The cyan alone is only 2:1 on white.
5. **Dark theme** values are defined in `packages/ui-tokens`. A unit test asserts AA text contrast for both themes and keeps the CSS in sync with the TypeScript values.

## Consequences

- PRODUCT-STACK-INSTRUCTIONS §2 and ENGINEERING-GUIDELINES read "npm workspaces" wherever they said pnpm/Turborepo.
- CI builds the web app with `npm run build:web` inside the existing application job; no new runner jobs are added.
