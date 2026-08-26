# Main Ruleset AC-1 Operator

This operator is scoped to active `TASK-0014`. It does not change the roadmap,
activate `TASK-0015`, merge PR #28, or manufacture review evidence.

## Purpose

GitHub ruleset `21212844` is the remaining hosted repository-settings blocker for
TASK-0014 AC-1. The connected ChatGPT GitHub capability can read rulesets but
cannot mutate them. `tools/apply_main_ruleset.py` provides an administrator-
authenticated, fail-closed GitHub CLI path to apply and certify the locked
ruleset contract.

The operator preserves unrelated existing rules, removes bypass actors, and
enforces:

- required checks: `governance`, `foundation`, `php-floor`, `integration`,
  `e2e`, and `security-gates`;
- strict/up-to-date required status checks;
- at least one approving review;
- approval of the most recent push;
- resolved review threads;
- deletion protection;
- force-push protection.

It intentionally does **not** approve PR #28 or select a reviewer. A real
independent human approval from an eligible collaborator remains separate
acceptance evidence.

## Prerequisites

- GitHub CLI (`gh`) installed.
- `gh auth status` succeeds for an identity authorized to administer this
  repository.
- The token/session has repository `Administration: write`.
- Run from a reviewed TASK-0014 checkout; no repository secret is stored by the
  script.

## Read-only certification

```bash
python tools/apply_main_ruleset.py
```

Before AC-1 is fixed this command is expected to fail and enumerate the missing
controls.

## Apply and certify

```bash
python tools/apply_main_ruleset.py \
  --apply \
  --confirm-repository Vertex-Systems-Network/vsn-marketing
```

The operator:

1. GETs ruleset `21212844`.
2. Refuses the wrong repository, ruleset, target, or inactive enforcement.
3. Builds the locked AC-1 mutation while preserving unrelated rules.
4. PUTs the repository ruleset through GitHub's REST API.
5. Validates the update response.
6. Performs a second GET and fails unless the effective hosted ruleset satisfies
   the contract.
7. Prints `MAIN RULESET AC-1 CERTIFICATION PASSED` only after read-back
   certification.

After that marker, obtain a real independent approval on PR #28 and re-read the
ruleset and review state before merge.

## Regression test

```bash
python tools/apply_main_ruleset.py --self-test
```

This deterministic, network-free test is executed inside the existing
`action-integrity` job, so failure propagates to the aggregate
`security-gates` check.
