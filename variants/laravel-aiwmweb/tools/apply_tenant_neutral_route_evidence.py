#!/usr/bin/env python3
"""Apply strict evidence for canonical routes outside tenant-selected routing.

This verifier is intentionally narrow. It supports two source-proven security
boundaries for zero-parameter routes that canonical scanner metadata marks as
tenant-owned even though the route itself does not select a tenant:

- ``explicit_anonymous``: the canonical source explicitly opts out with
  ``AllowAnonymous`` and Laravel preserves web-only anonymous access.
- ``authenticated_fallback``: the canonical source inherits an application-wide
  ``FallbackPolicy`` requiring an authenticated user, and Laravel preserves
  ``web + auth`` without inventing a tenant route parameter.

Both modes require exact pushed implementation, action, focused test and closure
evidence. They never infer terminality from file or route presence alone.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

TENANT_NEUTRAL_MANIFEST = reconcile.VARIANT / "docs" / "tenant-neutral-route-evidence.json"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


def git_show(sha: str, path: str) -> str:
    return reconcile.run_git("show", f"{sha}:{path}", check=False)


def apply(
    payload: dict[str, Any],
    canonical_manifest: dict[str, Any],
    neutral_manifest: dict[str, Any],
) -> list[str]:
    source_sha = str(neutral_manifest.get("source_sha") or "").strip()
    evidence_map = neutral_manifest.get("routes") or {}

    if not evidence_map:
        return []
    require(bool(source_sha), "source-boundary route evidence source SHA is missing")
    require(
        finalize.source_is_pushed(source_sha),
        f"source-boundary route evidence source is not reachable from a pushed remote ref: {source_sha}",
    )

    rows_by_id = {
        str(row.get("operation_id") or ""): row
        for row in payload.get("operations", [])
    }
    applied: list[str] = []
    authenticated_fallback_applied: list[str] = []

    for operation_id, evidence in evidence_map.items():
        row = rows_by_id.get(operation_id)
        require(row is not None, f"source-boundary route evidence references unknown operation: {operation_id}")
        require(row.get("kind") == "route", f"source-boundary evidence requires route kind: {operation_id}")
        require(
            row.get("migration_state") == "PENDING",
            f"source-boundary route evidence would double-count terminal operation: {operation_id}",
        )
        require(bool(row.get("tenant_owned")), f"source-boundary route contract expects tenant-owned metadata: {operation_id}")
        require(not bool(row.get("mutation")), f"source-boundary route contract cannot terminalize mutation: {operation_id}")

        mode = str(evidence.get("security_mode") or "explicit_anonymous").strip()
        require(
            mode in {"explicit_anonymous", "authenticated_fallback"},
            f"source-boundary route evidence has unsupported security_mode: {operation_id}:{mode}",
        )

        destination_path = str(evidence.get("destination_path") or "")
        action_path = str(evidence.get("action_path") or "")
        acceptance_path = str(evidence.get("acceptance_test") or "")
        evidence_path = str(evidence.get("evidence_path") or "")
        for path in (destination_path, action_path, acceptance_path, evidence_path):
            require(bool(path), f"source-boundary route evidence is missing required path: {operation_id}")

        destination = git_show(source_sha, destination_path)
        action = git_show(source_sha, action_path)
        acceptance = git_show(source_sha, acceptance_path)
        closure_evidence = git_show(source_sha, evidence_path)
        canonical_source_path = str(row.get("current_source") or "")
        canonical_source = git_show(source_sha, canonical_source_path)
        require(
            all((destination, action, acceptance, closure_evidence, canonical_source)),
            f"source-boundary route exact-SHA files are incomplete: {operation_id}",
        )
        require(
            operation_id in acceptance and operation_id in closure_evidence,
            f"source-boundary route evidence is not operation-linked in test/evidence: {operation_id}",
        )

        route_screen = str(row.get("route_screen") or "")
        action_stem = Path(action_path).stem
        require(route_screen and route_screen in destination, f"source-boundary route path is not explicit: {operation_id}")
        require(action_stem in destination, f"source-boundary route is not wired to declared action: {operation_id}")
        require(route_screen in canonical_source, f"canonical source route mismatch: {operation_id}")

        acceptance_low = acceptance.lower()
        destination_low = destination.lower()
        action_low = action.lower()
        signals: list[str]

        if mode == "explicit_anonymous":
            require("AllowAnonymous" in canonical_source, f"canonical source is not explicitly anonymous: {operation_id}")
            require(
                "assertcontains('web'" in acceptance_low,
                f"tenant-neutral route lacks web middleware acceptance: {operation_id}",
            )
            require(
                "assertnotcontains('auth'" in acceptance_low
                and "assertnotcontains('tenant.context'" in acceptance_low,
                f"tenant-neutral route lacks explicit auth/tenant-context absence proof: {operation_id}",
            )
            require(
                "parameternames" in acceptance_low and "assertsame([]," in acceptance_low,
                f"tenant-neutral route lacks zero-parameter acceptance: {operation_id}",
            )
            require(
                "assertstringnotcontainsstring" in acceptance_low
                and "tenant alpha sentinel" in acceptance_low
                and "tenant beta sentinel" in acceptance_low
                and "$anonymous" in acceptance_low
                and "$alpha" in acceptance_low
                and "$beta" in acceptance_low,
                f"tenant-neutral route lacks deterministic identity non-disclosure proof: {operation_id}",
            )
            require(
                "tenantcontext" not in action_low
                and "tenantauthorizer" not in action_low
                and "request()->user" not in action_low,
                f"tenant-neutral route action unexpectedly resolves tenant/user authority: {operation_id}",
            )
            signals = [
                "source:AllowAnonymous",
                "middleware:web-only",
                "tenant:neutral",
                "route:no-parameters",
                "identity:no-disclosure",
                f"test:{acceptance_path}",
            ]
        else:
            source_auth_path = str(evidence.get("source_auth_path") or "").strip()
            require(bool(source_auth_path), f"authenticated-fallback route is missing source_auth_path: {operation_id}")
            source_auth = git_show(source_sha, source_auth_path)
            require(bool(source_auth), f"authenticated-fallback source auth file is missing at exact SHA: {operation_id}")
            require(
                "FallbackPolicy" in source_auth and "RequireAuthenticatedUser()" in source_auth,
                f"canonical source does not prove authenticated fallback policy: {operation_id}",
            )
            require(
                "AllowAnonymous" not in canonical_source,
                f"authenticated-fallback source route explicitly opts out of authentication: {operation_id}",
            )
            require(
                "web" in destination_low and "auth" in destination_low and "tenant.context" not in destination_low,
                f"authenticated-fallback Laravel route must be web+auth without tenant.context: {operation_id}",
            )
            require(
                "assertcontains('web'" in acceptance_low and "assertcontains('auth'" in acceptance_low,
                f"authenticated-fallback route lacks web+auth middleware acceptance: {operation_id}",
            )
            require(
                "assertnotcontains('tenant.context'" in acceptance_low,
                f"authenticated-fallback route must prove tenant.context absence: {operation_id}",
            )
            require(
                "parameternames" in acceptance_low and "assertsame([]," in acceptance_low,
                f"authenticated-fallback route lacks zero-parameter acceptance: {operation_id}",
            )
            require(
                "assertredirect('/login')" in acceptance_low,
                f"authenticated-fallback route lacks guest fail-closed login acceptance: {operation_id}",
            )
            require(
                "assertstringnotcontainsstring" in acceptance_low
                and "alpha tenant sentinel" in acceptance_low
                and "beta tenant sentinel" in acceptance_low
                and "$alphahtml" in acceptance_low
                and "$betahtml" in acceptance_low,
                f"authenticated-fallback route lacks authenticated identity non-disclosure proof: {operation_id}",
            )
            require(
                "database-password-secret" in acceptance_low
                and "foreign-secret" in acceptance_low
                and "assertdontsee" in acceptance_low,
                f"authenticated-fallback route lacks query/secret non-disclosure acceptance: {operation_id}",
            )
            signals = [
                "source:fallback-authenticated",
                "middleware:web",
                "middleware:auth",
                "middleware:no-tenant-context",
                "route:no-parameters",
                "guest:login-redirect",
                "identity:no-disclosure",
                "diagnostics:no-secret-reflection",
                f"test:{acceptance_path}",
            ]
            authenticated_fallback_applied.append(operation_id)

        row["migration_state"] = "ADAPTED"
        row["laravel_destination"] = destination_path
        row["acceptance_test"] = acceptance_path
        row["evidence"] = (
            f"Source-boundary route closure@{source_sha}: {destination_path}; "
            f"source-boundary-route:{operation_id}; mode:{mode}; action:{action_path}; "
            f"acceptance:{acceptance_path}; evidence:{evidence_path}"
        )
        row["reconciliation"] = {
            "decision": "ADAPTED",
            "reason": (
                "Exact pushed source-boundary route evidence proves source authorization semantics, "
                "explicit action wiring, zero route parameters, focused runtime acceptance, and "
                "fail-closed identity/diagnostic behavior."
            ),
            "source_label": "Source-boundary route closure",
            "source_sha": source_sha,
            "destination_path": destination_path,
            "evidence_mode": "explicit_route_contract",
            "security_mode": mode,
            "action_path": action_path,
            "evidence_path": evidence_path,
            "signals": [f"operation:{operation_id}", *signals],
        }
        applied.append(operation_id)

    finalize.finalize_summary(payload, payload["operations"], canonical_manifest)
    payload["classification_policy"]["tenant_neutral_route_policy"] = (
        "zero-parameter route rows outside tenant-selected routing may be terminalized only by an exact pushed "
        "source-boundary contract proving either explicit AllowAnonymous web-only semantics or application-wide "
        "authenticated fallback semantics preserved as web+auth without tenant.context, together with explicit "
        "action wiring and deterministic fail-closed acceptance"
    )

    validation = payload.setdefault("validation", {})
    errors = list(validation.get("errors") or [])
    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != len(payload["operations"]):
        errors.append("source-boundary route status totals do not reconcile")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        errors.append("source-boundary route processing counted BLOCKED or PENDING as terminal")

    existing_explicit = set(validation.get("explicit_route_contract_terminals") or [])
    existing_explicit.update(applied)
    validation["explicit_route_contract_terminals"] = sorted(existing_explicit)
    validation["source_boundary_route_contract_terminals"] = sorted(applied)
    validation["tenant_neutral_route_contract_terminals"] = sorted(
        operation_id for operation_id in applied if operation_id not in authenticated_fallback_applied
    )
    validation["authenticated_fallback_route_contract_terminals"] = sorted(authenticated_fallback_applied)
    validation["source_boundary_route_contract_count"] = len(applied)
    validation["tenant_neutral_route_source_sha"] = source_sha
    validation["status_totals_reconcile"] = state_total == len(payload["operations"])
    validation["terminal_excludes_blocked"] = totals["terminal"] == expected_terminal
    validation["errors"] = errors
    validation["passed"] = not errors
    if errors:
        raise SystemExit("source-boundary route validation failed:\n- " + "\n- ".join(errors))

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
    canonical_manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    neutral_manifest = json.loads(TENANT_NEUTRAL_MANIFEST.read_text(encoding="utf-8"))
    require(
        len(payload.get("operations", [])) == args.check_total,
        f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}",
    )

    applied = apply(payload, canonical_manifest, neutral_manifest)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    explicit = len(payload.get("validation", {}).get("explicit_route_contract_terminals", []))
    authenticated_fallback = len(payload.get("validation", {}).get("authenticated_fallback_route_contract_terminals", []))
    needle = f"- Explicit route contracts: **{explicit}**\n"
    markdown = markdown.replace(
        needle,
        needle + f"- Authenticated fallback route contracts: **{authenticated_fallback}**\n",
        1,
    )
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"SOURCE_BOUNDARY_ROUTES_APPLIED={len(applied)}")
    print(f"AUTHENTICATED_FALLBACK_ROUTES_APPLIED={authenticated_fallback}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("SOURCE_BOUNDARY_ROUTE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
