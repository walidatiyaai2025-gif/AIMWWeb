#!/usr/bin/env python3
"""Apply exact-SHA focused service closure evidence to parity output.

This pass is additive and strict. It does not infer service parity from a file name,
open PR, or generic domain presence. A service row can advance only when a closure
evidence document opts into ``service_closure_contract`` and names a pushed exact
implementation SHA plus exact production and focused-test paths.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

TERMINAL_STATES = finalize.TERMINAL_STATES
EVIDENCE_DIR = reconcile.VARIANT / "docs" / "closure-evidence"


def load_contract_documents() -> list[tuple[Path, dict[str, Any], dict[str, Any]]]:
    documents: list[tuple[Path, dict[str, Any], dict[str, Any]]] = []
    for path in sorted(EVIDENCE_DIR.glob("*.json")):
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise SystemExit(f"focused service evidence is invalid JSON: {path}: {exc}") from exc
        if not isinstance(document, dict):
            continue
        contract = document.get("service_closure_contract")
        if isinstance(contract, dict):
            documents.append((path, document, contract))
    return documents


def require_string(value: Any, field: str, evidence_path: Path) -> str:
    text = str(value or "").strip()
    if not text:
        raise SystemExit(f"focused service evidence missing {field}: {evidence_path}")
    return text


def security_contract(row: dict[str, Any], test_text: str, evidence_path: Path) -> list[str]:
    low = test_text.lower()
    signals: list[str] = []

    if bool(row.get("tenant_owned")):
        tenant_proof = (
            ("assertnotfound" in low or "404" in low)
            and any(token in low for token in ("tenant", "foreign", "cross-tenant", "cross_tenant"))
        )
        if not tenant_proof:
            raise SystemExit(
                f"focused service evidence lacks tenant-isolation proof for {row['operation_id']}: {evidence_path}"
            )
        signals.append("test:tenant-isolation")

    risk = str(row.get("risk") or "").lower()
    if bool(row.get("mutation")) or risk in {"high", "critical"}:
        if "assertforbidden" not in low and "403" not in low:
            raise SystemExit(
                f"focused service evidence lacks authorization proof for {row['operation_id']}: {evidence_path}"
            )
        signals.append("test:authorization")

    return signals


def apply(payload: dict[str, Any]) -> list[str]:
    rows = payload.get("operations", [])
    rows_by_id = {str(row.get("operation_id") or ""): row for row in rows}
    applied: list[str] = []
    seen: set[str] = set()

    for evidence_path, document, contract in load_contract_documents():
        op_id = require_string(document.get("operation_id"), "operation_id", evidence_path)
        if op_id in seen:
            raise SystemExit(f"focused service evidence duplicates operation ownership: {op_id}")
        seen.add(op_id)

        row = rows_by_id.get(op_id)
        if row is None:
            raise SystemExit(f"focused service evidence references unknown operation: {op_id}")
        if row.get("kind") != "service":
            raise SystemExit(f"focused service evidence may only terminalize service rows: {op_id}")
        if row.get("migration_state") != "PENDING":
            raise SystemExit(f"focused service evidence would double-count terminal operation: {op_id}")

        state = require_string(document.get("terminal_state"), "terminal_state", evidence_path)
        if state not in TERMINAL_STATES:
            raise SystemExit(f"focused service evidence has non-terminal state for {op_id}: {state}")

        canonical = document.get("canonical_operation") or {}
        if str(canonical.get("kind") or "") != "service":
            raise SystemExit(f"focused service evidence kind mismatch for {op_id}")
        if str(canonical.get("domain") or "") != str(row.get("domain") or ""):
            raise SystemExit(f"focused service evidence domain mismatch for {op_id}")
        if str(canonical.get("source") or "") != str(row.get("current_source") or ""):
            raise SystemExit(f"focused service evidence source mismatch for {op_id}")

        implementation_sha = require_string(contract.get("implementation_sha"), "implementation_sha", evidence_path)
        destination_path = require_string(contract.get("destination_path"), "destination_path", evidence_path)
        acceptance_test = require_string(contract.get("acceptance_test"), "acceptance_test", evidence_path)

        if not finalize.source_is_pushed(implementation_sha):
            raise SystemExit(
                f"focused service implementation SHA is not reachable from a pushed remote ref: {implementation_sha}"
            )

        source = {
            "label": f"Focused service closure {op_id}",
            "sha": implementation_sha,
            "domains": [str(row.get("domain"))],
            "operation_ids": [op_id],
        }
        snapshot = reconcile.load_snapshot(source)
        files = {file.path: file for file in snapshot.files}
        destination = files.get(destination_path)
        test = files.get(acceptance_test)
        if destination is None or destination.test:
            raise SystemExit(f"focused service destination is missing from pushed implementation: {op_id}")
        if test is None or not test.test:
            raise SystemExit(f"focused service acceptance test is missing from pushed implementation: {op_id}")

        if op_id not in destination.text or op_id not in test.text:
            raise SystemExit(f"focused service code/test is not operation-linked: {op_id}")

        canonical_service = str(row.get("service") or "").strip()
        if canonical_service and canonical_service.lower() not in destination.text.lower():
            raise SystemExit(f"focused service destination lacks canonical service symbol: {op_id}")

        method = reconcile.method_name(row)
        method_core = method.removesuffix("Async")
        if method_core and method_core.lower() not in destination.text.lower():
            raise SystemExit(f"focused service destination lacks canonical member symbol: {op_id}")

        security_signals = security_contract(row, test.text, evidence_path)
        evidence_relative = evidence_path.relative_to(reconcile.ROOT).as_posix()

        row["migration_state"] = state
        row["laravel_destination"] = destination_path
        row["acceptance_test"] = acceptance_test
        row["evidence"] = (
            f"Focused service closure@{implementation_sha}: {destination_path}; "
            f"operation:{op_id}; test:{acceptance_test}; evidence:{evidence_relative}"
        )
        row["reconciliation"] = {
            "decision": state,
            "reason": (
                "Exact pushed service implementation is linked to the canonical operation ID, "
                "canonical service/member/source metadata, focused acceptance, and applicable security proof."
            ),
            "source_label": f"Focused service closure {op_id}",
            "source_sha": implementation_sha,
            "destination_path": destination_path,
            "evidence_mode": "focused_service_contract",
            "evidence_path": evidence_relative,
            "signals": [
                f"operation:{op_id}",
                f"service:{canonical_service}",
                f"member:{method}",
                f"test:{acceptance_test}",
                f"evidence:{evidence_relative}",
                *security_signals,
            ],
        }
        applied.append(op_id)

    manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    finalize.finalize_summary(payload, rows, manifest)
    payload["classification_policy"]["focused_service_policy"] = (
        "service rows remain PENDING unless an opted-in closure evidence contract names a pushed exact "
        "implementation SHA and the verifier confirms exact operation, canonical source/service/member, "
        "production path, focused test, and applicable tenant/authorization proof"
    )

    validation = payload.setdefault("validation", {})
    validation["focused_service_contract_terminals"] = applied
    validation["focused_service_contract_count"] = len(applied)

    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != len(rows):
        raise SystemExit("focused service status totals do not reconcile")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        raise SystemExit("focused service processing counted BLOCKED or PENDING as terminal")

    validation["status_totals_reconcile"] = True
    validation["terminal_excludes_blocked"] = True
    validation["passed"] = not (validation.get("errors") or [])
    if not validation["passed"]:
        raise SystemExit("focused service processing inherited reconciliation errors")

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
        raise SystemExit(
            f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}"
        )

    applied = apply(payload)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    validation = payload.get("validation", {})
    explicit = len(validation.get("explicit_route_contract_terminals", []))
    focused_visible = len(validation.get("focused_closure_contract_terminals", []))
    needle = f"- Explicit route contracts: **{explicit}**\n"
    markdown = markdown.replace(
        needle,
        needle
        + f"- Focused visible-control contracts: **{focused_visible}**\n"
        + f"- Focused service contracts: **{len(applied)}**\n",
        1,
    )
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"FOCUSED_SERVICES_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("FOCUSED_SERVICE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
