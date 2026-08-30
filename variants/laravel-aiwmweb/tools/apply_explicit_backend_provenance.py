#!/usr/bin/env python3
"""Apply narrow exact-SHA provenance for backend APIs/services the generic matcher cannot infer."""
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


def security_signals(mode: str, destination: str, action: str, acceptance: str, operation_id: str) -> list[str]:
    route = destination.lower()
    code = action.lower()
    test = acceptance.lower()

    if mode == "session_login":
        require("route::post('/api/login'" in route and "democontroller::class, 'login'" in route,
                f"login provenance lacks real POST /api/login wiring: {operation_id}")
        require("function login" in code and "auth::attempt" in code and "session()->regenerate" in code,
                f"login provenance lacks credential/session implementation: {operation_id}")
        require("assertauthenticatedas" in test and "assertstatus(422)" in test and "assertguest" in test,
                f"login provenance lacks success + invalid-credential acceptance: {operation_id}")
        return ["auth:credential-entrypoint", "session:regenerate-on-login", "test:authenticated", "test:invalid-credentials-422"]

    if mode == "session_logout":
        require("route::post('/api/logout'" in route and "middleware('auth')" in route,
                f"logout provenance lacks authenticated POST /api/logout wiring: {operation_id}")
        require("function logout" in code and "auth::logout" in code and "session()->invalidate" in code and "regeneratetoken" in code,
                f"logout provenance lacks session invalidation implementation: {operation_id}")
        require("actingas" in test and "assertguest" in test and "['ok' => true]" in test,
                f"logout provenance lacks authenticated invalidation acceptance: {operation_id}")
        return ["middleware:auth", "session:invalidate-on-logout", "csrf:token-regenerated", "test:guest-after-logout"]

    if mode == "setup_read":
        require("route::middleware('web')" in route and "->get('/setup'" in route,
                f"setup read provenance lacks web GET /setup route: {operation_id}")
        require("auth" not in route and "tenant.context" not in route,
                f"setup read provenance is not anonymous/tenant-neutral: {operation_id}")
        require("function __invoke" in code and "status['complete']" in code and "redirect('/')" in code and "->render" in code,
                f"setup read provenance lacks authoritative status/render implementation: {operation_id}")
        require("assertnotcontains('auth'" in test and "assertnotcontains('tenant.context'" in test and "assertdontsee" in test,
                f"setup read provenance lacks neutral/non-secret acceptance: {operation_id}")
        return ["middleware:web-only", "tenant:neutral-first-run", "setup:authoritative-status", "secret:not-rendered"]

    if mode == "setup_mutation":
        require("route::middleware('web')" in route and "->post('/setup'" in route,
                f"setup mutation provenance lacks web POST /setup route: {operation_id}")
        require("auth" not in route and "tenant.context" not in route,
                f"setup mutation provenance is not anonymous first-run boundary: {operation_id}")
        require("admin_password" in code and "confirmed" in code and "mutationservice->apply" in code and "failure_message" in code,
                f"setup mutation provenance lacks validated fail-closed mutation implementation: {operation_id}")
        require("hash::check" in test and "assertsessionhaserrors" in test and "existing identity state" in test.lower(),
                f"setup mutation provenance lacks hashed-owner + validation + preexisting-state acceptance: {operation_id}")
        return ["middleware:web-csrf", "tenant:neutral-first-run", "password:hashed", "existing-installation:fail-closed", "validation:no-mutation"]

    if mode == "setup_render_service":
        require("class databasesetuppageservice" in route and "function render" in route and "response()->view('setup'" in route,
                f"setup render provenance lacks real page service: {operation_id}")
        require("databasesetupreadservice" in route and "function status" in route,
                f"setup render provenance lacks authoritative read composition: {operation_id}")
        require("method=\"post\"" in test and "assertstringnotcontainsstring" in test and "db-render-secret-never-show" in test,
                f"setup render provenance lacks real form + escaped/non-secret acceptance: {operation_id}")
        require("post('/setup'" in test and "get('/setup')->assertredirect('/')" in test,
                f"setup render provenance lacks completed-installation lifecycle acceptance: {operation_id}")
        return ["service:authoritative-setup-page", "secret:not-rendered", "output:escaped", "setup:lifecycle-composed"]

    raise SystemExit(f"unknown explicit backend security_mode: {operation_id}:{mode}")


