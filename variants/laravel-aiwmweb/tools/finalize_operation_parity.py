#!/usr/bin/env python3
"""Finalize and validate the canonical 931-operation Laravel AIWMWeb reconciliation."""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path

import reconcile_operation_parity as reconcile

TERMINAL_STATES = {"PORTED", "ADAPTED", "VERIFIED_UNAVAILABLE_EXTERNAL"}


def source_is_pushed(sha: str) -> bool:
    output = reconcile.run_git("branch", "-r", "--contains", sha, check=False)
    return any(line.strip() for line in output.splitlines())


def state_summary(rows: list[dict]) -> dict:
    states = Counter(str(row["migration_state"]) for row in rows)
    total = len(rows)
    terminal = sum(states[state] for state in TERMINAL_STATES)
    return {
        "total": total,
        "ported": states["PORTED"],
        "adapted": states["ADAPTED"],
        "pending": states["PENDING"],
        "blocked": states["BLOCKED"],
        "verified_unavailable_external": states["VERIFIED_UNAVAILABLE_EXTERNAL"],
        "terminal": terminal,
        "percent": round(terminal / total * 100, 2) if total else 0.0,
    }


def apply_explicit_route_evidence(rows: list[dict], manifest: dict, snapshots: list[reconcile.Snapshot]) -> None:
    """Terminalize only exact route IDs backed by a pushed, operation-linked contract.

    Generic route/visible-control presence is still never parity. This narrow path
    exists for closure workers that provide an explicit operation-ID inventory,
    guarded route implementation, backing action boundary, and acceptance test.
    """
    rows_by_id = {str(row.get("operation_id") or ""): row for row in rows}
    snapshots_by_sha = {snapshot.sha: snapshot for snapshot in snapshots}
    applied: set[str] = set()

    for source in manifest["countable_sources"]:
        evidence_map = source.get("operation_evidence") or {}
        if not evidence_map:
            continue

        source_sha = str(source["sha"])
        snapshot = snapshots_by_sha.get(source_sha)
        if snapshot is None:
            raise SystemExit(f"explicit route evidence source was not loaded: {source_sha}")

        files_by_path = {file.path: file for file in snapshot.files}
        source_domains = set(source.get("domains", []))

        for operation_id, evidence in evidence_map.items():
            if operation_id in applied:
                raise SystemExit(f"explicit route evidence is duplicated for operation: {operation_id}")

            row = rows_by_id.get(operation_id)
            if row is None:
                raise SystemExit(f"explicit route evidence references unknown operation: {operation_id}")
            if row.get("kind") != "route":
                raise SystemExit(f"explicit route evidence may only terminalize route rows: {operation_id}")
            if row.get("migration_state") != "PENDING":
                raise SystemExit(
                    f"explicit route evidence would double-count an already terminal operation: {operation_id}"
                )
            if str(row.get("domain")) not in source_domains:
                raise SystemExit(f"explicit route evidence source does not own operation domain: {operation_id}")

            destination_path = str(evidence.get("destination_path") or "")
            action_path = str(evidence.get("action_path") or "")
            acceptance_test = str(evidence.get("acceptance_test") or "")
            evidence_path = str(evidence.get("evidence_path") or "")
            required_paths = (destination_path, action_path, acceptance_test)
            if not all(path and path in files_by_path for path in required_paths):
                raise SystemExit(f"explicit route evidence is missing pushed code/test paths: {operation_id}")

            route_text = files_by_path[destination_path].text
            action_text = files_by_path[action_path].text
            test_text = files_by_path[acceptance_test].text
            evidence_text = reconcile.run_git("show", f"{source_sha}:{evidence_path}", check=False)
            route_screen = str(row.get("route_screen") or "")

            if operation_id not in test_text or operation_id not in evidence_text:
                raise SystemExit(f"explicit route evidence is not operation-linked in test/evidence: {operation_id}")
            if route_screen and route_screen not in route_text:
                raise SystemExit(f"explicit route destination does not contain canonical route path: {operation_id}")
            if Path(action_path).stem not in route_text:
                raise SystemExit(f"explicit route destination is not wired to the declared action: {operation_id}")
            if "tenant.context" not in route_text or "auth" not in route_text:
                raise SystemExit(f"explicit route destination lacks auth/tenant middleware evidence: {operation_id}")
            if "TenantAuthorizer" not in action_text or "authorize" not in action_text:
                raise SystemExit(f"explicit route action lacks authorization evidence: {operation_id}")

            row["migration_state"] = "ADAPTED"
            row["laravel_destination"] = destination_path
            row["acceptance_test"] = acceptance_test
            row["evidence"] = (
                f"{source['label']}@{source_sha}: {destination_path}; "
                f"explicit-route-contract:{operation_id}; action:{action_path}; evidence:{evidence_path}"
            )
            row["reconciliation"] = {
                "decision": "ADAPTED",
                "reason": (
                    "Exact pushed route contract is linked to the canonical operation ID, "
                    "auth+tenant authorization, a real backing action boundary, and acceptance evidence."
                ),
                "source_label": source["label"],
                "source_sha": source_sha,
                "destination_path": destination_path,
                "evidence_mode": "explicit_route_contract",
                "action_path": action_path,
                "evidence_path": evidence_path,
                "signals": [
                    f"operation:{operation_id}",
                    "middleware:auth",
                    "middleware:tenant.context",
                    "authorization:TenantAuthorizer",
                    f"test:{acceptance_test}",
                ],
            }
            applied.add(operation_id)

    expected = {
        operation_id
        for source in manifest["countable_sources"]
        for operation_id in (source.get("operation_evidence") or {})
    }
    if applied != expected:
        missing = sorted(expected - applied)
        raise SystemExit("explicit route evidence was not fully applied: " + ", ".join(missing))


