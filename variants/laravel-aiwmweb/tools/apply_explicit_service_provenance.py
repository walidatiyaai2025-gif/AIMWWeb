#!/usr/bin/env python3
"""Apply strict exact-SHA service provenance that the generic matcher cannot infer.

This verifier is intentionally narrow. It only consumes manifest entries under
`service_provenance` from pushed exact-SHA snapshots, and requires exact
operation linkage in production code, focused acceptance, closure evidence,
canonical service/method identity, and tenant/mutation security when applicable.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


def git_show(sha: str, path: str) -> str:
    return reconcile.run_git("show", f"{sha}:{path}", check=False)


def apply(payload: dict[str, Any], manifest: dict[str, Any]) -> list[str]:
    rows = payload.get("operations", [])
    rows_by_id = {str(row.get("operation_id") or ""): row for row in rows}
    applied: list[str] = []

    for source in manifest.get("countable_sources", []):
        evidence_map = source.get("service_provenance") or {}
        if not evidence_map:
            continue

        source_sha = str(source.get("sha") or "")
        require(bool(source_sha), "explicit service provenance source is missing sha")
        require(
            finalize.source_is_pushed(source_sha),
            f"explicit service provenance source is not reachable from a pushed remote ref: {source_sha}",
        )
        domains = set(source.get("domains") or [])

        for operation_id, evidence in evidence_map.items():
            row = rows_by_id.get(operation_id)
            require(row is not None, f"explicit service provenance references unknown operation: {operation_id}")
            require(
                row.get("migration_state") == "PENDING",
                f"explicit service provenance would double-count terminal operation: {operation_id}",
            )
            require(
                row.get("kind") == "service",
                f"explicit service provenance has unsupported kind: {operation_id}:{row.get('kind')}",
            )
            require(
                str(row.get("domain")) in domains,
                f"explicit service provenance source does not own operation domain: {operation_id}",
            )

            destination_path = str(evidence.get("destination_path") or "")
            acceptance_path = str(evidence.get("acceptance_test") or "")
            evidence_path = str(evidence.get("evidence_path") or "")
            for path in (destination_path, acceptance_path, evidence_path):
                require(bool(path), f"explicit service provenance is missing required path: {operation_id}")

            destination = git_show(source_sha, destination_path)
            acceptance = git_show(source_sha, acceptance_path)
            closure_evidence = git_show(source_sha, evidence_path)
            require(
                all((destination, acceptance, closure_evidence)),
                f"explicit service provenance exact-SHA files are incomplete: {operation_id}",
            )
            require(
                operation_id in destination and operation_id in acceptance and operation_id in closure_evidence,
                f"explicit service provenance is not operation-linked in code/test/evidence: {operation_id}",
            )

            canonical_service = str(row.get("service") or "")
            service_type, separator, service_method = canonical_service.partition(".")
            require(bool(separator and service_type and service_method),
                    f"explicit service provenance lacks canonical service.method identity: {operation_id}")
            destination_low = destination.lower()
            acceptance_low = acceptance.lower()
            require(service_type.lower() in destination_low,
                    f"explicit service destination lacks canonical service type: {operation_id}")
            require(service_method.lower() in destination_low,
                    f"explicit service destination lacks canonical method: {operation_id}")

            signals = [f"operation:{operation_id}", f"service:{canonical_service}", f"test:{acceptance_path}"]
            if bool(row.get("tenant_owned")):
                tenant_code = any(signal in destination_low for signal in ("tenantcontext", "tenant_id", "belongstotenant"))
                tenant_test = any(
                    signal in acceptance_low
                    for signal in ("foreign_tenant", "tenant_isolation", "modelnotfoundexception", "assertnotfound")
                )
                require(tenant_code and tenant_test,
                        f"explicit service provenance lacks tenant-isolation evidence: {operation_id}")
                signals.extend(["tenant:code-scope", "tenant:test-fail-closed"])

            if bool(row.get("mutation")):
                security_text = destination_low + "\n" + acceptance_low
                mutation_security = any(signal in security_text for signal in ("authorize", "permission", "approval"))
                require(mutation_security,
                        f"explicit mutating service provenance lacks authorization/approval evidence: {operation_id}")
                signals.append("mutation:authorization-or-approval")

            try:
                evidence_payload = json.loads(closure_evidence)
            except json.JSONDecodeError as exc:
                raise SystemExit(f"explicit service evidence JSON is invalid for {operation_id}: {exc}") from exc
            operation_evidence = evidence_payload.get("operation") or {}
            require(
                operation_evidence.get("operation_id") == operation_id
                and operation_evidence.get("terminal_state") == "ADAPTED",
                f"explicit service evidence does not declare the exact ADAPTED operation: {operation_id}",
            )

            row["migration_state"] = "ADAPTED"
            row["laravel_destination"] = destination_path
            row["acceptance_test"] = acceptance_path
            row["evidence"] = (
                f"{source['label']}@{source_sha}: {destination_path}; explicit-service:{operation_id}; "
                f"acceptance:{acceptance_path}; evidence:{evidence_path}"
            )
            row["reconciliation"] = {
                "decision": "ADAPTED",
                "reason": (
                    "Exact pushed service provenance is operation-linked to the canonical service/method, "
                    "focused runtime acceptance, closure evidence, and required tenant/security contracts."
                ),
                "source_label": source["label"],
                "source_sha": source_sha,
                "destination_path": destination_path,
                "evidence_mode": "explicit_service_contract",
                "evidence_path": evidence_path,
                "signals": signals,
            }
            applied.append(operation_id)

    finalize.finalize_summary(payload, rows, manifest)
    payload["classification_policy"]["explicit_service_policy"] = (
        "service rows may be terminalized by service_provenance only when an exact pushed source proves "
        "operation-linked production code, canonical service/method identity, focused runtime acceptance, "
        "closure evidence, tenant isolation when required, and mutation security when applicable"
    )

    validation = payload.setdefault("validation", {})
    validation["explicit_service_contract_terminals"] = sorted(applied)
    validation["explicit_service_contract_count"] = len(applied)
    validation["errors"] = list(validation.get("errors") or [])
    validation["passed"] = not validation["errors"]
    if validation["errors"]:
        raise SystemExit("explicit service provenance validation failed:\n- " + "\n- ".join(validation["errors"]))

    return sorted(applied)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--summary-output", type=Path)
    parser.add_argument("--markdown-output", type=Path, required=True)
    parser.add_argument("--check-total", type=int, default=931)
    args = parser.parse_args()

    payload = json.loads(args.input.read_text(encoding="utf-8"))
    manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    require(
        len(payload.get("operations", [])) == args.check_total,
        f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}",
    )

    applied = apply(payload, manifest)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    validation = payload.get("validation", {})
    explicit_routes = len(validation.get("explicit_route_contract_terminals", []))
    route_api = len(validation.get("explicit_route_api_contract_terminals", []))
    needle = f"- Explicit route contracts: **{explicit_routes}**\n"
    markdown = markdown.replace(
        needle,
        needle
        + f"- Explicit route/API provenance contracts: **{route_api}**\n"
        + f"- Explicit service contracts: **{len(applied)}**\n",
        1,
    )
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"EXPLICIT_SERVICE_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("EXPLICIT_SERVICE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
