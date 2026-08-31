# Connector Contracts

This document describes the provider-neutral connector contracts scaffolded for TASK-0016.

Files added:
- app/Connectors/Contracts/* — interfaces for Connector, Error Normalizer, Webhook Verifier, Quota Signal, Reconciliation
- app/Connectors/Enums/ErrorCategory.php — normalized error categories (PHP enum)
- app/Connectors/Manifest.php — simple manifest model carrying capabilities and provenance metadata
- app/Connectors/Adapters/ExampleProviderAdapter.php — example adapter skeleton
- tests/Unit/Connectors/ConnectorContractsTest.php — unit test ensuring interfaces exist
- tests/Integration/Connectors/WebhookNegativeTests.php — integration test scaffolds for negative contract tests

Next steps:
- Implement concrete ErrorNormalizer for real providers
- Wire WebhookVerifier into application's webhook route preserving raw-body
- Implement deduplication and replay protection
- Add negative tests that post raw payloads and assert proper rejection or acceptance

This is an initial scaffold to follow the TASK-0016 acceptance criteria and create a place to implement provider-specific adapters.