def finalize_summary(payload: dict, rows: list[dict], manifest: dict) -> None:
    totals = state_summary(rows)
    payload["totals"] = {
        **{key: value for key, value in totals.items() if key != "percent"},
        "overall_parity_percent": totals["percent"],
    }
    payload["classification_policy"]["percentage_formula"] = (
        "(PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL) / TOTAL * 100"
    )
    payload["classification_policy"]["blocked_progress_policy"] = "not_counted"
    payload["classification_policy"]["operation_scoped_source_policy"] = (
        "a countable source declaring operation_ids may contribute generic evidence only to those canonical IDs; "
        "the normal code, tenant/security, and test thresholds still apply"
    )
    payload["classification_policy"]["explicit_route_policy"] = (
        "route rows remain pending unless an exact pushed source declares operation_evidence "
        "and the generator verifies route, auth/tenant action, test, and evidence links"
    )

    domains: dict[str, dict] = {}
    for domain in sorted({str(row["domain"]) for row in rows}):
        subset = [row for row in rows if str(row["domain"]) == domain]
        domains[domain] = state_summary(subset)
    payload["domains"] = domains

    kinds: dict[str, dict] = {}
    for kind in sorted({str(row["kind"]) for row in rows}):
        subset = [row for row in rows if str(row["kind"]) == kind]
        kinds[kind] = state_summary(subset)
    payload["kinds"] = kinds

    visible = [row for row in rows if row.get("kind") == "visible_control"]
    visible_summary = state_summary(visible)
    payload["visible_controls"] = {
        "total": visible_summary["total"],
        "terminal": visible_summary["terminal"],
        "pending": visible_summary["pending"],
        "blocked": visible_summary["blocked"],
        "percent": visible_summary["percent"],
    }

    pending_by_domain: dict[str, list[str]] = {}
    for domain in sorted(domains):
        pending_by_domain[domain] = sorted(
            str(row["operation_id"])
            for row in rows
            if str(row["domain"]) == domain and row["migration_state"] == "PENDING"
        )
    payload["pending_operation_ids_by_domain"] = pending_by_domain
    payload["source_manifest"] = {
        "schema_version": manifest.get("schema_version"),
        "path": str(reconcile.MANIFEST.relative_to(reconcile.ROOT)).replace("\\", "/"),
        "base_main_sha": manifest["base_main_sha"],
        "countable_sources": [
            {
                "label": source["label"],
                "sha": source["sha"],
                "domains": source.get("domains", []),
                "supporting_only": bool(source.get("supporting_only", False)),
                "operation_ids": sorted(str(operation_id) for operation_id in source.get("operation_ids", [])),
                "explicit_operation_evidence_ids": sorted((source.get("operation_evidence") or {}).keys()),
            }
            for source in manifest["countable_sources"]
        ],
    }


