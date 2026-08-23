# GitHub Copilot Repository Instructions

Follow `/AGENTS.md` as the repository-wide operating contract. Treat `.ai/state/CURRENT-STATE.yaml` and the referenced active task as the execution source of truth. Do not suggest or implement work from a future task unless state is intentionally advanced. Provider integrations must use the canonical adapter contracts. Architecture changes require an ADR. Any implementation change must preserve tests, checkpoint state, security boundaries, consent, suppression, and provider abstraction rules.
