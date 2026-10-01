# TASK-0057 typed proposal and tool certification

Status: accepted after repaired exact-head and resulting-main gates passed.

| Criterion | Implementation / evidence |
|---|---|
| AC-1 | Strict bounded schema dialect; complete-status, exact schema/context and workspace binding; known references; rejection of refusal, incomplete output, unsupported schema keywords, unknown keys and unsafe strings. `AiStructuredOutputValidator` and scoped gateway validator do not execute tools. |
| AC-2 | Canonical tool registration and minimum risk; typed arguments/results; independent permission/approval dependencies; actor/workspace/brand/version/argument-bound durable idempotency; audit hashes; reversible preflight/postcondition/rollback required for writes. No production handler is installed. |
| AC-3 | Unit/feature adversarial coverage plus exact-head full application, PostgreSQL/Redis, PHP-floor, E2E, continuity and security gates. Resulting-main evidence is required separately. |

## Exact-head and resulting-main evidence

- Feature carrier PR #456: head `dbe431bfae7cf032d71b59bbb4b9d4cb4d66458b`; Application `36911341918`, Continuity `36911341874`, Security `36911341967` passed.
- PR #456 merged at `a3d7fa4a161c035e1373105427a03167e5dbb7ec`. Resulting-main Application `36930013699` failed integration job `110597582137`; therefore that merge was not task acceptance evidence.
- The PostgreSQL contention worker inherited the parent PDO/libpq connection. Disconnecting it after fork could terminate the parent's server session. A child exception could return into PHPUnit and execute subsequent tests concurrently.
- Repair PR #457: head `bd878a6ee32064de1ae396ae46caf0048dcfe27d`; connection purge occurs before fork, child failures terminate with nonzero status, and parent cleanup reaps the child. The reservation ceiling, child outcome, final reserved amount and unique-row assertions remain intact.
- PR #457 Application `36931095445` passed: foundation `110600445801`, E2E `110601357262`, PostgreSQL/Redis integration `110601357320`, PHP-floor `110601357365`. Continuity `36931095568` and Security `36931095497` passed.
- Repaired main: `2fb294fdd4f9bce718fb436aa5b1b8901426b285`; Application `36931825964` passed: foundation `110602733884`, PHP-floor `110603672293`, E2E `110603672362`, PostgreSQL/Redis integration `110603672383`. Continuity `36931825813`, Security `36931825957`, Release Integrity `36931825852`, Scorecard `36931825863` passed.

## Scope and limitations

Local merged backend verification: 753 passing tests / 4,251 assertions; 136 infrastructure tests skipped locally. These skips do not prove PostgreSQL contention. The actual PostgreSQL CI result above is the applicable evidence. No test, required gate, assertion or security boundary was weakened.

No live provider, credential, production route, tool side effect, paid invocation or deployment was activated. Schema/reference validation and privacy guards do not establish unrestricted semantic truth or comprehensive personal-data detection. Future concrete tools and live routes require their own consent, rights, privacy, provider-idempotency and measured-quality evidence.
