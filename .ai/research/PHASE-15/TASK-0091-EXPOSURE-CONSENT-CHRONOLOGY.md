# TASK-0091 — Independent Exposure Denominators and Consent Chronology

Status: **staged offline-only candidate; exact-head/protected-main certification required**.

## Risk 1: assignment equality does not prove exposure equality

PR #557 requires exact frozen cohort assignment counts to match independently scored outcome assignments. Yet an outcome source could still claim all 5,000 assigned control units were exposed while the independently reviewed frozen source reports only 4,999 exposures. This can inflate conversion evidence without changing assignment total, holdout count, allocation ratios or the outcome manifest link.

Both typed read-only sources now expose their validated **exposed-by-arm** maps. Any positive operator candidate requires the independent frozen-cohort per-arm exposures to equal the scored-outcome exposures after canonical sorting. Missing typed exposures hold with `cohort_outcome_exposure_unavailable`; differing exposures hold with `cohort_outcome_exposure_mismatch`.

## Risk 2: recently observed is not necessarily recently authorized

Even individually fresh (within 300 seconds) sources can have causal ordering problems: a frozen-cohort consent snapshot observed **before** a newer outcome, or an independent join observed **before** the scored source it claims to join. Candidate review now requires the join observation to be at or after the scored outcome observation, and the independently re-read frozen/consented cohort observation to be at or after *both*. Older sources fail closed with `outcome_join_predates_scored_snapshot` or `cohort_consent_readback_predates_outcomes`.

These are necessary consistency conditions, not proof that live consent was independently attested. All external-source adapters remain deny-by-default and synthetic fixtures are not production provider signatures.

## Adversarial regression

A frozen 5,000/5,000/1,000 assignment and positive independent statistical signal stays offline review-only with all effect permissions false. With that same assignment:
- Frozen control exposures at 4,999 vs scored control exposures at 5,000 hold
- Frozen consent snapshot observed one second before the scored outcome holds
- Join observed one second before the scored snapshot holds
- A control/treatment assignment swap remains held by PR #557 per-arm assignment logic

No customer send, spend, refund, retry, rollback, live promotion, production experimentation or provider activation is performed. TASK-0091 AC-1..3 remain pending for holistic independent evidence and formal guarded acceptance.
