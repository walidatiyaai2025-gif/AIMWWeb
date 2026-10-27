#!/usr/bin/env python3
"""Apply exact-SHA focused background-job closure evidence to parity output.

Background-job rows remain PENDING unless an explicit closure-evidence document
opts into this contract and binds one canonical operation to an already-pushed
exact SHA. The verifier then requires operation-linked production code, focused
tests, and applicable tenant-safety assertions before granting terminal credit.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

TERMINAL_STATES = finalize.TERMINAL_STATES
EVIDENCE_ROOT = Path("variants/laravel-aiwmweb/docs/closure-evidence")
EVIDENCE_MODE = "focused_background_job_contract"


def load_contracts() -> list[tuple[Path, dict[str, Any]]]:
    contracts: list[tuple[Path, dict[str, Any]]] = []
    for path in sorted(EVIDENCE_ROOT.glob("*.json")):
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise SystemExit(f"invalid closure evidence JSON: {path}: {exc}") from exc
        if not isinstance(document, dict) or document.get("evidence_mode") != EVIDENCE_MODE:
            continue
        contracts.append((path, document))
    return contracts


def require_string(document: dict[str, Any], key: str, path: Path) -> str:
    value = document.get(key)
    if not isinstance(value, str) or not value.strip():
        raise SystemExit(f"focused background-job evidence missing {key}: {path}")
    return value.strip()


def focused_security_ok(row: dict[str, Any], test_text: str) -> tuple[bool, list[str]]:
    text = test_text.lower()
    signals: list[str] = []

    if bool(row.get("tenant_owned")):
        tenant_tokens = (
            "tenantcontext",
            "tenanta",
            "tenantb",
            "tenant partition",
            "tenant-scoped",
            "tenant scoped",
        )
        if "tenant" not in text or not any(token in text for token in tenant_tokens):
            return False, ["missing focused tenant-isolation assertion"]
        signals.append("test:tenant-isolation")

    # Internal background jobs do not invent an HTTP authorization surface. If
    # the canonical row is a mutation/high-risk boundary, require the focused
    # test to prove fail-closed/identity behavior instead of fabricating RBAC.
    risk = str(row.get("risk") or "").lower()
    if bool(row.get("mutation")) or risk in {"high", "critical"}:
        if not any(token in text for token in ("fail closed", "invalid", "idempotent", "stale")):
            return False, ["missing focused fail-closed/idempotency assertion"]
        signals.append("test:fail-closed-idempotency")

    return True, signals


def apply(payload: dict[str, Any]) -> list[str]:
    rows_by_id = {str(row.get("operation_id") or ""): row for row in payload.get("operations", [])}
    applied: list[str] = []
    seen: set[str] = set()

    for path, document in load_contracts():
        operation_id = require_string(document, "operation_id", path)
        source_sha = require_string(document, "source_sha", path)
        state = require_string(document, "terminal_state", path)

        if operation_id in seen:
            raise SystemExit(f"duplicate focused background-job evidence: {operation_id}")
        seen.add(operation_id)

        row = rows_by_id.get(operation_id)
        if row is None:
            raise SystemExit(f"focused background-job evidence references unknown operation: {operation_id}")
        if row.get("kind") != "background_job":
            raise SystemExit(f"focused background-job evidence kind mismatch: {operation_id}")
        if row.get("migration_state") != "PENDING":
            raise SystemExit(f"focused background-job evidence would double-count terminal row: {operation_id}")
        if state not in TERMINAL_STATES:
            raise SystemExit(f"focused background-job evidence has non-terminal state: {operation_id}")
        if document.get("domain") and document.get("domain") != row.get("domain"):
            raise SystemExit(f"focused background-job evidence domain mismatch: {operation_id}")
        if not finalize.source_is_pushed(source_sha):
            raise SystemExit(f"focused background-job source is not reachable from a pushed ref: {source_sha}")

        laravel = document.get("laravel")
        if not isinstance(laravel, dict):
            raise SystemExit(f"focused background-job evidence missing laravel contract: {operation_id}")
        destination = str(laravel.get("destination") or "").strip()
        acceptance_test = str(laravel.get("acceptance_test") or "").strip()
        if not destination or not acceptance_test:
            raise SystemExit(f"focused background-job evidence missing code/test paths: {operation_id}")

        source = {
            "label": f"Focused background-job closure {operation_id}",
            "sha": source_sha,
            "domains": [str(row.get("domain") or "")],
            "operation_ids": [operation_id],
        }
        snapshot = reconcile.load_snapshot(source)
        files = {file.path: file for file in snapshot.files}
        code = files.get(destination)
        test = files.get(acceptance_test)
        if code is None or test is None or not test.test:
            raise SystemExit(f"focused background-job code/test paths are absent at exact SHA: {operation_id}")
        if operation_id not in code.text or operation_id not in test.text:
            raise SystemExit(f"focused background-job code/test are not operation-linked: {operation_id}")

        job_name = str(row.get("background_job") or "").strip()
        if job_name and job_name.lower() not in (destination + "\n" + code.text).lower():
            raise SystemExit(f"focused background-job destination does not implement canonical job identity: {operation_id}")

        security_ok, security_signals = focused_security_ok(row, test.text)
        if not security_ok:
            raise SystemExit(f"focused background-job security contract failed for {operation_id}: {security_signals[0]}")

        row["migration_state"] = state
        row["laravel_destination"] = destination
        row["acceptance_test"] = acceptance_test
        row["evidence"] = (
            f"Focused background-job closure@{source_sha}: {destination}; "
            f"operation:{operation_id}; test:{acceptance_test}; evidence:{path.as_posix()}"
        )
        row["reconciliation"] = {
            "decision": state,
            "reason": (
                "Exact pushed focused background-job closure is linked to the canonical operation ID "
                "in production code and focused tests with applicable tenant/fail-closed assertions."
            ),
            "source_label": f"Focused background-job closure {operation_id}",
            "source_sha": source_sha,
            "destination_path": destination,
            "evidence_mode": EVIDENCE_MODE,
            "evidence_path": path.as_posix(),
            "signals": [
                f"operation:{operation_id}",
                f"production-marker:{destination}",
                f"test:{acceptance_test}",
                f"evidence:{path.as_posix()}",
                *security_signals,
            ],
        }
        applied.append(operation_id)

    manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    finalize.finalize_summary(payload, payload["operations"], manifest)
    validation = payload.setdefault("validation", {})
    validation["focused_background_job_contract_terminals"] = sorted(applied)
    validation["focused_background_job_contract_count"] = len(applied)

    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    errors = list(validation.get("errors") or [])
    if state_total != len(payload["operations"]):
        errors.append("focused background-job status totals do not reconcile")
    if totals["terminal"] != expected_terminal:
        errors.append("focused background-job processing counted BLOCKED or PENDING as terminal")
    validation["status_totals_reconcile"] = state_total == len(payload["operations"])
    validation["terminal_excludes_blocked"] = totals["terminal"] == expected_terminal
    validation["errors"] = errors
    validation["passed"] = not errors
    if errors:
        raise SystemExit("focused background-job validation failed:\n- " + "\n- ".join(errors))

    return applied


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--summary-output", type=Path)
    parser.add_argument("--markdown-output", type=Path, required=True)
    parser.add_argument("--check-total", type=int, default=931)
    args = parser.parse_args()

    payload = json.loads(args.input.read_text(encoding="utf-8"))
    if len(payload.get("operations", [])) != args.check_total:
        raise SystemExit(f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}")

    applied = apply(payload)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    explicit = len(payload.get("validation", {}).get("explicit_route_contract_terminals", []))
    focused_visible = len(payload.get("validation", {}).get("focused_closure_contract_terminals", []))
    focused_service = len(payload.get("validation", {}).get("focused_service_contract_terminals", []))
    needle = f"- Explicit route contracts: **{explicit}**\n"
    addition = (
        f"- Focused visible-control contracts: **{focused_visible}**\n"
        f"- Focused service contracts: **{focused_service}**\n"
        f"- Focused background-job contracts: **{len(applied)}**\n"
    )
    # render_markdown may already include focused lines from earlier pipeline stages;
    # avoid duplicate labels while ensuring this new count is visible.
    if "Focused background-job contracts" not in markdown:
        if "Focused service contracts" in markdown:
            service_line = f"- Focused service contracts: **{focused_service}**\n"
            markdown = markdown.replace(service_line, service_line + f"- Focused background-job contracts: **{len(applied)}**\n", 1)
        elif needle in markdown:
            markdown = markdown.replace(needle, needle + addition, 1)
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"FOCUSED_BACKGROUND_JOBS_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("FOCUSED_BACKGROUND_JOB_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
