#!/usr/bin/env python3
"""Apply exact-SHA focused visible-control closure evidence to parity output.

The base reconciliation intentionally refuses to infer visible-control parity from
frontend presence. This verifier adds credit only when an already-pushed closure
composition contains all of the following for one canonical operation:

- an explicit terminal closure-evidence JSON document;
- the exact canonical operation ID in production code;
- the exact canonical operation ID in focused test code;
- tenant/security assertions when the canonical row requires them.

Anything that cannot satisfy the contract remains PENDING.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import finalize_operation_parity as finalize
import reconcile_operation_parity as reconcile

TERMINAL_STATES = finalize.TERMINAL_STATES
EVIDENCE_ROOT = "variants/laravel-aiwmweb/docs/closure-evidence/"


def nested(document: dict[str, Any], *path: str) -> Any:
    value: Any = document
    for part in path:
        if not isinstance(value, dict):
            return None
        value = value.get(part)
    return value


def unique_string(values: list[Any]) -> str | None:
    strings = {str(value).strip() for value in values if isinstance(value, str) and value.strip()}
    if len(strings) == 1:
        return next(iter(strings))
    return None


def operation_id(document: dict[str, Any]) -> str | None:
    return unique_string([
        document.get("operation_id"),
        nested(document, "canonical_operation", "operation_id"),
        nested(document, "operation", "operation_id"),
    ])


def terminal_state(document: dict[str, Any]) -> str | None:
    return unique_string([
        document.get("terminal_state"),
        nested(document, "canonical_operation", "terminal_state"),
        nested(document, "operation", "terminal_state"),
        nested(document, "terminality", "state"),
    ])


def evidence_domain(document: dict[str, Any]) -> str | None:
    return unique_string([
        document.get("domain"),
        nested(document, "canonical_operation", "domain"),
        nested(document, "operation", "domain"),
    ])


def evidence_kind(document: dict[str, Any]) -> str | None:
    return unique_string([
        document.get("kind"),
        nested(document, "canonical_operation", "kind"),
        nested(document, "operation", "kind"),
    ])


def evidence_paths(source_sha: str) -> list[str]:
    paths = reconcile.run_git(
        "ls-tree", "-r", "--name-only", source_sha, "--", EVIDENCE_ROOT
    ).splitlines()
    return sorted(path for path in paths if path.startswith(EVIDENCE_ROOT) and path.endswith(".json"))


def load_evidence(source_sha: str, path: str) -> dict[str, Any] | None:
    raw = reconcile.run_git("show", f"{source_sha}:{path}", check=False)
    if not raw.strip():
        return None
    try:
        value = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise SystemExit(f"focused closure evidence is invalid JSON: {path}: {exc}") from exc
    return value if isinstance(value, dict) else None


def security_contract(row: dict[str, Any], tests: list[reconcile.FileEvidence]) -> tuple[bool, list[str]]:
    text = "\n".join(test.text for test in tests).lower()
    signals: list[str] = []

    if bool(row.get("tenant_owned")):
        tenant_proof = (
            ("assertnotfound" in text or "404" in text)
            and any(token in text for token in ("tenant", "foreign", "cross-tenant", "cross_tenant"))
        )
        if not tenant_proof:
            return False, ["missing focused tenant-isolation assertion"]
        signals.append("test:tenant-isolation")

    risk = str(row.get("risk") or "").lower()
    if bool(row.get("mutation")) or risk in {"high", "critical"}:
        auth_proof = "assertforbidden" in text or "403" in text
        if not auth_proof:
            return False, ["missing focused authorization assertion"]
        signals.append("test:authorization")

    return True, signals


def configured_sources(manifest: dict[str, Any]) -> list[dict[str, Any]]:
    primary_sha = str(manifest.get("focused_closure_evidence_source_sha") or "").strip()
    if not primary_sha:
        raise SystemExit("manifest must declare focused_closure_evidence_source_sha")

    sources: list[dict[str, Any]] = [{
        "label": "Live focused closure composition",
        "sha": primary_sha,
        "operation_ids": None,
    }]
    seen_shas = {primary_sha}

    for item in manifest.get("supplemental_focused_closure_evidence_sources", []):
        if not isinstance(item, dict):
            raise SystemExit("supplemental focused closure source must be an object")

        source_sha = str(item.get("sha") or "").strip()
        label = str(item.get("label") or "").strip() or "Supplemental focused closure composition"
        raw_operation_ids = item.get("operation_ids")
        if not source_sha:
            raise SystemExit("supplemental focused closure source must declare sha")
        if source_sha in seen_shas:
            raise SystemExit(f"focused closure source SHA is duplicated: {source_sha}")
        if not isinstance(raw_operation_ids, list) or not raw_operation_ids:
            raise SystemExit(
                f"supplemental focused closure source must declare non-empty operation_ids: {source_sha}"
            )

        operation_ids = {
            str(operation_id).strip()
            for operation_id in raw_operation_ids
            if str(operation_id).strip()
        }
        if len(operation_ids) != len(raw_operation_ids):
            raise SystemExit(
                f"supplemental focused closure source has blank/duplicate operation_ids: {source_sha}"
            )

        sources.append({
            "label": label,
            "sha": source_sha,
            "operation_ids": operation_ids,
        })
        seen_shas.add(source_sha)

    return sources


def apply(payload: dict[str, Any], manifest: dict[str, Any]) -> list[str]:
    rows_by_id = {str(row.get("operation_id") or ""): row for row in payload.get("operations", [])}
    sources = configured_sources(manifest)

    evidence_by_operation: dict[
        str,
        tuple[str, dict[str, Any], str, str, reconcile.Snapshot],
    ] = {}

    for source_config in sources:
        source_sha = str(source_config["sha"])
        source_label = str(source_config["label"])
        allowed_operation_ids = source_config["operation_ids"]

        if not finalize.source_is_pushed(source_sha):
            raise SystemExit(
                f"focused closure evidence source is not reachable from a pushed remote ref: {source_sha}"
            )

        source = {
            "label": source_label,
            "sha": source_sha,
            "domains": sorted({str(row.get("domain")) for row in payload.get("operations", [])}),
        }
        snapshot = reconcile.load_snapshot(source)

        for path in evidence_paths(source_sha):
            document = load_evidence(source_sha, path)
            if not document:
                continue
            op_id = operation_id(document)
            state = terminal_state(document)
            if not op_id or state not in TERMINAL_STATES:
                continue
            if allowed_operation_ids is not None and op_id not in allowed_operation_ids:
                continue

            row = rows_by_id.get(op_id)
            if row is None or row.get("kind") != "visible_control":
                continue
            if op_id in evidence_by_operation:
                first_path, _, first_label, first_sha, _ = evidence_by_operation[op_id]
                raise SystemExit(
                    "focused visible-control evidence is duplicated for "
                    f"{op_id}: {first_label}@{first_sha}:{first_path}, "
                    f"{source_label}@{source_sha}:{path}"
                )
            evidence_by_operation[op_id] = (
                path,
                document,
                source_label,
                source_sha,
                snapshot,
            )

        if allowed_operation_ids is not None:
            unknown_ids = sorted(allowed_operation_ids - set(rows_by_id))
            if unknown_ids:
                raise SystemExit(
                    "supplemental focused closure source references unknown operations: "
                    + ", ".join(unknown_ids)
                )

    applied: list[str] = []
    for op_id in sorted(evidence_by_operation):
        row = rows_by_id[op_id]
        if row.get("migration_state") != "PENDING":
            continue

        path, document, source_label, source_sha, snapshot = evidence_by_operation[op_id]
        state = terminal_state(document)
        domain = evidence_domain(document)
        kind = evidence_kind(document)
        if domain and domain != str(row.get("domain")):
            raise SystemExit(
                f"focused closure evidence domain mismatch for {op_id}: "
                f"{domain} != {row.get('domain')}"
            )
        if kind and kind != "visible_control":
            raise SystemExit(f"focused closure evidence kind mismatch for {op_id}: {kind}")
        if state not in TERMINAL_STATES:
            continue

        markers = sorted(
            (file for file in snapshot.code if op_id in file.text),
            key=lambda file: file.path,
        )
        tests = sorted(
            (file for file in snapshot.tests if op_id in file.text),
            key=lambda file: file.path,
        )
        if not markers or not tests:
            continue

        security_ok, security_signals = security_contract(row, tests)
        if not security_ok:
            continue

        marker = markers[0]
        test = tests[0]
        row["migration_state"] = state
        row["laravel_destination"] = marker.path
        row["acceptance_test"] = test.path
        row["evidence"] = (
            f"{source_label}@{source_sha}: {marker.path}; "
            f"focused-closure:{op_id}; test:{test.path}; evidence:{path}"
        )
        row["reconciliation"] = {
            "decision": state,
            "reason": (
                "Exact pushed focused closure evidence is linked to the canonical operation ID "
                "in production code and focused acceptance tests; tenant/security assertions are "
                "required when applicable."
            ),
            "source_label": source_label,
            "source_sha": source_sha,
            "destination_path": marker.path,
            "evidence_mode": "focused_closure_contract",
            "evidence_path": path,
            "signals": [
                f"operation:{op_id}",
                f"production-marker:{marker.path}",
                f"test:{test.path}",
                f"evidence:{path}",
                *security_signals,
            ],
        }
        applied.append(op_id)

    finalize.finalize_summary(payload, payload["operations"], manifest)
    payload["classification_policy"]["focused_visible_control_policy"] = (
        "visible-control rows remain PENDING unless an exact pushed closure evidence file, "
        "production operation-ID marker, focused operation-ID test, and applicable tenant/security "
        "assertions are all present; supplemental sources are exact-SHA and operation-ID scoped"
    )

    validation = payload.setdefault("validation", {})
    errors = list(validation.get("errors") or [])
    placeholder_terminals = [
        str(row["operation_id"])
        for row in payload["operations"]
        if row.get("kind") in {"route", "visible_control"}
        and row.get("migration_state") in TERMINAL_STATES
        and (row.get("reconciliation") or {}).get("evidence_mode")
        not in {"explicit_route_contract", "explicit_route_api_contract", "focused_closure_contract"}
    ]
    if placeholder_terminals:
        errors.append(
            "route/visible-control rows terminalized without an explicit verified closure contract: "
            + ", ".join(placeholder_terminals[:20])
        )

    totals = payload["totals"]
    state_total = sum(
        int(totals[key])
        for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external")
    )
    if state_total != len(payload["operations"]):
        errors.append("focused closure status totals do not reconcile")
    expected_terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"]
    if totals["terminal"] != expected_terminal:
        errors.append("focused closure processing counted BLOCKED or PENDING as terminal")

    validation["focused_closure_source_sha"] = str(
        manifest.get("focused_closure_evidence_source_sha") or ""
    ).strip()
    validation["supplemental_focused_closure_sources"] = [
        {
            "label": str(source["label"]),
            "sha": str(source["sha"]),
            "operation_ids": (
                sorted(source["operation_ids"])
                if source["operation_ids"] is not None
                else None
            ),
        }
        for source in sources[1:]
    ]
    validation["focused_closure_contract_terminals"] = applied
    validation["focused_closure_contract_count"] = len(applied)
    validation["route_or_visible_placeholder_terminals"] = placeholder_terminals
    validation["frontend_placeholders_not_counted"] = not placeholder_terminals
    validation["status_totals_reconcile"] = state_total == len(payload["operations"])
    validation["terminal_excludes_blocked"] = totals["terminal"] == expected_terminal
    validation["errors"] = errors
    validation["passed"] = not errors
    if errors:
        raise SystemExit("focused closure validation failed:\n- " + "\n- ".join(errors))

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
    manifest = json.loads(reconcile.MANIFEST.read_text(encoding="utf-8"))
    if len(payload.get("operations", [])) != args.check_total:
        raise SystemExit(
            f"expected {args.check_total} canonical operations, found {len(payload.get('operations', []))}"
        )

    applied = apply(payload, manifest)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {key: value for key, value in payload.items() if key != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    markdown = finalize.render_markdown(payload)
    explicit = len(payload.get("validation", {}).get("explicit_route_contract_terminals", []))
    focused = len(applied)
    needle = f"- Explicit route contracts: **{explicit}**\n"
    markdown = markdown.replace(
        needle,
        needle + f"- Focused visible-control contracts: **{focused}**\n",
        1,
    )
    args.markdown_output.write_text(markdown, encoding="utf-8")

    totals = payload["totals"]
    print(f"FOCUSED_VISIBLE_CONTROLS_APPLIED={len(applied)}")
    print(f"TERMINAL={totals['terminal']}")
    print(f"PENDING={totals['pending']}")
    print(f"PARITY_PERCENT={totals['overall_parity_percent']:.2f}")
    print("FOCUSED_CLOSURE_VALIDATION=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
