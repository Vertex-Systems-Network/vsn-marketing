# TASK-0063 Research Pack — Deterministic Assignment and Exposure

- researched_at: 2026-10-02T06:30:00+00:00
- task: TASK-0063
- phase: PHASE-11
- scope: tenant-scoped versioned experiment assignment, holdout and exposure persistence
- researcher: Codex Supervisor

## Sources

| Source | Type | Accessed | Impact |
|---|---|---|---|
| [Eppo assignment logging](https://docs.geteppo.com/sdks/event-logging/assignment-logging/) | First-party docs | 2026-10-02 | Log allocation identity and version; avoid assignment-as-exposure. |
| [Eppo holdouts](https://docs.geteppo.com/feature-flagging/concepts/holdout-config/) and [mutual exclusion](https://docs.geteppo.com/feature-flagging/concepts/mutual_exclusion/) | First-party docs | 2026-10-02 | Explicit holdout and collision groups. |
| [Statsig implementation](https://docs.statsig.com/experiments/implementation/implement) | First-party docs | 2026-10-02 | Stable assignment and exposure/outcome integration must be distinct. |
| [NIST SP 800-107 Rev. 1 HMAC](https://csrc.nist.gov/pubs/sp/800/107/r1/final) | Standard/security guidance | 2026-10-02 | Keyed digest conceals subject identifier; key/version rotation needs explicit control. |

## Current external reality and market workflow

Experiment platforms separate evaluated treatment from the subject seeing it; a persisted assignment is not proof of exposure. Holdouts are explicitly addressable. Experiment allocations and mutually exclusive layers must not silently drift while subjects participate. An operator previews a frozen version then activates; a new candidate version is a new experiment, not an in-place rewrite.

## Security/privacy findings

Store an opaque keyed subject digest, never email or raw contact identity in assignment/exposure rows. All persistence is workspace and brand bound; exact brand null semantics matter. No direct caller grants itself an experiment permission. An independent eligibility interface must deny unverified canonical identity, consent, suppression or purpose before assignment. There is no provider or live channel path in this task. A digest is pseudonymous, so retention and deletion policy still apply. A key change must fail closed for existing assignments. A caller claiming actual rendering needs a trusted application boundary; no such production boundary is certified here.

## API/platform constraints

Use existing Laravel/PostgreSQL persistence and TenantContext with campaign create/read/approve permissions until a dedicated experiment permission taxonomy receives an explicit ADR. Do not add a provider SDK. Durable unique keys handle duplicate delivery retries. Migrations are additive, rollback rejects nonempty evidence tables.

## Performance/reliability findings

Use keyed deterministic bucket, rejection sampling for unbiased 10,000-way allocation, and unique assignment/exposure constraints for concurrent replay. PostgreSQL transaction and unique conflict behavior need integration evidence. No numeric production throughput/SLO is claimed.

## Conflicts with current assumptions

No existing experiment schema exists. The task cannot certify actual exposure at a delivery/render boundary without TASK-0064 integration; the TASK-0063 API will require a trusted proof reference and preserve a separate exposure ledger, but production calls remain disabled.

## Required roadmap extensions

No new task. TASK-0064 must bind trusted campaign/content/render identifiers and verify real exposure. TASK-0066 must diagnose missing exposure, SRM and crossover before conclusions.

## Rejected options

Unkeyed hashing, mutable split after activation, client supplied workspace, recording exposure on `assign`, automatically dispatching marketing content from this module.

## Decision impact

`CONFIRMS_PLAN`: TASK-0063 scoped offline assignment foundation. `NEW_ACCEPTANCE_CRITERION`: rotation denial, actual exposure boundary, null brand isolation and concurrency. `BLOCKER` for production activation: operator approval and trusted render/send event binding are outside this task.

## Freshness risks

Recheck provider exposure API details before any connector hookup. This pure local allocation does not rely on a mutable external API.
