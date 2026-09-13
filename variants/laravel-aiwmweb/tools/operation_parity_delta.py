#!/usr/bin/env python3
"""Compare a generated reconciliation with an exact historical reconciliation commit."""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

TERMINAL = {"PORTED", "ADAPTED", "VERIFIED_UNAVAILABLE_EXTERNAL"}
RECONCILIATION_PATH = "variants/laravel-aiwmweb/docs/operation-parity-reconciliation.json"


def git_show(spec: str) -> str:
    proc = subprocess.run(["git", "show", spec], text=True, capture_output=True)
    if proc.returncode != 0:
        raise SystemExit(proc.stderr.strip() or f"unable to read {spec}")
    return proc.stdout


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--current", type=Path, required=True)
    parser.add_argument("--previous-sha", required=True)
    args = parser.parse_args()

    current = json.loads(args.current.read_text(encoding="utf-8"))
    previous = json.loads(git_show(f"{args.previous_sha}:{RECONCILIATION_PATH}"))
    current_by_id = {row["operation_id"]: row for row in current["operations"]}
    previous_by_id = {row["operation_id"]: row for row in previous["operations"]}
    if current_by_id.keys() != previous_by_id.keys():
        raise SystemExit("canonical operation ID universe changed between reconciliations")

    demoted = sorted(
        op_id for op_id in current_by_id
        if previous_by_id[op_id]["migration_state"] in TERMINAL
        and current_by_id[op_id]["migration_state"] not in TERMINAL
    )
    terminalized = sorted(
        op_id for op_id in current_by_id
        if previous_by_id[op_id]["migration_state"] not in TERMINAL
        and current_by_id[op_id]["migration_state"] in TERMINAL
    )
    changed = sorted(
        op_id for op_id in current_by_id
        if previous_by_id[op_id]["migration_state"] != current_by_id[op_id]["migration_state"]
    )
    previous_terminal = sum(
        1 for row in previous["operations"] if row["migration_state"] in TERMINAL
    )
    current_terminal = sum(
        1 for row in current["operations"] if row["migration_state"] in TERMINAL
    )

    print(f"PREVIOUS_TERMINAL={previous_terminal}")
    print(f"CURRENT_TERMINAL={current_terminal}")
    print(f"TERMINAL_DELTA={current_terminal - previous_terminal:+d}")
    print(f"FALSE_POSITIVES_DEMOTED={len(demoted)}")
    print(f"FALSE_PENDING_ROWS_TERMINALIZED={len(terminalized)}")
    print(f"STATUS_CHANGED_ROWS={len(changed)}")
    print("DEMOTED_IDS=" + ",".join(demoted))
    print("TERMINALIZED_IDS=" + ",".join(terminalized))
    print("STATUS_CHANGED_IDS=" + ",".join(changed))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
