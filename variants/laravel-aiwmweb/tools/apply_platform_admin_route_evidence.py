#!/usr/bin/env python3
"""Apply exact-SHA evidence for zero-parameter platform-administrator routes.

This verifier is intentionally narrow. It exists for canonical route rows whose
scanner metadata is tenant-owned while the source route itself is a global
administrative entry point protected by a non-delegable source authorization
policy. It never invents a tenant selector merely to satisfy a generic route
shape.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

PLATFORM_ADMIN_MANIFEST = reconcile.VARIANT / "docs" / "platform-admin-route-evidence.json"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


def git_show(sha: str, path: str) -> str:
    return reconcile.run_git("show", f"{sha}:{path}", check=False)


def apply(
    payload: dict[str, Any],
    canonical_manifest: dict[str, Any],
    platform_manifest: dict[str, Any],
) -> list[str]:
    source_sha = str(platform_manifest.get("source_sha") or "").strip()
    evidence_map = platform_manifest.get("routes") or {}
    if not evidence_map:
        return []

    require(bool(source_sha), "platform-admin route evidence source SHA is missing")
    require(
        finalize.source_is_pushed(source_sha),
        f"platform-admin route evidence source is not reachable from a pushed remote ref: {source_sha}",
    )

    rows_by_id = {
        str(row.get("operation_id") or ""): row
        for row in payload.get("operations", [])
    }
    applied: list[str] = []

    for operation_id, evidence in evidence_map.items():
        row = rows_by_id.get(operation_id)
        require(row is not None, f"platform-admin route evidence references unknown operation: {operation_id}")
        require(row.get("kind") == "route", f"platform-admin evidence requires route kind: {operation_id}")
        require(
            row.get("migration_state") == "PENDING",
            f"platform-admin route evidence would double-count terminal operation: {operation_id}",
        )
        require(bool(row.get("tenant_owned")), f"platform-admin route contract expects tenant-owned canonical metadata: {operation_id}")
        require(not bool(row.get("mutation")), f"platform-admin route contract cannot terminalize a mutation: {operation_id}")

        destination_path = str(evidence.get("destination_path") or "")
        action_path = str(evidence.get("action_path") or "")
        acceptance_path = str(evidence.get("acceptance_test") or "")
        evidence_path = str(evidence.get("evidence_path") or "")
        source_auth_path = str(evidence.get("source_auth_path") or "")
        for path in (destination_path, action_path, acceptance_path, evidence_path, source_auth_path):
            require(bool(path), f"platform-admin route evidence is missing required path: {operation_id}")

        destination = git_show(source_sha, destination_path)
        action = git_show(source_sha, action_path)
        acceptance = git_show(source_sha, acceptance_path)
        closure_raw = git_show(source_sha, evidence_path)
        canonical_source_path = str(row.get("current_source") or "")
        canonical_source = git_show(source_sha, canonical_source_path)
        source_auth = git_show(source_sha, source_auth_path)
        require(
            all((destination, action, acceptance, closure_raw, canonical_source, source_auth)),
            f"platform-admin route exact-SHA files are incomplete: {operation_id}",
        )

        try:
            closure = json.loads(closure_raw)
        except json.JSONDecodeError as exc:
            raise SystemExit(f"platform-admin closure evidence is invalid JSON: {operation_id}: {exc}") from exc

        canonical = closure.get("canonical_operation") or {}
        scope_guards = closure.get("scope_guards") or {}
        terminality = closure.get("terminality") or {}
        require(canonical.get("operation_id") == operation_id, f"platform-admin closure evidence operation mismatch: {operation_id}")
        require(canonical.get("terminal_state") == "ADAPTED", f"platform-admin closure evidence is not terminal ADAPTED: {operation_id}")
        require(terminality.get("state") == "ADAPTED", f"platform-admin terminality state mismatch: {operation_id}")
        require(scope_guards.get("only_operation_claimed") == operation_id, f"platform-admin closure does not prove exact-one scope: {operation_id}")
        require(scope_guards.get("second_operation_started") is False, f"platform-admin closure reports a second operation: {operation_id}")

        route_screen = str(row.get("route_screen") or "")
        action_stem = Path(action_path).stem
        require(route_screen and route_screen in canonical_source, f"canonical source route mismatch: {operation_id}")
        require("ApplicationPermissionCatalog.SettingsManage" in canonical_source, f"canonical source lacks Settings.Manage policy: {operation_id}")
        require('public const string SettingsManage = "Settings.Manage";' in source_auth, f"source authorization catalog lacks Settings.Manage definition: {operation_id}")
        require('new(SettingsManage, "Manage security-sensitive settings"' in source_auth, f"source authorization catalog lacks security-sensitive Settings.Manage definition: {operation_id}")
        require('!string.Equals(permission, SettingsManage, StringComparison.Ordinal)' in source_auth, f"source Settings.Manage is not proven non-delegable to custom roles: {operation_id}")
        require('string.Equals(role, "Administrator", StringComparison.OrdinalIgnoreCase)' in source_auth and "return AllPermissions;" in source_auth, f"source administrator mapping is not proven: {operation_id}")

        destination_low = destination.lower()
        action_low = action.lower()
        acceptance_low = acceptance.lower()
        require(route_screen in destination, f"platform-admin Laravel destination lacks canonical route: {operation_id}")
        require(action_stem.lower() in destination_low, f"platform-admin route is not wired to declared action: {operation_id}")
        require("web" in destination_low and "auth" in destination_low and "platform.admin" in destination_low, f"platform-admin Laravel route lacks web+auth+platform.admin: {operation_id}")
        require("tenant.context" not in destination_low, f"platform-admin source-equivalent route must not invent tenant.context: {operation_id}")
        require("tenantauthorizer" not in action_low and "tenantcontext" not in action_low, f"platform-admin route action unexpectedly resolves tenant authority: {operation_id}")

        require("assertcontains('web'" in acceptance_low, f"platform-admin route lacks web middleware acceptance: {operation_id}")
        require("assertcontains('auth'" in acceptance_low, f"platform-admin route lacks auth middleware acceptance: {operation_id}")
        require("assertcontains('platform.admin'" in acceptance_low, f"platform-admin route lacks platform-admin middleware acceptance: {operation_id}")
        require("assertnotcontains('tenant.context'" in acceptance_low, f"platform-admin route lacks explicit tenant.context absence proof: {operation_id}")
        require("parameternames" in acceptance_low and "assertsame([]," in acceptance_low, f"platform-admin route lacks zero-parameter acceptance: {operation_id}")
        require("assertredirect('/login')" in acceptance_low, f"platform-admin route lacks guest fail-closed acceptance: {operation_id}")
        require("assertforbidden" in acceptance_low and "'platform_admin' => false" in acceptance_low, f"platform-admin route lacks non-admin 403 acceptance: {operation_id}")
        require("'platform_admin' => true" in acceptance_low and "assertok" in acceptance_low, f"platform-admin route lacks authorized administrator acceptance: {operation_id}")
        for resource in ("tenant", "account", "subscription", "provider", "user"):
            require(
                f"assertdontsee('name=\\\"{resource}" in acceptance_low,
                f"platform-admin route lacks {resource} resource-input non-disclosure acceptance: {operation_id}",
            )

        row["migration_state"] = "ADAPTED"
        row["laravel_destination"] = destination_path
        row["acceptance_test"] = acceptance_path
        row["evidence"] = (
            f"Platform-admin route closure@{source_sha}: {destination_path}; "
            f"platform-admin-route:{operation_id}; action:{action_path}; "
            f"acceptance:{acceptance_path}; evidence:{evidence_path}"
        )
        row["reconciliation"] = {
            "decision": "ADAPTED",
            "reason": (
                "Exact pushed evidence proves the source Settings.Manage administrator-only policy, "
                "Laravel web+auth+platform.admin enforcement without an invented tenant selector, "
                "zero route parameters, read-only action wiring, and deterministic fail-closed acceptance."
            ),
            "source_label": "Platform-admin route closure",
            "source_sha": source_sha,
            "destination_path": destination_path,
            "evidence_mode": "explicit_route_contract",
            "security_mode": "platform_admin_policy",
            "action_path": action_path,
            "evidence_path": evidence_path,
            "signals": [
                f"operation:{operation_id}",
                "source-policy:Settings.Manage",
                "source-policy:administrator-only",
                "middleware:web",
                "middleware:auth",
                "middleware:platform.admin",
                "middleware:no-tenant-context",
                "route:no-parameters",
                "resource-input:none",
                "guest:login-redirect",
                "non-admin:403",
                "platform-admin:200",
                f"test:{acceptance_path}",
            ],
        }
        applied.append(operation_id)

    finalize.finalize_summary(payload, payload["operations"], canonical_manifest)
    payload["classification_policy"]["platform_admin_route_policy"] = (
        "zero-parameter tenant-owned scanner rows may use platform-admin route evidence only when the canonical "
        "source explicitly requires non-delegable Settings.Manage authorization and exact pushed Laravel evidence "
        "preserves it as web+auth+platform.admin without tenant.context or caller-selected resource identifiers"
    )

    validation = payload.setdefault("validation", {})
    errors = list(validation.get("errors") or [])
    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != len(payload["operations"]):
        errors.append("platform-admin route status totals do not reconcile")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        errors.append("platform-admin route processing counted BLOCKED or PENDING as terminal")

    existing_explicit = set(validation.get("explicit_route_contract_terminals") or [])
    existing_explicit.update(applied)
    validation["explicit_route_contract_terminals"] = sorted(existing_explicit)
    validation["platform_admin_route_contract_terminals"] = sorted(applied)
    validation["platform_admin_route_contract_count"] = len(applied)
    validation["platform_admin_route_source_sha"] = source_sha
    validation["status_totals_reconcile"] = state_total == len(payload["operations"])
    validation["terminal_excludes_blocked"] = totals["terminal"] == expected_terminal
    validation["errors"] = errors
    validation["passed"] = not errors
    if errors:
        raise SystemExit("platform-admin route validation failed:\n- " + "\n- ".join(errors))

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
    platform_manifest = json.loads(PLATFORM_ADMIN_MANIFEST.read_text(encoding="utf-8"))
    require(
        len(payload.get("operations", [])) == args.check_total,
        f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}",
    )

    applied = apply(payload, canonical_manifest, platform_manifest)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    args.markdown_output.write_text(finalize.render_markdown(payload), encoding="utf-8")
    totals = payload["totals"]
    print(f"PLATFORM_ADMIN_ROUTES_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("PLATFORM_ADMIN_ROUTE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
