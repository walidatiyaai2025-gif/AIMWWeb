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
        require("assertconflict" in test_low or "409" in test_low,
                f"explicit API provenance lacks ambiguous-tenant fail-closed acceptance: {operation_id}")
        signals.extend(["middleware:auth", "tenant:selected", "authorization:TenantAuthorizer", "test:401", "test:403", "test:404", "test:409/conflict"])
        return signals

    if mode == "pre_tenant_session_auth":
        require("auth::attempt" in action_low,
                f"explicit pre-tenant session-auth API provenance lacks credential authentication: {operation_id}")
        require("session()->regenerate()" in action_low,
                f"explicit pre-tenant session-auth API provenance lacks session fixation protection: {operation_id}")
        require("assertauthenticatedas" in test_low or "assertauthenticated(" in test_low,
                f"explicit pre-tenant session-auth API provenance lacks successful authenticated-session acceptance: {operation_id}")
        require("assertstatus(422)" in test_low and "assertguest()" in test_low,
                f"explicit pre-tenant session-auth API provenance lacks invalid-credential fail-closed acceptance: {operation_id}")
        require("assertcontains('web'" in test_low,
                f"explicit pre-tenant session-auth API provenance lacks web-session middleware acceptance: {operation_id}")
        require("assertnotcontains('auth'" in test_low and "assertnotcontains('tenant.context'" in test_low,
                f"explicit pre-tenant session-auth API provenance does not prove anonymous pre-tenant reachability: {operation_id}")
        require("parameternames" in test_low and "assertsame([]," in test_low,
                f"explicit pre-tenant session-auth API provenance lacks zero-route-parameter acceptance: {operation_id}")
        signals.extend([
            "middleware:web",
            "auth:credential-boundary",
            "tenant:pre-context",
            "route:no-parameters",
            "session:regenerated",
            "test:authenticated",
            "test:invalid-credentials-422",
        ])
        return signals

    if mode == "authenticated_session_logout":
        require("auth::logout()" in action_low,
                f"explicit authenticated-session logout API provenance lacks authoritative logout: {operation_id}")
        require("session()->invalidate()" in action_low,
                f"explicit authenticated-session logout API provenance lacks session invalidation: {operation_id}")
        require("session()->regeneratetoken()" in action_low,
                f"explicit authenticated-session logout API provenance lacks CSRF-token regeneration: {operation_id}")
        require("assertcontains('web'" in test_low and "assertcontains('auth'" in test_low,
                f"explicit authenticated-session logout API provenance lacks authenticated web-route acceptance: {operation_id}")
        require("assertnotcontains('tenant.context'" in test_low,
                f"explicit authenticated-session logout API provenance must remain tenant-resource neutral: {operation_id}")
        require("parameternames" in test_low and "assertsame([]," in test_low,
                f"explicit authenticated-session logout API provenance lacks zero-route-parameter acceptance: {operation_id}")
        require("actingas" in test_low and "postjson('/api/logout')" in test_low and "assertguest()" in test_low,
                f"explicit authenticated-session logout API provenance lacks authenticated-to-guest runtime acceptance: {operation_id}")
        require("assertunauthorized" in test_low or "assertstatus(401)" in test_low,
                f"explicit authenticated-session logout API provenance lacks unauthenticated fail-closed acceptance: {operation_id}")
        require("assertexactjson(['ok' => true])" in test_low,
                f"explicit authenticated-session logout API provenance lacks truthful success response acceptance: {operation_id}")
        signals.extend([
            "middleware:web",
            "middleware:auth",
            "tenant:resource-neutral",
            "route:no-parameters",
            "session:logout",
            "session:invalidated",
            "csrf:token-regenerated",
            "test:authenticated-to-guest",
            "test:unauthorized-guest",
        ])
        return signals

    if mode == "pre_tenant_setup_mutation":
        require("web" in route_low and "auth" not in route_low and "tenant.context" not in route_low,
                f"explicit setup API provenance route is not anonymous pre-tenant web: {operation_id}")
        require("setupmutationcontroller::class" in route_low and "->post('/setup'" in route_low,
                f"explicit setup API provenance lacks exact POST /setup controller binding: {operation_id}")
        require("databasesetupmutationservice" in action_low and "->apply(" in action_low,
                f"explicit setup API provenance lacks real setup mutation service invocation: {operation_id}")
        require("assertcontains('web'" in test_low and "assertnotcontains('auth'" in test_low and "assertnotcontains('tenant.context'" in test_low,
                f"explicit setup API provenance lacks anonymous pre-tenant middleware acceptance: {operation_id}")
        require("parameternames" in test_low and "assertsame([]," in test_low,
                f"explicit setup API provenance lacks zero-route-parameter acceptance: {operation_id}")
        require("post('/setup'" in test_low and "assertredirect('/')" in test_low,
                f"explicit setup API provenance lacks successful runtime redirect acceptance: {operation_id}")
        require("assertstatus(400)" in test_low and "assertdontsee($password)" in test_low,
                f"explicit setup API provenance lacks bounded fail-closed failure acceptance: {operation_id}")
        require("hash::check" in test_low and "owner" in test_low and "permissions()->count()" in test_low,
                f"explicit setup API provenance lacks hashed-owner RBAC bootstrap acceptance: {operation_id}")
        require("@csrf" in test_low and "database_credentials_deployment_owned" in test_low,
                f"explicit setup API provenance lacks Laravel CSRF/deployment-owned credential adaptation proof: {operation_id}")
        signals.extend([
            "middleware:web",
            "auth:anonymous-first-run",
            "tenant:pre-context",
            "route:no-parameters",
            "setup:mutation-service",
            "setup:hashed-owner-rbac",
            "redirect:root-on-success",
            "failure:html-400-bounded",
            "csrf:retained-strengthening",
            "database-credentials:deployment-owned",
        ])
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
            if kind == "api" and mode in {"pre_tenant_session_auth", "authenticated_session_logout"}:
                action_method = str(evidence.get("action_method") or "").strip()
                require(bool(action_method),
                        f"explicit session API provenance is missing action_method: {operation_id}")
                method_low = action_method.lower()
                require(re.search(rf"function\s+{re.escape(method_low)}\s*\(", action.lower()) is not None,
                        f"explicit session API provenance action method is absent: {operation_id}")
                post_binding = re.compile(
                    rf"route::post\s*\(\s*['\"][^'\"]*{re.escape(literals[-1])}[^'\"]*['\"]\s*,\s*\[\s*{re.escape(action_stem)}::class\s*,\s*['\"]{re.escape(method_low)}['\"]\s*\]",
                    re.IGNORECASE,
                )
                require(post_binding.search(destination) is not None,
                        f"explicit session API provenance lacks exact POST/action binding: {operation_id}")
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
        "and tenant-selected, pre-tenant session-auth, authenticated-session logout, pre-tenant setup-mutation, "
        "or explicitly tenant-neutral security semantics"
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
