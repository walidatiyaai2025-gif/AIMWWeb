#!/usr/bin/env python3
import json
import subprocess
from pathlib import Path

OP = "AIMW-EMAI-7E1D4105B0"
SHA = "faa63881a3f34ec885b1a1548933acfabe92af81"
ROOT = Path(__file__).resolve().parents[1]
manifest = ROOT / "docs" / "operation-parity-evidence-sources.json"
data = json.loads(manifest.read_text(encoding="utf-8"))
entry = {"label": "AIMW-EMAI-7E1D4105B0 CancelEdit focused closure", "sha": SHA, "observed_pr_head": SHA, "operation_ids": [OP]}
for key in ("countable_sources", "focused_closure_operation_sources"):
    data[key] = [row for row in data[key] if OP not in row.get("operation_ids", [])]
    data[key].append(entry)
manifest.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
out_json = ROOT / "docs" / "operation-parity-reconciliation.json"
out_md = ROOT / "docs" / "operation-parity-reconciliation.md"
summary = "/tmp/operation-parity-summary.json"
tools = ROOT / "tools"
commands = [
    ["finalize_operation_parity.py", "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_tenant_neutral_route_evidence.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_platform_admin_route_evidence.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_explicit_route_api_provenance.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_focused_closure_evidence.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_focused_service_evidence.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
    ["apply_focused_background_job_evidence.py", "--input", str(out_json), "--output", str(out_json), "--summary-output", summary, "--markdown-output", str(out_md), "--check-total", "931"],
]
for command in commands:
    subprocess.run(["python", str(tools / command[0]), *command[1:]], check=True)