def validate(rows: list[dict], payload: dict, manifest: dict, snapshots: list[reconcile.Snapshot], expected: int) -> dict:
    errors: list[str] = []
    operation_ids = [str(row.get("operation_id") or "") for row in rows]
    duplicates = sorted(op for op, count in Counter(operation_ids).items() if op and count > 1)
    blank_ids = sum(1 for op in operation_ids if not op)
    invalid_states = sorted({str(row.get("migration_state")) for row in rows} - reconcile.ALLOWED)

    if len(rows) != expected:
        errors.append(f"denominator mismatch: expected {expected}, got {len(rows)}")
    if duplicates:
        errors.append(f"duplicate operation IDs: {', '.join(duplicates)}")
    if blank_ids:
        errors.append(f"blank operation IDs: {blank_ids}")
    if invalid_states:
        errors.append(f"invalid statuses: {', '.join(invalid_states)}")

    known_operation_ids = set(operation_ids)
    scoped_ids = [
        str(operation_id)
        for source in manifest["countable_sources"]
        for operation_id in source.get("operation_ids", [])
        if str(operation_id)
    ]
    unknown_scoped_ids = sorted(set(scoped_ids) - known_operation_ids)
    duplicate_scoped_ids = sorted(
        operation_id for operation_id, count in Counter(scoped_ids).items() if count > 1
    )
    if unknown_scoped_ids:
        errors.append("operation-scoped evidence references unknown IDs: " + ", ".join(unknown_scoped_ids))
    if duplicate_scoped_ids:
        errors.append("operation-scoped evidence duplicates ownership: " + ", ".join(duplicate_scoped_ids))

    source_presence: dict[str, bool] = {}
    for source in manifest["countable_sources"]:
        sha = str(source["sha"])
        source_presence[sha] = source_is_pushed(sha)
        if not source_presence[sha]:
            errors.append(f"countable source is not reachable from a pushed remote ref: {sha}")

    snapshots_by_sha = {snap.sha: snap for snap in snapshots}
    missing_evidence_refs: list[str] = []
    for row in rows:
        state = str(row["migration_state"])
        if state not in {"PORTED", "ADAPTED"}:
            continue
        op_id = str(row["operation_id"])
        rec = row.get("reconciliation") or {}
        source_sha = str(rec.get("source_sha") or "")
        destination = str(row.get("laravel_destination") or "")
        acceptance_test = str(row.get("acceptance_test") or "")
        snap = snapshots_by_sha.get(source_sha)
        paths = {file.path for file in snap.files} if snap else set()
        if not source_sha or snap is None or destination not in paths or acceptance_test not in paths:
            missing_evidence_refs.append(op_id)
    if missing_evidence_refs:
        errors.append(
            "terminal rows with missing exact-SHA destination/test references: "
            + ", ".join(missing_evidence_refs[:20])
        )

    explicit_route_terminals = [
        str(row["operation_id"])
        for row in rows
        if row.get("kind") == "route"
        and row["migration_state"] in TERMINAL_STATES
        and (row.get("reconciliation") or {}).get("evidence_mode") == "explicit_route_contract"
    ]
    placeholder_terminals = [
        str(row["operation_id"])
        for row in rows
        if row["migration_state"] in TERMINAL_STATES
        and (
            row.get("kind") == "visible_control"
            or (
                row.get("kind") == "route"
                and (row.get("reconciliation") or {}).get("evidence_mode") != "explicit_route_contract"
            )
        )
    ]
    if placeholder_terminals:
        errors.append(
            "route/visible-control rows terminalized without operation-linked closure path: "
            + ", ".join(placeholder_terminals[:20])
        )

    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != expected:
        errors.append(f"status totals do not reconcile: {state_total} != {expected}")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        errors.append("BLOCKED or PENDING is being counted as terminal progress")

    visible = payload["visible_controls"]
    if visible["terminal"] + visible["pending"] + visible["blocked"] != visible["total"]:
        errors.append("visible-control totals do not reconcile")

    unpushed = sorted(sha for sha, present in source_presence.items() if not present)
    validation = {
        "denominator_exact": len(rows) == expected,
        "operation_id_count": len(operation_ids),
        "duplicate_operation_ids": duplicates,
        "blank_operation_ids": blank_ids,
        "allowed_statuses_only": not invalid_states,
        "invalid_statuses": invalid_states,
        "operation_scoped_unknown_ids": unknown_scoped_ids,
        "operation_scoped_duplicate_ids": duplicate_scoped_ids,
        "status_totals_reconcile": state_total == expected,
        "terminal_excludes_blocked": totals["terminal"] == expected_terminal,
        "terminal_evidence_references_exist": not missing_evidence_refs,
        "missing_terminal_evidence_operation_ids": missing_evidence_refs,
        "pushed_source_shas_verified": sorted(sha for sha, present in source_presence.items() if present),
        "unpushed_sources_counted": unpushed,
        "explicit_route_contract_terminals": sorted(explicit_route_terminals),
        "route_or_visible_placeholder_terminals": placeholder_terminals,
        "frontend_placeholders_not_counted": not placeholder_terminals,
        "errors": errors,
        "passed": not errors,
    }
    payload["validation"] = validation
    if errors:
        raise SystemExit("parity validation failed:\n- " + "\n- ".join(errors))
    return validation


