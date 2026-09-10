#!/usr/bin/env python3
"""Deterministic GitHub-native Supervisor observer/triage runtime.

This process is intentionally NOT a merge agent. It reads trusted canonical state,
recomputes registered workstream readiness from GitHub evidence, and writes only
coordination issue/comment surfaces.
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
STATE_PATH = ROOT / ".ai/state/CURRENT-STATE.yaml"
CONTROL_PATH = ROOT / ".ai/parallel/CONTROL.yaml"
WORKSTREAMS_PATH = ROOT / ".ai/parallel/WORKSTREAMS.yaml"

STATUS_TITLE = "[Supervisor] Persistent Control Plane Status"
STATUS_MARKER = "<!-- persistent-supervisor:status:v1 -->"
REVIEW_PREFIX = "<!-- persistent-supervisor:review-ready:"
REQUIRED_CI = (
    "AI Continuity Guard",
    "Application Foundation CI",
    "Security Supply Chain CI",
)


class GitHubApiError(RuntimeError):
    pass


def load_json_yaml(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected object: {path.relative_to(ROOT)}")
    return data


def standalone(text: str | None, expected: str) -> bool:
    return any(line.strip() == expected for line in (text or "").splitlines())


def workstreams_by_branch(registry: dict[str, Any]) -> dict[str, dict[str, Any]]:
    rows = registry.get("workstreams", [])
    if not isinstance(rows, list):
        return {}
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        if not isinstance(row, dict):
            continue
        branch = row.get("branch")
        wid = row.get("id")
        if isinstance(branch, str) and branch and isinstance(wid, str) and wid:
            result[branch] = row
    return result


def main_is_ancestor(compare: object) -> bool:
    if not isinstance(compare, dict):
        return False
    status = compare.get("status")
    try:
        behind = int(compare.get("behind_by", -1))
    except (TypeError, ValueError):
        return False
    return status in {"ahead", "identical"} and behind == 0


def classify_ci(runs: object, head_sha: str, required: tuple[str, ...] = REQUIRED_CI) -> dict[str, str]:
    latest: dict[str, dict[str, Any]] = {}
    if isinstance(runs, list):
        for raw in runs:
            if not isinstance(raw, dict) or raw.get("head_sha") != head_sha:
                continue
            name = raw.get("name")
            if name not in required:
                continue
            previous = latest.get(str(name))
            current_key = (str(raw.get("created_at", "")), int(raw.get("id", 0) or 0))
            previous_key = (
                str(previous.get("created_at", "")),
                int(previous.get("id", 0) or 0),
            ) if previous else ("", -1)
            if previous is None or current_key > previous_key:
                latest[str(name)] = raw

    result: dict[str, str] = {}
    for name in required:
        run = latest.get(name)
        if run is None:
            result[name] = "missing"
        elif run.get("status") != "completed":
            result[name] = "pending"
        elif run.get("conclusion") == "success":
            result[name] = "success"
        else:
            result[name] = f"failed:{run.get('conclusion') or 'unknown'}"
    return result


def evaluate_pr(
    pr: dict[str, Any],
    workstream: dict[str, Any],
    control: dict[str, Any],
    main_sha: str,
    compare: object,
    runs: object,
) -> dict[str, Any]:
    head = pr.get("head") if isinstance(pr.get("head"), dict) else {}
    base = pr.get("base") if isinstance(pr.get("base"), dict) else {}
    head_repo = head.get("repo") if isinstance(head.get("repo"), dict) else {}
    base_repo = base.get("repo") if isinstance(base.get("repo"), dict) else {}
    head_sha = str(head.get("sha", ""))
    body = str(pr.get("body") or "")
    wid = str(workstream.get("id", ""))
    completion_signal = str(control.get("required_completion_signal", "Work Done and Submitted"))
    ci = classify_ci(runs, head_sha)
    head_repo_name = head_repo.get("full_name")
    base_repo_name = base_repo.get("full_name")

    checks = {
        "targets_main": base.get("ref") == control.get("protected_main_branch", "main"),
        "same_repository": bool(head_repo_name) and head_repo_name == base_repo_name,
        "non_draft": pr.get("draft") is False,
        "workstream_marker": standalone(body, f"Workstream: {wid}"),
        "completion_signal": standalone(body, completion_signal),
        "contains_current_main": main_is_ancestor(compare),
        "exact_head_ci": all(value == "success" for value in ci.values()),
    }
    blockers: list[str] = []
    labels = {
        "targets_main": "PR does not target protected main",
        "same_repository": "PR head is not the registered base repository",
        "non_draft": "PR is draft",
        "workstream_marker": "registered Workstream marker missing",
        "completion_signal": f"exact standalone `{completion_signal}` signal missing",
        "contains_current_main": f"PR head does not contain current main {main_sha}",
        "exact_head_ci": "required exact-head CI is not fully successful",
    }
    for key, ok in checks.items():
        if not ok:
            blockers.append(labels[key])

    return {
        "number": int(pr.get("number", 0) or 0),
        "workstream": wid,
        "branch": str(head.get("ref", "")),
        "head_sha": head_sha,
        "draft": bool(pr.get("draft")),
        "checks": checks,
        "ci": ci,
        "blockers": blockers,
        "review_ready": all(checks.values()),
    }


def issue_needs_update(issue: dict[str, Any] | None, body: str) -> bool:
    return issue is None or issue.get("state") != "open" or str(issue.get("body") or "") != body


def _short(sha: str) -> str:
    return sha[:12] if sha else "unknown"


def render_status(
    state: dict[str, Any],
    registry: dict[str, Any],
    main_sha: str,
    main_ci: dict[str, str],
    prs: list[dict[str, Any]],
) -> tuple[str, str]:
    execution = state.get("execution") if isinstance(state.get("execution"), dict) else {}
    active_task = str(execution.get("active_task", "unknown"))
    execution_status = str(execution.get("status", "unknown"))
    parent_task = str(registry.get("parent_task", "unknown"))
    mode = str(registry.get("mode", "unknown"))

    blockers: list[str] = []
    for item in state.get("blockers", []) if isinstance(state.get("blockers"), list) else []:
        blockers.append(f"Canonical state: {item}")
    if mode == "active" and parent_task != active_task:
        blockers.append(f"Parallel parent `{parent_task}` does not match active task `{active_task}`")
    if execution_status in {"blocked", "needs_reconciliation"}:
        blockers.append(f"Canonical execution status is `{execution_status}`")
    for name, status in main_ci.items():
        if status != "success":
            blockers.append(f"Main exact-head CI `{name}` is `{status}`")
    for pr in prs:
        if not pr.get("draft") and not pr.get("review_ready"):
            for reason in pr.get("blockers", []):
                blockers.append(f"PR #{pr.get('number')} ({pr.get('workstream')}): {reason}")

    health = "HEALTHY" if not blockers else "DEGRADED"
    lines = [
        STATUS_MARKER,
        "# Persistent Supervisor Control Plane",
        "",
        f"Status: **{health}**",
        "",
        "## Canonical state",
        "",
        f"- Main SHA: `{main_sha}`",
        f"- Active task: `{active_task}`",
        f"- Execution status: `{execution_status}`",
        f"- Parallel mode: `{mode}`",
        f"- Parallel parent task: `{parent_task}`",
        "",
        "## Main exact-head CI",
        "",
    ]
    for name in REQUIRED_CI:
        lines.append(f"- {name}: **{main_ci.get(name, 'missing')}**")

    lines.extend(["", "## Registered open workstream PRs", ""])
    if not prs:
        lines.append("None.")
    else:
        lines.extend([
            "| PR | Workstream | Head | Completion | Main ancestry | Required CI | Review ready |",
            "|---:|---|---|---|---|---|---|",
        ])
        for pr in sorted(prs, key=lambda row: int(row.get("number", 0))):
            checks = pr.get("checks", {})
            completion = "yes" if checks.get("completion_signal") else "no"
            ancestry = "yes" if checks.get("contains_current_main") else "no"
            ci = "green" if checks.get("exact_head_ci") else "blocked"
            ready = "yes" if pr.get("review_ready") else "no"
            lines.append(
                f"| #{pr.get('number')} | `{pr.get('workstream')}` | `{_short(str(pr.get('head_sha', '')))}` | {completion} | {ancestry} | {ci} | {ready} |"
            )

    lines.extend(["", "## Actionable blockers", ""])
    if blockers:
        lines.extend(f"- {item}" for item in blockers)
    else:
        lines.append("- None.")
    lines.extend([
        "",
        "## Authority boundary",
        "",
        "This workflow performs deterministic observation and triage only. `SUPERVISOR REVIEW READY` is not approval. The workflow never auto-merges, force-pushes, moves refs, changes branch protection, weakens checks, or mutates canonical `.ai` / product code.",
    ])
    return health, "\n".join(lines) + "\n"


class GitHubClient:
    def __init__(self, token: str, api_url: str = "https://api.github.com"):
        if not token:
            raise GitHubApiError("GITHUB_TOKEN is required")
        self.token = token
        self.api_url = api_url.rstrip("/")

    def request(self, method: str, path: str, payload: dict[str, Any] | None = None) -> tuple[Any, dict[str, str]]:
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            self.api_url + path,
            data=data,
            method=method,
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": f"Bearer {self.token}",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "vsn-persistent-supervisor/1",
                "Content-Type": "application/json",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                raw = response.read()
                parsed = json.loads(raw.decode("utf-8")) if raw else None
                return parsed, dict(response.headers.items())
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")[:1000]
            raise GitHubApiError(f"GitHub API {method} {path} failed ({exc.code}): {detail}") from exc
        except urllib.error.URLError as exc:
            raise GitHubApiError(f"GitHub API {method} {path} failed: {exc.reason}") from exc

    def get(self, path: str) -> Any:
        return self.request("GET", path)[0]

    def post(self, path: str, payload: dict[str, Any]) -> Any:
        return self.request("POST", path, payload)[0]

    def patch(self, path: str, payload: dict[str, Any]) -> Any:
        return self.request("PATCH", path, payload)[0]

    def get_all(self, path: str, per_page: int = 100) -> list[Any]:
        rows: list[Any] = []
        page = 1
        while True:
            sep = "&" if "?" in path else "?"
            result = self.get(f"{path}{sep}per_page={per_page}&page={page}")
            if not isinstance(result, list):
                raise GitHubApiError(f"expected list from {path}")
            rows.extend(result)
            if len(result) < per_page:
                return rows
            page += 1
            if page > 20:
                raise GitHubApiError(f"pagination safety limit exceeded for {path}")


def find_status_issue(client: GitHubClient, repo: str) -> dict[str, Any] | None:
    query = urllib.parse.quote(f'repo:{repo} is:issue in:title "{STATUS_TITLE}"')
    result = client.get(f"/search/issues?q={query}&per_page=20")
    items = result.get("items", []) if isinstance(result, dict) else []
    for item in items:
        if isinstance(item, dict) and item.get("title") == STATUS_TITLE and "pull_request" not in item:
            return item
    return None


def runs_for_head(client: GitHubClient, repo: str, sha: str) -> list[dict[str, Any]]:
    encoded = urllib.parse.quote(sha, safe="")
    result = client.get(f"/repos/{repo}/actions/runs?head_sha={encoded}&per_page=100")
    rows = result.get("workflow_runs", []) if isinstance(result, dict) else []
    return [row for row in rows if isinstance(row, dict)]


def reconcile() -> int:
    repo = os.environ.get("GITHUB_REPOSITORY", "").strip()
    token = os.environ.get("GITHUB_TOKEN", "")
    api_url = os.environ.get("GITHUB_API_URL", "https://api.github.com")
    if "/" not in repo:
        raise GitHubApiError("GITHUB_REPOSITORY must be owner/name")

    state = load_json_yaml(STATE_PATH)
    control = load_json_yaml(CONTROL_PATH)
    registry = load_json_yaml(WORKSTREAMS_PATH)
    client = GitHubClient(token, api_url)
    main_branch = str(control.get("protected_main_branch", "main"))

    ref = client.get(f"/repos/{repo}/git/ref/heads/{urllib.parse.quote(main_branch, safe='')}")
    main_sha = str((ref.get("object") or {}).get("sha", "")) if isinstance(ref, dict) else ""
    if len(main_sha) != 40:
        raise GitHubApiError("could not resolve protected main SHA")

    main_runs = runs_for_head(client, repo, main_sha)
    main_ci = classify_ci(main_runs, main_sha)
    mapping = workstreams_by_branch(registry)
    open_prs = client.get_all(f"/repos/{repo}/pulls?state=open")
    evaluated: list[dict[str, Any]] = []

    for pr in open_prs:
        if not isinstance(pr, dict):
            continue
        head = pr.get("head") if isinstance(pr.get("head"), dict) else {}
        head_repo = head.get("repo") if isinstance(head.get("repo"), dict) else {}
        branch = str(head.get("ref", ""))
        workstream = mapping.get(branch)
        if workstream is None or head_repo.get("full_name") != repo:
            continue
        head_sha = str(head.get("sha", ""))
        compare: object = None
        if len(head_sha) == 40:
            try:
                compare = client.get(f"/repos/{repo}/compare/{main_sha}...{head_sha}")
            except GitHubApiError:
                compare = None
        try:
            pr_runs: object = runs_for_head(client, repo, head_sha) if len(head_sha) == 40 else []
        except GitHubApiError:
            pr_runs = []
        evaluated.append(evaluate_pr(pr, workstream, control, main_sha, compare, pr_runs))

    health, body = render_status(state, registry, main_sha, main_ci, evaluated)
    issue = find_status_issue(client, repo)
    if issue is None:
        issue = client.post(f"/repos/{repo}/issues", {"title": STATUS_TITLE, "body": body})
        print(f"Created Supervisor status issue #{issue.get('number')}; status={health}")
    elif issue_needs_update(issue, body):
        issue = client.patch(
            f"/repos/{repo}/issues/{int(issue['number'])}",
            {"body": body, "state": "open"},
        )
        print(f"Updated Supervisor status issue #{issue.get('number')}; status={health}")
    else:
        print(f"Supervisor status issue #{issue.get('number')} unchanged; status={health}")

    for pr in evaluated:
        if not pr.get("review_ready"):
            continue
        number = int(pr["number"])
        marker = f"{REVIEW_PREFIX}{pr['head_sha']} -->"
        comments = client.get_all(f"/repos/{repo}/issues/{number}/comments")
        if any(marker in str(item.get("body") or "") for item in comments if isinstance(item, dict)):
            continue
        comment = (
            f"{marker}\n"
            "**SUPERVISOR REVIEW READY**\n\n"
            f"Exact head `{pr['head_sha']}` contains current main `{main_sha}` and all required exact-head CI workflows are green.\n\n"
            "This is deterministic triage only. It is not approval and does not authorize merge."
        )
        client.post(f"/repos/{repo}/issues/{number}/comments", {"body": comment})
        print(f"Marked PR #{number} review-ready at {pr['head_sha']}")
    return 0


def main() -> int:
    try:
        return reconcile()
    except (GitHubApiError, OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(f"Persistent Supervisor error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
