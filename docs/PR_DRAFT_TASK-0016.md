# Draft PR: TASK-0016 — Provider-neutral connector contracts and scaffolding

Branch: task-0016/adapter-contracts

Summary

This draft PR implements provider-neutral connector contracts and a concrete scaffold to satisfy TASK-0016 acceptance criteria. It contains interfaces, an error normalizer, a generic webhook verifier (raw-body aware), quota signal parser, reconciliation scaffold, an in-memory dedup store for tests, service provider bindings, unit tests, integration test scaffolds, and documentation.

Files added (high level)
- app/Connectors/Contracts/* — ConnectorInterface, ErrorNormalizerInterface, WebhookVerifierInterface, QuotaSignalInterface, ReconciliationInterface
- app/Connectors/Manifest.php
- app/Connectors/Enums/ErrorCategory.php
- app/Connectors/ErrorNormalizer/BaseErrorNormalizer.php
- app/Connectors/Webhook/GenericWebhookVerifier.php
- app/Connectors/Quota/QuotaSignalParser.php
- app/Connectors/Reconciliation/DefaultReconciler.php
- app/Connectors/Dedup/InMemoryDedupStore.php
- app/Providers/ConnectorServiceProvider.php
- app/Connectors/Adapters/ExampleProviderAdapter.php
- tests/Unit/Connectors/* — interface test, normalizer test, webhook verifier test, quota parser test
- tests/Integration/Connectors/WebhookNegativeTests.php (scaffold, marked incomplete)
- docs/CONNECTOR_CONTRACTS.md
- CHANGELOG-connector-scaffold.txt

What is implemented now
- Provider-neutral contract interfaces and a manifest model
- Error normalization base implementation mapping common shapes to stable categories
- Generic webhook verifier that preserves raw body, supports common HMAC signature headers, and extracts deduplication ids
- Quota signal parser that normalizes header/body quota signals
- Default reconciler scaffold for async ops
- In-memory dedup store (test/dev) with an interface for production implementations (Redis/DB)
- Service provider binding the interfaces to default implementations
- Unit tests covering the basic behaviors and existence of interfaces
- Integration tests scaffolded and marked incomplete where provider secrets or routes are required

Tests & CI status
- Unit tests added. CI/test run is pending — the repository's CI runner is required to execute PHP/JS/Python validations and to run phpunit/php artisan test, composer analyse/lint, and npm typecheck/test/build.

Next actions (follow-ups to merge)
1. Wire webhook verifier & dedup store into the application's webhook route so raw-body is passed to the verifier.
2. Implement production DedupStore (Redis/DB) and provide configuration in config/connectors.php.
3. Implement provider-specific ErrorNormalizers and Reconciliation pollers for each provider.
4. Flesh out integration negative tests to post to webhook routes and assert rejected/accepted semantics; add CI secrets/mocks as needed.
5. Run the full validations (python tools/*, php artisan test, composer analyse/lint, npm checks) and fix any issues.

Notes
- InMemoryDedupStore is not suitable for multi-process production. Use Redis/DB and bind via ConnectorServiceProvider.
- GenericWebhookVerifier handles common HMAC signature types; provider-specific verifiers are still recommended.
- Integration tests requiring external network or secrets are marked incomplete until CI secrets are available.

Contact
I will continue without asking further confirmations. I will run validations and CI where possible, update this draft PR with test results, and open a GitHub draft PR when CI output is available. If you want me to prioritize a specific provider (Stripe, SendGrid, Mailgun, AWS, etc.) I will implement provider-specific normalizers next.
