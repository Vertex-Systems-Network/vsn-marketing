# TASK-0064 — campaign experiment binding research (2026-10-02)

## Sources and decisions

- [Statsig layer evaluation](https://docs.statsig.com/sdks/how-evaluation-works): layer allocation and variant allocation use distinct salts; exclusion and targeting have an explicit order. **Plan confirmation:** reuse TASK-0063's sticky, mutually exclusive unit assignment; a campaign matrix cannot choose another assignment unit per factor.
- [Statsig CMS integration](https://docs.statsig.com/guides/cms-integrations): treatment parameters may identify CMS content, and layer ownership controls overlap. **Plan confirmation:** bind a canonical content version to each candidate instead of copying mutable content.
- [Eppo entry points](https://docs.geteppo.com/guides/advanced-experimentation/entry_points/): assignment can precede actual exposure; analysis needs a qualifying exposure event. **Acceptance gate:** outcome admission requires a prior witnessed exposure for the same assignment and candidate; missing exposure is quarantined.
- [Eppo data pipeline](https://docs.geteppo.com/data-management/data-pipeline/): subjects exposed to more than one variant are excluded from analysis. **Acceptance gate:** crossing variant or campaign evidence quarantines the candidate outcome and must not silently count it.
- [Eppo analysis configuration](https://docs.geteppo.com/experiment-analysis/configuration/): assignment and event windows can differ, including short marketing treatments with later outcomes. **Plan confirmation:** record ordering and retain event timestamps; defer statistical inference to TASK-0065.

## Repository evidence and bounded design

`campaign_snapshots` pins the campaign and content version. TASK-0063 persists immutable plans, sticky assignment and separately witnessed exposure; it has no live eligibility or exposure verifier adapter. TASK-0064 binds candidates to a single snapshot and uses a bounded Cartesian content × UTC timing × audience version matrix (at most 16 arms, matching the existing plan's cap). The audience version is a canonical reference, not a claim that segmentation has evaluated a real subject. Candidate resolution requires the existing assignment and explicit eligibility again; it cannot send, enroll or witness exposure by itself. Outcome admission retains quarantined provenance and requires a matching prior exposure, with one candidate identity per assignment. Rollback halts further candidate resolution while preserving evidence. No provider, conversion, power or production lift is asserted by this offline contract.

## Classification

The findings confirm the existing PHASE-11 scope and strengthen AC-2. There is no external API or production adapter in this task. Statistical evaluation and operational activation remain downstream TASK-0065 through TASK-0067 gates.
