# TASK-0060 adversarial boundary evidence

The versioned offline corpus `tests/Fixtures/AI/red-team.v1.json` has22 named cases. `AiRedTeamTest` drives the actual `AiContextAssembler`, `AiAgentRuntime`, `AiAgentProposalGateway` and `AiGateway` with synthetic repository/provider/budget/telemetry ports from `AiRuntimeHarness`. The harness is test support and is never a production adapter or authority binding.

| Threat | Exercised boundary |
|---|---|
| Direct/indirect instruction replacement | Payload remains quarantined `untrusted_data`; server instructions, workspace, region, fallback and finite plan unchanged. Malicious modeled outputs are separately rejected. |
| Retrieval poisoning | Foreign workspace/brand/customer, elevated permission, stale source and secret-bearing content rejected before invocation. |
| Prompt leakage/exfiltration | Known private instruction canary, sensitive credential pattern and arbitrary collection URL rejected; output withheld and rejection trace accounted. |
| Hallucinated references | Invented/duplicate citations and foreign output workspace rejected. This does not evaluate semantic accuracy of every cited sentence. |
| Tool/authority abuse | Unregistered publication tool, model approval, new step and risk/permission mutation fields rejected by strict proposal schema/tool policy. Existing DB-backed `AiTypedToolExecutorTest` separately proves independent permission, approval, typed arguments, replay binding, rollback and no permissive handler. |
| Unsafe fallback | Four outage variants cannot fall back across region, workspace, live evidence or missing capability. Unknown primary cost remains reserved. |
| Budget/loops/self-modification | Denied reservation blocks invocation; repeated-agent and model-selected steps reject before invocation. Existing runtime tests cover run ceiling and max steps. |
| Evaluation poisoning | Unknown expected outcome cannot convert an invalid sample into a passed report. |

Every rejected output invocation retains known accounting and `validation_failed` status. Preflight failures have zero provider calls. Valid quarantined instruction text can remain in context while a bounded safe proposal passes; text presence alone is not authority and not proof a real model obeys instructions.

These are deterministic server-boundary regression outcomes, not a real-provider attack-success benchmark, universal injection resistance, full prompt secrecy, unrestricted PII detection or semantic hallucination detection. No network attacks, actual credentials, provider activation, publication or production side effects are used. Existing independent tool, creative-rights, schema and database gates remain intact.
