# TASK-0060 Research Pack

- researched_at: 2026-10-01T22:17:00Z
- task: TASK-0060
- phase: PHASE-10
- scope: adversarial regression corpus across AI authority, retrieval, disclosure, fallback and consumption boundaries
- researcher: Supervisor

## Sources
| Source | Type/version | Accessed | Why authoritative / impact |
|---|---|---|---|
| https://genai.owasp.org/llmrisk/llm01-prompt-injection/ | Official security/2025 current page | 2026-10-01 | Distinguishes direct, indirect, poisoned retrieval and obfuscated/multimodal attacks; prompt instructions alone cannot guarantee prevention. |
| https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html | Official security/current | 2026-10-01 | Independent authority, least privilege, scoped memory, output validation and bounded consumption remain server responsibilities. |

## Current external reality / market workflow
Attack simulations must treat model output as hostile even when retrieved text looks credible. A repeatable corpus should describe the payload, expected deterministic boundary and observed result. New attacks become regression fixtures rather than a claim that a prompt filter solves all injection.

## Security/privacy findings
CONFIRMS_PLAN: test direct instruction replacement, indirect instruction text, poisoned source identity, private instruction markers, hallucinated references and foreign workspace output. Exercise unauthorized tools, sensitive text/URL exfiltration, incompatible fallback, zero/missing budget, oversized retries and governing-policy mutation. No attacker fixture may call a real endpoint or include an actual credential.

## API/platform constraints
No hosted model, SDK, network attack, vendor account or new endpoint is required. Deterministic malicious adapters exercise the actual application policy/gateway/runtime boundaries. Record that this proves tested server outcomes, not model-level resistance to every attack or semantic hallucination.

## Performance/reliability findings
Preflight denial must precede invocation. Retry plans stay finite and failure retains reserved cost. Corpus execution is reproducible in the locked full CI suite; no production performance threshold follows from it.

## Conflicts / required roadmap extensions
None. Existing TASK-0060 criteria cover the findings. No safety or acceptance gate is relaxed.

## Rejected options / decision impact
Reject production penetration tests, real exfiltration destinations, regex-only safety claims and model-generated authorization. CONFIRMS_PLAN: add fixture-driven regressions on deterministic trust boundaries.

## Freshness risks
Expand/revalidate the attack corpus as new providers, modalities, retrieval mechanisms or tools are enabled.

Activation revalidation: 2026-10-01T22:57:00Z, accepted baseline `b7ced3305224d011546e4938d4e96e9f2f3580c8`. Same-day official-source findings still apply; no external capability is introduced.