def apply(payload: dict[str, Any], manifest: dict[str, Any]) -> list[str]:
    rows = payload.get("operations", [])
    rows_by_id = {str(row.get("operation_id") or ""): row for row in rows}
    applied: list[str] = []

    for source in manifest.get("countable_sources", []):
        evidence_map = source.get("backend_provenance") or {}
        if not evidence_map:
            continue
        source_sha = str(source.get("sha") or "")
        require(bool(source_sha), "explicit backend provenance source is missing sha")
        require(finalize.source_is_pushed(source_sha),
                f"explicit backend provenance source is not reachable from a pushed remote ref: {source_sha}")
        domains = set(source.get("domains") or [])

        for operation_id, evidence in evidence_map.items():
            row = rows_by_id.get(operation_id)
            require(row is not None, f"explicit backend provenance references unknown operation: {operation_id}")
            require(row.get("migration_state") == "PENDING",
                    f"explicit backend provenance would double-count terminal operation: {operation_id}")
            kind = str(row.get("kind") or "")
            require(kind in {"api", "service"}, f"explicit backend provenance unsupported kind: {operation_id}:{kind}")
            require(str(row.get("domain")) in domains,
                    f"explicit backend provenance source does not own operation domain: {operation_id}")

            destination_path = str(evidence.get("destination_path") or "")
            action_path = str(evidence.get("action_path") or destination_path)
            acceptance_path = str(evidence.get("acceptance_test") or "")
            link_test_path = str(evidence.get("operation_link_test") or "")
            evidence_path = str(evidence.get("evidence_path") or "")
            mode = str(evidence.get("security_mode") or "")
            for path in (destination_path, action_path, acceptance_path, link_test_path, evidence_path):
                require(bool(path), f"explicit backend provenance missing required path: {operation_id}")

            destination = git_show(source_sha, destination_path)
            action = git_show(source_sha, action_path)
            acceptance = git_show(source_sha, acceptance_path)
            link_test = git_show(source_sha, link_test_path)
            closure_evidence = git_show(source_sha, evidence_path)
            require(all((destination, action, acceptance, link_test, closure_evidence)),
                    f"explicit backend provenance exact-SHA files are incomplete: {operation_id}")
            require(operation_id in link_test and operation_id in closure_evidence,
                    f"explicit backend provenance is not operation-linked in test/evidence: {operation_id}")

            if kind == "api":
                route_literal = str(row.get("route_screen") or "").strip('/').split('/')[-1].lower()
                require(route_literal and route_literal in destination.lower(),
                        f"explicit backend API destination does not match canonical route literal: {operation_id}")
                action_stem = Path(action_path).stem.lower()
                require(action_stem in destination.lower(),
                        f"explicit backend API destination is not wired to declared action: {operation_id}")
            else:
                source_service = str(row.get("service") or "").lower()
                require(source_service, f"explicit backend service row lacks canonical service identity: {operation_id}")

            signals = security_signals(mode, destination, action, acceptance, operation_id)
            row["migration_state"] = "ADAPTED"
            row["laravel_destination"] = destination_path
            row["acceptance_test"] = acceptance_path
            row["evidence"] = (
                f"{source['label']}@{source_sha}: {destination_path}; explicit-backend:{operation_id}; "
                f"action:{action_path}; acceptance:{acceptance_path}; evidence:{evidence_path}"
            )
            row["reconciliation"] = {
                "decision": "ADAPTED",
                "reason": (
                    "Exact pushed backend provenance is operation-linked to a real implementation, "
                    "focused canonical binding, runtime acceptance, closure evidence, and a narrow security contract."
                ),
                "source_label": source["label"],
                "source_sha": source_sha,
                "destination_path": destination_path,
                "evidence_mode": "explicit_backend_contract",
                "action_path": action_path,
                "evidence_path": evidence_path,
                "signals": [f"operation:{operation_id}", f"test:{acceptance_path}", f"operation-link-test:{link_test_path}", *signals],
            }
            applied.append(operation_id)

    finalize.finalize_summary(payload, rows, manifest)
    payload["classification_policy"]["explicit_backend_policy"] = (
        "API/service rows may be terminalized by backend_provenance only when an exact pushed source proves "
        "operation-ID linkage, real implementation wiring, runtime acceptance, closure evidence, and one of the "
        "enumerated session/setup security contracts"
    )
    validation = payload.setdefault("validation", {})
    validation["explicit_backend_contract_terminals"] = sorted(applied)
    validation["explicit_backend_contract_count"] = len(applied)
    validation["errors"] = list(validation.get("errors") or [])
    validation["passed"] = not validation["errors"]
    if validation["errors"]:
        raise SystemExit("explicit backend provenance validation failed:\n- " + "\n- ".join(validation["errors"]))
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
    markdown = markdown.replace(needle, needle + f"- Explicit backend provenance contracts: **{len(applied)}**\n", 1)
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"EXPLICIT_BACKEND_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("EXPLICIT_BACKEND_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
