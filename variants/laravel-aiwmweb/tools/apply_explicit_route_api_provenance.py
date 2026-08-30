#!/usr/bin/env python3
"""Apply strict exact-SHA route/API provenance that the generic matcher cannot infer.

This verifier is intentionally narrow. It only consumes manifest entries under
`route_api_provenance` from pushed exact-SHA snapshots, and requires a real
route declaration, declared action, operation-linked test, behavior acceptance
and operation-linked closure evidence. It does not infer parity from broad
controller or domain presence.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

TERMINAL_STATES = finalize.TERMINAL_STATES


def git_show(sha: str, path: str) -> str:
    return reconcile.run_git("show", f"{sha}:{path}", check=False)


def route_literals(value: str) -> list[str]:
    value = (value or "").split("|")[0].strip().lower()
    value = re.sub(r"\{[^}]+\}", "{}", value)
    literals: list[str] = []
    for part in value.strip("/").split("/"):
        part = part.strip()
        if not part or part == "{}" or part in {"api", "v1", "v2", "tenants", "tenant"}:
            continue
        cleaned = re.sub(r"[^a-z0-9_-]", "", part)
        if cleaned:
            literals.append(cleaned)
    return literals


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


def security_signals(kind: str, mode: str, destination: str, action: str, acceptance: str, operation_id: str) -> list[str]:
    route_low = destination.lower()
    action_low = action.lower()
    test_low = acceptance.lower()
    signals: list[str] = []

    if kind == "route":
        require("auth" in route_low and "tenant.context" in route_low,
                f"explicit route/API provenance lacks auth+tenant route middleware: {operation_id}")
        require("tenantauthorizer" in action_low and "authorize" in action_low,
                f"explicit route/API provenance lacks tenant authorization action evidence: {operation_id}")
        require(("assertnotfound" in test_low or "404" in test_low) and ("assertforbidden" in test_low or "403" in test_low),
                f"explicit route/API provenance lacks route fail-closed acceptance: {operation_id}")
        signals.extend(["middleware:auth", "middleware:tenant.context", "authorization:TenantAuthorizer", "test:404", "test:403"])
        return signals

    require(kind == "api", f"unsupported explicit route/API provenance kind: {operation_id}:{kind}")
    if mode == "tenant_selected":
        require("auth" in route_low,
                f"explicit API provenance lacks authenticated route boundary: {operation_id}")
        require("tenantauthorizer" in action_low and "tenantcontext" in action_low and "authorize" in action_low,
                f"explicit API provenance lacks selected-tenant authorization boundary: {operation_id}")
        require("assertunauthorized" in test_low or "401" in test_low,
                f"explicit API provenance lacks unauthenticated fail-closed acceptance: {operation_id}")
        require("assertforbidden" in test_low or "403" in test_low,
                f"explicit API provenance lacks authorization fail-closed acceptance: {operation_id}")
        require("assertnotfound" in test_low or "404" in test_low,
                f"explicit API provenance lacks foreign-tenant fail-closed acceptance: {operation_id}")
        require("409" in test_low,
                f"explicit API provenance lacks ambiguous-tenant fail-closed acceptance: {operation_id}")
        signals.extend(["middleware:auth", "tenant:selected", "authorization:TenantAuthorizer", "test:401", "test:403", "test:404", "test:409"])
        return signals

    if mode == "tenant_neutral":
        require("web" in route_low and "auth" not in route_low and "tenant.context" not in route_low,
                f"explicit tenant-neutral API provenance route boundary is not neutral: {operation_id}")
        require("assertnotcontains('auth'" in test_low and "assertnotcontains('tenant.context'" in test_low,
                f"explicit tenant-neutral API provenance lacks middleware-neutral acceptance: {operation_id}")
        require("parameternames" in test_low and "assertsame([]," in test_low,
                f"explicit tenant-neutral API provenance lacks zero-parameter acceptance: {operation_id}")
        require("evil.example" in test_low and "assertredirect('/')" in test_low,
                f"explicit tenant-neutral API provenance lacks safe-redirect acceptance: {operation_id}")
        signals.extend(["middleware:web-only", "tenant:neutral", "route:no-parameters", "redirect:safe-local-only"])
        return signals

    raise SystemExit(f"explicit API provenance requires known security_mode: {operation_id}:{mode}")


def apply(payload: dict[str, Any], manifest: dict[str, Any]) -> list[str]:
    rows = payload.get("operations", [])
    rows_by_id = {str(row.get("operation_id") or ""): row for row in rows}
    applied: list[str] = []

    for source in manifest.get("countable_sources", []):
        evidence_map = source.get("route_api_provenance") or {}
        if not evidence_map:
            continue
        source_sha = str(source.get("sha") or "")
        require(bool(source_sha), "explicit route/API provenance source is missing sha")
        require(finalize.source_is_pushed(source_sha),
                f"explicit route/API provenance source is not reachable from a pushed remote ref: {source_sha}")
        domains = set(source.get("domains") or [])

        for operation_id, evidence in evidence_map.items():
            row = rows_by_id.get(operation_id)
            require(row is not None, f"explicit route/API provenance references unknown operation: {operation_id}")
            require(row.get("migration_state") == "PENDING",
                    f"explicit route/API provenance would double-count terminal operation: {operation_id}")
            kind = str(row.get("kind") or "")
            require(kind in {"route", "api"}, f"explicit route/API provenance has unsupported kind: {operation_id}:{kind}")
            require(str(row.get("domain")) in domains,
                    f"explicit route/API provenance source does not own operation domain: {operation_id}")

            destination_path = str(evidence.get("destination_path") or "")
            action_path = str(evidence.get("action_path") or "")
            acceptance_path = str(evidence.get("acceptance_test") or "")
            link_test_path = str(evidence.get("operation_link_test") or "")
            evidence_path = str(evidence.get("evidence_path") or "")
            mode = str(evidence.get("security_mode") or "")
            for path in (destination_path, action_path, acceptance_path, link_test_path, evidence_path):
                require(bool(path), f"explicit route/API provenance is missing required path: {operation_id}")

            destination = git_show(source_sha, destination_path)
            action = git_show(source_sha, action_path)
            acceptance = git_show(source_sha, acceptance_path)
            link_test = git_show(source_sha, link_test_path)
            closure_evidence = git_show(source_sha, evidence_path)
            require(all((destination, action, acceptance, link_test, closure_evidence)),
                    f"explicit route/API provenance exact-SHA files are incomplete: {operation_id}")
            require(operation_id in link_test and operation_id in closure_evidence,
                    f"explicit route/API provenance is not operation-linked in test/evidence: {operation_id}")

            literals = route_literals(str(row.get("route_screen") or ""))
            destination_low = destination.lower()
            acceptance_low = acceptance.lower()
            require(bool(literals), f"explicit route/API provenance has no canonical route literals: {operation_id}")
            require(all(literal in destination_low for literal in literals),
                    f"explicit route/API destination does not match normalized canonical route: {operation_id}")
            require(literals[-1] in acceptance_low,
                    f"explicit route/API acceptance does not exercise canonical route: {operation_id}")

            action_stem = Path(action_path).stem.lower()
            require(action_stem in destination_low,
                    f"explicit route/API destination is not wired to declared action: {operation_id}")
            signals = security_signals(kind, mode, destination, action, acceptance, operation_id)

            row["migration_state"] = "ADAPTED"
            row["laravel_destination"] = destination_path
            row["acceptance_test"] = acceptance_path
            row["evidence"] = (
                f"{source['label']}@{source_sha}: {destination_path}; explicit-route-api:{operation_id}; "
                f"action:{action_path}; acceptance:{acceptance_path}; evidence:{evidence_path}"
            )
            row["reconciliation"] = {
                "decision": "ADAPTED",
                "reason": (
                    "Exact pushed route/API provenance is operation-linked to a real route declaration, "
                    "declared action, focused operation-ID binding, runtime acceptance, and strict security contract."
                ),
                "source_label": source["label"],
                "source_sha": source_sha,
                "destination_path": destination_path,
                "evidence_mode": "explicit_route_api_contract",
                "action_path": action_path,
                "evidence_path": evidence_path,
                "signals": [
                    f"operation:{operation_id}",
                    f"test:{acceptance_path}",
                    f"operation-link-test:{link_test_path}",
                    *signals,
                ],
            }
            applied.append(operation_id)

    finalize.finalize_summary(payload, rows, manifest)
    payload["classification_policy"]["explicit_route_api_policy"] = (
        "route/API rows may be terminalized by route_api_provenance only when an exact pushed source "
        "proves normalized route identity, declared action wiring, operation-ID linkage, runtime acceptance, "
        "and tenant-selected or explicitly tenant-neutral security semantics"
    )

    validation = payload.setdefault("validation", {})
    validation["explicit_route_api_contract_terminals"] = sorted(applied)
    validation["explicit_route_api_contract_count"] = len(applied)
    validation["errors"] = list(validation.get("errors") or [])
    validation["passed"] = not validation["errors"]
    if validation["errors"]:
        raise SystemExit("explicit route/API provenance validation failed:\n- " + "\n- ".join(validation["errors"]))
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
    require(len(payload.get("operations", [])) == args.check_total,
            f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}")

    applied = apply(payload, manifest)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    explicit_routes = len(payload.get("validation", {}).get("explicit_route_contract_terminals", []))
    needle = f"- Explicit route contracts: **{explicit_routes}**\n"
    markdown = markdown.replace(needle, needle + f"- Explicit route/API provenance contracts: **{len(applied)}**\n", 1)
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"EXPLICIT_ROUTE_API_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("EXPLICIT_ROUTE_API_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
