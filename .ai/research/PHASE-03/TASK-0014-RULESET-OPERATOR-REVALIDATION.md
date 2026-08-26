# TASK-0014 Ruleset Operator Revalidation

- researched_at: 2026-08-26
- task: TASK-0014
- scope: GitHub repository ruleset mutation path for the already-recorded AC-1 blocker.
- repository_baseline: PR #28 exact head `1e211f11f1f5d5c9e4b59f9de7652d21a82adf30`

## Current official evidence

GitHub's current REST documentation was revalidated before implementing an
administrator operator:

- `Update a repository ruleset` uses
  `PUT /repos/{owner}/{repo}/rulesets/{ruleset_id}`.
- The endpoint requires repository `Administration: write` for supported
  fine-grained/GitHub App tokens.
- Update payloads can carry `name`, `target`, `enforcement`, `bypass_actors`,
  `conditions`, and the rules array.
- `required_status_checks` supports explicit contexts and
  `strict_required_status_checks_policy`.
- Branch rulesets support pull-request review requirements, deletion
  protection, and non-fast-forward/force-push protection.

Primary source:
`https://docs.github.com/en/rest/repos/rules`

## Decision

`CONFIRMS_PLAN`: AC-1 remains a hosted repository-settings requirement.

`HARDENS_PLAN`: add a repository-owned, fail-closed GitHub CLI operator that
mutates only the known VSN Marketing ruleset, requires an explicit apply
confirmation, preserves unrelated rules, and certifies the effective hosted
state with a second GET.

`NO_ACCEPTANCE_SHORTCUT`: the operator does not replace independent PR review,
post-merge trusted-main evidence, or the repository-native TASK-0014 completion
transaction.
