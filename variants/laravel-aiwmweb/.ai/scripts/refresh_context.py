#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import subprocess
from pathlib import Path


def git(root: Path, *args: str) -> str:
    try:
        return subprocess.check_output(
            ["git", "-C", str(root), *args],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()
    except Exception:
        return "UNKNOWN"


def extract_metric(text: str, name: str) -> str:
    match = re.search(rf"^\|\s*{re.escape(name)}\s*\|\s*([^|]+?)\s*\|$", text, re.MULTILINE)
    return match.group(1).strip() if match else "UNKNOWN"


def owner_queue_state(text: str) -> str:
    return "NONE" if "**NONE.**" in text else "CHECK_LIVE_FILE"


def render(variant: Path, repo_root: Path) -> str:
    reconciliation_path = variant / "docs" / "operation-parity-reconciliation.md"
    owner_queue_path = variant / "docs" / "FINAL_OWNER_ACCEPTANCE_QUEUE.md"

    reconciliation = reconciliation_path.read_text(encoding="utf-8") if reconciliation_path.exists() else ""
    owner_queue = owner_queue_path.read_text(encoding="utf-8") if owner_queue_path.exists() else ""

    branch = git(repo_root, "branch", "--show-current")
    sha = git(repo_root, "rev-parse", "HEAD")

    metrics = {
        "TOTAL": extract_metric(reconciliation, "TOTAL"),
        "TERMINAL": extract_metric(reconciliation, "TERMINAL"),
        "PENDING": extract_metric(reconciliation, "PENDING"),
        "BLOCKED": extract_metric(reconciliation, "BLOCKED"),
        "OVERALL_PARITY_PERCENT": extract_metric(reconciliation, "OVERALL_PARITY_PERCENT"),
    }

    return f"""# Current State Snapshot

Generated from the local checkout. This is convenience context, not canonical authority.

- Local branch: `{branch}`
- Local HEAD: `{sha}`
- TOTAL: {metrics["TOTAL"]}
- TERMINAL: {metrics["TERMINAL"]}
- PENDING: {metrics["PENDING"]}
- BLOCKED: {metrics["BLOCKED"]}
- OVERALL_PARITY_PERCENT: {metrics["OVERALL_PARITY_PERCENT"]}
- Owner-only acceptance queue: {owner_queue_state(owner_queue)}

## Live checks still required

Before implementation or merge, fetch live GitHub and verify:
- current `worker/laravel-aiwmweb-closure-composition` head;
- open Laravel PRs;
- active/next/ready handoff Issues;
- exact-head workflow conclusions;
- the canonical reconciliation at the current composition head.

Chat memory and this generated file never override live GitHub/PCC evidence.
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write", action="store_true", help="Write .ai/CURRENT_STATE.md instead of stdout only.")
    args = parser.parse_args()

    script = Path(__file__).resolve()
    variant = script.parents[2]
    repo_root = variant.parents[1]
    output = render(variant, repo_root)

    if args.write:
        target = variant / ".ai" / "CURRENT_STATE.md"
        target.write_text(output, encoding="utf-8")
        print(target)
    else:
        print(output, end="")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
