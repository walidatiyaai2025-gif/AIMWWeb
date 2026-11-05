#!/usr/bin/env python3
"""Fail-closed release gate for immutable main-SHA, push-triggered CI.

Inputs are GitHub REST JSON responses. No signing or hosting secrets are read.
"""
import json
import re
import sys
from pathlib import Path

# Following the owner-approved retirement of legacy ASP.NET CI in PR #1418,
# production release gates are exactly the two still-active Laravel push CI
# workflows. Never treat retired workflow checks from old SHAs as required.
REQUIRED_WORKFLOWS = {
    "Laravel AIWMWeb Acceptance": ".github/workflows/laravel-aiwmweb.yml",
    "Laravel AIWMWeb cPanel Setup ZIP": ".github/workflows/laravel-aiwmweb-cpanel-package.yml",
}

REQUIRED = tuple(REQUIRED_WORKFLOWS)


def verify(main_ref: dict, runs: dict, sha: str) -> None:
    if re.fullmatch(r"[0-9a-f]{40}", sha) is None:
        raise ValueError("BLOCKED: invalid exact-source commit SHA")
    if main_ref.get("object", {}).get("sha") != sha:
        raise ValueError("BLOCKED: main moved; source commit is no longer main HEAD")
    workflow_runs = runs.get("workflow_runs")
    if not isinstance(workflow_runs, list):
        raise ValueError("BLOCKED: GitHub Actions evidence is unavailable")
    for name in REQUIRED_WORKFLOWS:
        candidates = [
            entry for entry in workflow_runs
            if isinstance(entry, dict) and entry.get("name") == name
            and entry.get("path") == REQUIRED_WORKFLOWS[name]
            and entry.get("event") == "push" and entry.get("head_sha") == sha
            and entry.get("head_branch") == "main"
        ]
        if not candidates:
            raise ValueError("BLOCKED: required exact-main push CI is absent: " + name)
        # Never accept an older green workflow in place of its latest failed
        # or in-progress rerun on the same commit. The GitHub run ID and\n        # created_at do not change on reruns; updated_at does.
        latest = max(candidates, key=lambda x: (str(x.get("updated_at") or x.get("created_at") or ""), int(x.get("id") or 0)))
        if latest.get("status") != "completed" or latest.get("conclusion") != "success":
            raise ValueError("BLOCKED: required exact-main push CI not green: " + name)
    print("PASS: latest required push CI green on current immutable main HEAD", sha)


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit("Usage: verify-production-ci.py MAIN_REF.json WORKFLOW_RUNS.json SOURCE_SHA")
    try:
        verify(json.loads(Path(sys.argv[1]).read_text(encoding="utf-8")),
               json.loads(Path(sys.argv[2]).read_text(encoding="utf-8")), sys.argv[3])
    except (ValueError, TypeError, OSError, json.JSONDecodeError) as exc:
        sys.exit(str(exc))
