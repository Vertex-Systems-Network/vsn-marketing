# TASK-0046 evaluation and privacy evidence

Status: implementation evidence in PR #412; acceptance pending its unchanged final head.

## Query shape and bounded work

- Compiler source is the versioned AST validator and static field/event registry. `TenantContext.workspaceId` supplies the base contact predicate and correlated relation predicates independently of AST input. Values are bindings. The count query wraps a `SELECT DISTINCT c.id` plan in a derived table with `LIMIT max_count_probe + 1`, then counts only that bounded result. A larger audience returns a lower bound and `capped`, never an invented exact or estimated count.
- The PostgreSQL integration fixture inserts 300 matching contacts and a SQL-looking company domain, runs `EXPLAIN (FORMAT JSON)` against the bound, tenant-scoped query, asserts a plan exists, the hostile string is absent from SQL syntax and present among bindings, and proves the probe yields 251 rows. The initial PR #412 repair head `f10c3acc` passed PostgreSQL integration in Application run `36261413575`. This fixture establishes query shape and bounded output; it is not a production latency SLO or a claim that every possible plan avoids a large scan.
- The configured cost limit and event-window/node guards apply before query execution. PostgreSQL uses transaction-local statement timeout. Timeout/database errors return `unavailable` without SQL, raw values or members. No asynchronous materialization or cache is introduced, so no cancellation retry or stale membership reuse can occur in this task. Client navigation does not guarantee database cancellation; the server timeout remains the enforced work bound.

## Disclosure and version semantics

- Preview returns count metadata and **no member identifiers, names, addresses or raw values**. It includes definition hash, optional pinned version, evaluation identity, UTC evaluation instant, explicit unknown source freshness, and separate `eligibility: not_evaluated`. Delivery's canonical admission path remains responsible for consent and suppression at send time.
- Count disclosure is limited to an authorized `contact.read` actor in the same workspace. No universal small-cell suppression threshold is asserted without an approved privacy policy. Count evaluations are audited by hash/evaluation ID and count kind, excluding raw filter values. Saved definitions are listed with a 50-row bound to authorized users; no contact export or audience membership pagination endpoint exists.
- Draft edits append immutable versions under a scoped row lock. Publication pins an explicit version number; later edits do not float that pointer. Pinned preview reloads stored AST by composite workspace/definition/version identity, checks its canonical hash and rejects a mismatched client definition. No count/membership cache exists (`cache_status: disabled`), preventing cross-workspace reuse by construction. Future caching requires an independently reviewed permission/policy revision in its key and reauthorization on retrieval.

## Remaining certification

Exact final PR head Application/E2E/Security/Continuity and TASK-0046 acceptance criteria remain open. The browser test seeds a test-only persistent SQLite workspace and exercises a live authenticated keyboard-operated, mobile build → preview → save → publish journey, including nested and NOT controls. Unit UI tests cover unavailable/freshness states. The endpoint test separately proves unauthenticated read and CSRF-protected mutation denial. The persistent database and file sessions are scoped to the E2E job; the seed script refuses non-testing or non-SQLite contexts. These new browser changes remain unverified until their exact head passes CI.
