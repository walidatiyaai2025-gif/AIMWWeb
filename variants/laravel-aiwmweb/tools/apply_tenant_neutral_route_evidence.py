#!/usr/bin/env python3
"""Apply strict evidence for explicitly tenant-neutral anonymous canonical routes.

This verifier is intentionally narrow. It exists for canonical source routes that
are explicitly anonymous (for example `[AllowAnonymous]`) and therefore must not
be forced through the normal auth + tenant.context route contract merely to earn
parity credit.

A route is terminalized only when an exact pushed snapshot proves all of:
- canonical source explicitly declares anonymous access;
- a real explicit Laravel route is wired to the declared action;
- runtime acceptance proves web-only middleware, no auth/tenant.context, and no
  route parameters;
- operation-linked evidence and tests exist;
- tenant A, tenant B, and anonymous callers receive identity-neutral content with
  explicit no-disclosure assertions.
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
    require(bool(source_sha), "tenant-neutral route evidence source SHA is missing")
    require(
        finalize.source_is_pushed(source_sha),
        f"tenant-neutral route evidence source is not reachable from a pushed remote ref: {source_sha}",
    )

    rows_by_id = {
        str(row.get("operation_id") or ""): row
        for row in payload.get("operations", [])
    }
    applied: list[str] = []

    for operation_id, evidence in evidence_map.items():
        row = rows_by_id.get(operation_id)
        require(row is not None, f"tenant-neutral route evidence references unknown operation: {operation_id}")
        require(row.get("kind") == "route", f"tenant-neutral evidence requires route kind: {operation_id}")
        require(
            row.get("migration_state") == "PENDING",
            f"tenant-neutral route evidence would double-count terminal operation: {operation_id}",
        )
        require(bool(row.get("tenant_owned")), f"tenant-neutral route contract expects tenant-owned metadata: {operation_id}")
        require(not bool(row.get("mutation")), f"tenant-neutral route contract cannot terminalize mutation: {operation_id}")

        destination_path = str(evidence.get("destination_path") or "")
        action_path = str(evidence.get("action_path") or "")
        acceptance_path = str(evidence.get("acceptance_test") or "")
        evidence_path = str(evidence.get("evidence_path") or "")
        for path in (destination_path, action_path, acceptance_path, evidence_path):
            require(bool(path), f"tenant-neutral route evidence is missing required path: {operation_id}")

        destination = git_show(source_sha, destination_path)
        action = git_show(source_sha, action_path)
        acceptance = git_show(source_sha, acceptance_path)
        closure_evidence = git_show(source_sha, evidence_path)
        canonical_source_path = str(row.get("current_source") or "")
        canonical_source = git_show(source_sha, canonical_source_path)
        require(
            all((destination, action, acceptance, closure_evidence, canonical_source)),
            f"tenant-neutral route exact-SHA files are incomplete: {operation_id}",
        )
        require(
            operation_id in acceptance and operation_id in closure_evidence,
            f"tenant-neutral route evidence is not operation-linked in test/evidence: {operation_id}",
        )

        route_screen = str(row.get("route_screen") or "")
        action_stem = Path(action_path).stem
        require(route_screen and route_screen in destination, f"tenant-neutral route path is not explicit: {operation_id}")
        require(action_stem in destination, f"tenant-neutral route is not wired to declared action: {operation_id}")
        require("AllowAnonymous" in canonical_source, f"canonical source is not explicitly anonymous: {operation_id}")
        require(route_screen in canonical_source, f"canonical anonymous source route mismatch: {operation_id}")

        acceptance_low = acceptance.lower()
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

        action_low = action.lower()
        require(
            "tenantcontext" not in action_low
            and "tenantauthorizer" not in action_low
            and "request()->user" not in action_low,
            f"tenant-neutral route action unexpectedly resolves tenant/user authority: {operation_id}",
        )

        row["migration_state"] = "ADAPTED"
        row["laravel_destination"] = destination_path
        row["acceptance_test"] = acceptance_path
        row["evidence"] = (
            f"Tenant-neutral route closure@{source_sha}: {destination_path}; "
            f"tenant-neutral-route:{operation_id}; action:{action_path}; "
            f"acceptance:{acceptance_path}; evidence:{evidence_path}"
        )
        row["reconciliation"] = {
            "decision": "ADAPTED",
            "reason": (
                "Exact pushed tenant-neutral route evidence preserves the canonical anonymous boundary, "
                "proves explicit controller wiring, zero route parameters, web-only middleware, and "
                "identity-neutral behavior across anonymous and multiple tenant callers."
            ),
            "source_label": "Tenant-neutral route closure",
            "source_sha": source_sha,
            "destination_path": destination_path,
            "evidence_mode": "explicit_route_contract",
            "security_mode": "tenant_neutral",
            "action_path": action_path,
            "evidence_path": evidence_path,
            "signals": [
                f"operation:{operation_id}",
                "source:AllowAnonymous",
                "middleware:web-only",
                "tenant:neutral",
                "route:no-parameters",
                "identity:no-disclosure",
                f"test:{acceptance_path}",
            ],
        }
        applied.append(operation_id)

    finalize.finalize_summary(payload, payload["operations"], canonical_manifest)
    payload["classification_policy"]["tenant_neutral_route_policy"] = (
        "explicitly anonymous canonical route rows may be terminalized only by an exact pushed "
        "tenant-neutral contract proving AllowAnonymous source semantics, explicit action wiring, "
        "web-only/no-auth/no-tenant-context runtime middleware, zero route parameters, and "
        "identity-neutral multi-caller acceptance"
    )

    validation = payload.setdefault("validation", {})
    errors = list(validation.get("errors") or [])
    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != len(payload["operations"]):
        errors.append("tenant-neutral route status totals do not reconcile")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        errors.append("tenant-neutral route processing counted BLOCKED or PENDING as terminal")

    existing_explicit = set(validation.get("explicit_route_contract_terminals") or [])
    existing_explicit.update(applied)
    validation["explicit_route_contract_terminals"] = sorted(existing_explicit)
    validation["tenant_neutral_route_contract_terminals"] = sorted(applied)
    validation["tenant_neutral_route_contract_count"] = len(applied)
    validation["tenant_neutral_route_source_sha"] = source_sha
    validation["status_totals_reconcile"] = state_total == len(payload["operations"])
    validation["terminal_excludes_blocked"] = totals["terminal"] == expected_terminal
    validation["errors"] = errors
    validation["passed"] = not errors
    if errors:
        raise SystemExit("tenant-neutral route validation failed:\n- " + "\n- ".join(errors))

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
    needle = f"- Explicit route contracts: **{explicit}**\n"
    markdown = markdown.replace(
        needle,
        needle + f"- Tenant-neutral route contracts: **{len(applied)}**\n",
        1,
    )
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"TENANT_NEUTRAL_ROUTES_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("TENANT_NEUTRAL_ROUTE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
