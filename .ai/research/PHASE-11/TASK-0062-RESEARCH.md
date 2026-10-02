# TASK-0062 Research Pack — Experimentation and Adaptive Optimization

- researched_at: 2026-10-02T06:00:00+00:00
- task: TASK-0062
- phase: PHASE-11
- scope: randomized marketing experiment design, allocation, exposure, holdouts, analysis and AI recommendation controls
- researcher: Codex Supervisor
- source baseline: protected main `564f048accabc12a2e1acfcc09ed6799914a677b`

## Sources

| Source | Type | Accessed | Implementation impact |
|---|---|---|---|
| [Penn State STAT 509 sample size and power](https://online.stat.psu.edu/stat509/Lesson08) | Academic primary teaching source | 2026-10-02 | Prespecify effect, alpha, power, allocation, multiple primary comparisons and sample size; do not infer an effect from an underpowered null. |
| [Statsig experiment implementation](https://docs.statsig.com/experiments/implementation/implement) and [creation](https://docs.statsig.com/experiments/create-new) | First-party product docs | 2026-10-02 | Distinguish code assignment, exposure and outcome; use per-experiment salt, explicit control and traffic allocation. |
| [Eppo assignment logging](https://docs.geteppo.com/sdks/event-logging/assignment-logging/) and [holdouts](https://docs.geteppo.com/feature-flagging/concepts/holdout-config/) | First-party product docs | 2026-10-02 | Capture assignment metadata, holdout allocation and exposure at treatment; keep analysis population explicit. |
| [Eppo mutual exclusion](https://docs.geteppo.com/feature-flagging/concepts/mutual_exclusion/) | First-party product docs | 2026-10-02 | Conflicting simultaneous treatments need registered exclusion layers. |
| [Eppo diagnostics](https://docs.geteppo.com/experiment-analysis/diagnostics/) and [Statsig SRM](https://docs.statsig.com/stats-engine/methodologies/srm-checks) | First-party product docs | 2026-10-02 | SRM, mixed assignments, missing metric joins and pre-experiment imbalance must be visible and can invalidate decisions. |
| [Statsig sequential testing](https://docs.statsig.com/experiments/advanced-setup/sequential-testing) and [Optimizely false discovery rate](https://support.optimizely.com/hc/en-us/articles/4410283967245-False-discovery-rate-control) | First-party statistical method docs | 2026-10-02 | Fixed-horizon peeking is invalid; sequential or multiplicity correction must be explicitly selected and tested. |

## Current external reality

Assignment only selects a treatment. It does not prove a subject saw that treatment. Exposure must be recorded at the actual rendering/send boundary with subject, version, variant, timestamp and dedupe key. Analysis needs a prespecified unit, eligibility, target population, primary outcome, denominator, duration, stopping method and allocation. Chi-square sample-ratio mismatch is a diagnostic, not a blanket correction. Multiple variants/metrics and repeated peeking inflate false claims unless analysis accounts for them.

## Market/reference workflow

Operator defines hypothesis/control, randomized unit, traffic and eligible population, primary metric, guardrails, minimum detectable effect, target power and analysis window; previews and receives independent review before launch. Version is frozen at first exposure. Operators monitor exposure completeness, SRM, crossover and guardrails, then see effect size with uncertainty and an inconclusive option. Rollback returns the stable baseline without changing prior assignment or exposure evidence. Neither proprietary scoring nor competitor dashboards are copied.

## Security/privacy findings

Use authenticated workspace/brand scope, consented canonical identity and purpose for marketing actions. Do not assign by mutable email or infer identity from model output. HMAC/keyed deterministic bucket inputs must not leak raw customer identifiers into telemetry. Assignment and exposure are personal/behavioral event data with retention/deletion needs. AI cannot assign itself promotion authority or bypass send, suppression, approval and frequency gates. Unknown purpose or rights fail closed.

## API/platform constraints

This phase is provider-neutral; no provider endpoint, account grant, API quota or production traffic is activated. Existing canonical campaign, journey, content, consent, audit and event contracts remain in force. Multi-channel provider dispatch cannot be treated as an exposure merely because an assignment exists. Production activation requires separate legal/privacy, capacity and operator review.

## Performance/reliability findings

Stable salted assignment should be replayable across retries and concurrent workers. Preserve an immutable experiment version, exposure dedupe and event-time semantics so asynchronous deliveries cannot switch variants. Specify population, sample size and horizon from real baseline rates; synthetic fixtures cannot certify production statistical power, conversion uplift, campaign performance or capacity. PostgreSQL contention and outbox/retry behavior need explicit tests.

## Conflicts with current assumptions

The preplanned TASK-0062–0067 list is a skeleton. No running experiment engine, measured baseline rate, approved statistical thresholds or live sample exists. Phase-10 synthetic AI cost/latency fixtures do not establish marketing experiment efficacy. Assignment-at-evaluation logging can overcount exposure when a campaign is never rendered or delivered.

## Required roadmap extensions

Register six tasks with 16/22/18/16/16/12 weights totaling 100. Add immutable analysis plan, actual exposure event, sample-ratio/mixed-assignment checks, inconclusive results and independent AI promotion criteria. TASK-0063 owns assignment integrity; TASK-0064 owns campaign-bound variants; TASK-0065 owns AI candidate review; TASK-0066 owns statistical analysis; TASK-0067 certifies exact-head/main and explicitly limits claims.

## Rejected options

- Hashing an email with an unkeyed public salt; rerandomizing a subject at each send.
- Logging a treatment as seen at assignment time; analyzing absent exposure as a successful delivery.
- Selecting a winner from a raw p-value after repeated peeking, many variants or unmeasured baseline.
- Letting an AI optimizer rewrite the active hypothesis, statistical plan, or approval gate.

## Decision impact

`CONFIRMS_PLAN`: six preplanned tasks. `NEW_ACCEPTANCE_CRITERION`: frozen analysis/version, exposure-time evidence, SRM/crossover/missingness, explicit multiplicity and inconclusive result. `NEW_PREREQUISITE`: baseline metric/consent schema and authorized activation before production. `BLOCKER` for production efficacy claim: no actual baseline, account/provider enrollment or data-quality evidence. No blocker for offline implementation.

## Freshness risks

Provider SDK logging details, statistical defaults, privacy rules and platform policies can change; recheck primary sources before external integration or production activation. The cited methods motivate a local contract, not a claim that VSN has copied a vendor's validated statistics engine.