def render_markdown(payload: dict) -> str:
    totals = payload["totals"]
    visible = payload["visible_controls"]
    lines = [
        "# Laravel AIWMWeb 931-Operation Parity Reconciliation",
        "",
        "Authority: AIMWWeb Issue #257. Evidence-only reconciliation; no feature completion is inferred from file, class, route, or UI presence alone.",
        "",
        "## Exact totals",
        "",
        "| Metric | Value |",
        "|---|---:|",
        f"| TOTAL | {totals['total']} |",
        f"| PORTED | {totals['ported']} |",
        f"| ADAPTED | {totals['adapted']} |",
        f"| PENDING | {totals['pending']} |",
        f"| BLOCKED | {totals['blocked']} |",
        f"| VERIFIED_UNAVAILABLE_EXTERNAL | {totals['verified_unavailable_external']} |",
        f"| TERMINAL | {totals['terminal']} |",
        f"| OVERALL_PARITY_PERCENT | {totals['overall_parity_percent']:.2f}% |",
        "",
        "Terminal progress is strictly `PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL`; `BLOCKED` is not progress.",
        "",
        "## Visible controls",
        "",
        f"- Total: **{visible['total']}**",
        f"- Terminal: **{visible['terminal']}**",
        f"- Pending: **{visible['pending']}**",
        f"- Blocked: **{visible['blocked']}**",
        f"- Parity: **{visible['percent']:.2f}%**",
        "",
        "## By domain",
        "",
        "| Domain | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for domain, summary in payload["domains"].items():
        lines.append(
            f"| {domain} | {summary['total']} | {summary['ported']} | {summary['adapted']} | "
            f"{summary['pending']} | {summary['blocked']} | {summary['verified_unavailable_external']} | "
            f"{summary['terminal']} | {summary['percent']:.2f}% |"
        )

    lines += [
        "",
        "## By kind",
        "",
        "| Kind | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for kind, summary in payload["kinds"].items():
        lines.append(
            f"| {kind} | {summary['total']} | {summary['ported']} | {summary['adapted']} | "
            f"{summary['pending']} | {summary['blocked']} | {summary['verified_unavailable_external']} | "
            f"{summary['terminal']} | {summary['percent']:.2f}% |"
        )

    validation = payload["validation"]
    lines += [
        "",
        "## Reproducibility / guard results",
        "",
        f"- Denominator exactly 931: **{'PASS' if validation['denominator_exact'] else 'FAIL'}**",
        f"- Duplicate operation IDs: **{len(validation['duplicate_operation_ids'])}**",
        f"- Allowed statuses only: **{'PASS' if validation['allowed_statuses_only'] else 'FAIL'}**",
        f"- Totals reconcile: **{'PASS' if validation['status_totals_reconcile'] else 'FAIL'}**",
        f"- Evidence references exist for terminal code rows: **{'PASS' if validation['terminal_evidence_references_exist'] else 'FAIL'}**",
        f"- Explicit route contracts: **{len(validation['explicit_route_contract_terminals'])}**",
        f"- Unpushed countable sources: **{len(validation['unpushed_sources_counted'])}**",
        f"- Frontend placeholder terminals: **{len(validation['route_or_visible_placeholder_terminals'])}**",
        f"- BLOCKED excluded from progress: **{'PASS' if validation['terminal_excludes_blocked'] else 'FAIL'}**",
        "",
        "## Exact remaining PENDING operation IDs by domain",
    ]
    for domain, operation_ids in payload["pending_operation_ids_by_domain"].items():
        lines += ["", f"### {domain} ({len(operation_ids)})", ""]
        if operation_ids:
            lines.extend(f"- `{operation_id}`" for operation_id in operation_ids)
        else:
            lines.append("- None")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--summary-output", type=Path)
    parser.add_argument("--markdown-output", type=Path)
    parser.add_argument("--check-total", type=int, default=931)
    args = parser.parse_args()

    ledger = json.loads(reconcile.LEDGER.read_text(encoding="utf-8"))
    manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    rows = ledger.get("operations", [])
    if len(rows) != args.check_total:
        raise SystemExit(f"expected {args.check_total} canonical operations, found {len(rows)}")

    snapshots = [reconcile.load_snapshot(source) for source in manifest["countable_sources"]]
    exclusions = {source["label"]: source["reason"] for source in manifest.get("excluded_sources", [])}
    reconciled_rows = [reconcile.classify(row, snapshots, exclusions) for row in rows]
    apply_explicit_route_evidence(reconciled_rows, manifest, snapshots)
    payload = reconcile.summarize(reconciled_rows, manifest)
    finalize_summary(payload, reconciled_rows, manifest)
    validate(reconciled_rows, payload, manifest, snapshots, args.check_total)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.markdown_output:
        args.markdown_output.write_text(render_markdown(payload), encoding="utf-8")

    totals = payload["totals"]
    visible = payload["visible_controls"]
    print(f"TOTAL={totals['total']}")
    print(f"PORTED={totals['ported']}")
    print(f"ADAPTED={totals['adapted']}")
    print(f"PENDING={totals['pending']}")
    print(f"BLOCKED={totals['blocked']}")
    print(f"VERIFIED_UNAVAILABLE_EXTERNAL={totals['verified_unavailable_external']}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print(f"VISIBLE_CONTROLS_TOTAL={visible['total']}")
    print(f"VISIBLE_CONTROLS_TERMINAL={visible['terminal']}")
    print(f"VISIBLE_CONTROLS_PENDING={visible['pending']}")
    print("VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
