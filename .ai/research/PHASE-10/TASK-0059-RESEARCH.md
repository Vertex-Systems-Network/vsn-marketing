# TASK-0059 Research Pack

- researched_at: 2026-10-01T21:56:00Z
- task: TASK-0059
- phase: PHASE-10
- scope: provider-neutral creative text and media drafts, rights/provenance and independent review
- researcher: Supervisor

## Sources
| Source | Type/version | Accessed | Why authoritative / impact |
|---|---|---|---|
| https://developers.openai.com/api/docs/guides/image-generation | Official API/current | 2026-10-01 | Documents normalized image capabilities and moderation failure handling; blocked/user errors must not become automatic unsafe retries. |
| https://spec.c2pa.org/specifications/specifications/2.4/specs/C2PA_Specification.html | Primary standard/2.4 | 2026-10-01 | Provenance uses signed assertions bound to asset bytes; a hash alone is not C2PA verification or a rights license. |
| https://developers.openai.com/api/docs/guides/content-provenance | Official API/current | 2026-10-01 | Generated-content provenance must be preserved and accurately disclosed. |
| https://www.php.net/manual/en/function.getimagesize.php | Official PHP/current | 2026-10-01 | Header/dimension parsing is not full image validation; use existing Fileinfo MIME inspection and retain independent safety/canonical ingestion gates. |
| https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html | Official security/current | 2026-10-01 | Untrusted output cannot authorize tools, disclosures or downstream publication. |

## Current external reality
Creative capability is provider/model dependent. Moderation failure remains terminal, and unsupported media/input limits deny before invocation. Provider identity and request reference, prompt/context hashes and exact output/asset binding belong in candidate provenance. No model is selected by popularity.

## Market/reference workflow
A creative result is a reviewable draft with generated-content disclosure, evidence lineage and pending independent brand/safety/rights review. It cannot publish, send, connect an account or assert a license from model prose.

## Security/privacy findings
Keep credentials outside model input. Rights authorization, allowed media format/size and brand scope come from trusted policy services before a candidate is requested. Post-generation reviews bind the exact candidate hash and authenticated scope. Refusal, incomplete usage, malformed output, unsupported rights and unknown provenance deny. Provider-supplied approval flags are ignored. Asset URLs are not fetched from untrusted output; only scoped asset identifiers from a trusted store are supported.

## API/platform constraints
No live provider, credential, paid invocation, SDK or network fetch is installed. The initial media contract is image-only; no video API is inferred from general media support. The portable adapter composes the existing gateway for cost/route/status handling and adds a creative provider contract. Input/provider rights and consent are application evidence, separate from C2PA assertions. C2PA verification requires a trusted implementation and certificate checks; this task must not label a metadata reference as verified content credentials.

## Performance/reliability findings
Bound creative quantity, output size and media bytes through explicit route policy. Unknown usage stays reserved under existing gateway semantics. Live model quality, cost, latency and format support require actual provider contract evidence at activation; deterministic fixtures establish only the portable policy boundary.

## Conflicts with current assumptions
CONFIRMS_PLAN. The earlier 2.3 C2PA reference is superseded for this research by the observed 2.4 specification. Provenance does not establish truth, copyright ownership or platform publication approval.

## Required roadmap extensions
NEW_PREREQUISITE within AC-1: register explicit creative_text and creative_image capability keys plus versioned creative policy bounds before implementation. Existing TASK-0059 rights/provenance/review acceptance covers this registration; no new task or weakened criterion. Video and other unsupported media remain denied.

## Rejected options
Reject fabricated rights, model self-review, unsigned metadata labeled C2PA verified, arbitrary remote asset URLs, moderation bypass, production activation from offline fixture success and silent disclosure stripping.

## Decision impact
CONFIRMS_PLAN: portable creative adapters and reviewable drafts with independent policy checks. Provider/account activation remains independently gated.

## Freshness risks
Revalidate model capabilities, media limits, rights terms, regional processing and required channel disclosures when a real route is configured.

Activation baseline: `7262dce7c5118cd08a0874c016d8d60894273183`; source claims revalidated in this session.

Correctness reconciliation: media drafts use Fileinfo MIME and bounded dimension/header checks, not a claim of complete image decoding or malware/content safety. Parser notices become generic input denial; independent safety review and canonical asset ingestion remain required before downstream use.
