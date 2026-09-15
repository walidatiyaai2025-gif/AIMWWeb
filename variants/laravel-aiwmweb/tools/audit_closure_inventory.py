#!/usr/bin/env python3
"""Non-authoritative closure inventory audit for Issue #257.

This tool NEVER changes migration_state and NEVER regenerates canonical parity.
It only classifies the existing 931 canonical rows by evidence discoverability on
the checked-out exact SHA so integration can distinguish implementation debt from
evidence/provenance debt before the final canonical reconciliation is run.
"""
from __future__ import annotations

import json
import re
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[3]
VARIANT = ROOT / "variants/laravel-aiwmweb"
RECON = VARIANT / "docs/operation-parity-reconciliation.json"
EVIDENCE = VARIANT / "docs/closure-evidence"
OUT = VARIANT / "docs/closure-evidence-audit.json"

OP_RE = re.compile(r"AIMW-[A-Z]+-[0-9A-F]{10}")
TERMINAL = {"PORTED", "ADAPTED", "VERIFIED_UNAVAILABLE_EXTERNAL"}
TEXT_EXT = {".php", ".ts", ".tsx", ".js", ".mjs", ".py", ".sh", ".json", ".md"}


def walk_strings(value: Any):
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for item in value.values():
            yield from walk_strings(item)
    elif isinstance(value, list):
        for item in value:
            yield from walk_strings(item)


def op_ids(value: Any) -> set[str]:
    found: set[str] = set()
    for text in walk_strings(value):
        found.update(OP_RE.findall(text))
    return found


def explicit_terminal_state(document: dict[str, Any]) -> str | None:
    candidates: list[Any] = [
        document.get("terminal_state"),
        document.get("migration_state"),
        (document.get("terminality") or {}).get("state") if isinstance(document.get("terminality"), dict) else None,
    ]
    for parent in ("canonical_operation", "operation"):
        node = document.get(parent)
        if isinstance(node, dict):
            candidates.extend([node.get("terminal_state"), node.get("migration_state"), node.get("state")])
    states = {str(v).strip().upper() for v in candidates if isinstance(v, str) and str(v).strip()}
    states &= TERMINAL
    return next(iter(states)) if len(states) == 1 else None


def read_text_files():
    production: dict[str, str] = {}
    tests: dict[str, str] = {}
    routes: dict[str, str] = {}
    for path in VARIANT.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(ROOT).as_posix()
        if any(part in {"vendor", "node_modules", "storage"} for part in path.parts):
            continue
        if path.suffix.lower() not in TEXT_EXT and not path.name.endswith(".blade.php"):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        is_test = "/tests/" in f"/{rel}" or "/__tests__/" in f"/{rel}"
        is_doc = "/docs/" in f"/{rel}"
        if is_test:
            tests[rel] = text
        elif not is_doc:
            production[rel] = text
        if "/routes/" in f"/{rel}":
            routes[rel] = text
    return production, tests, routes


def security_signals(row: dict[str, Any], test_paths: list[str], tests: dict[str, str]) -> dict[str, bool]:
    text = "\n".join(tests[path] for path in test_paths if path in tests).lower()
    tenant_required = bool(row.get("tenant_owned"))
    auth_required = bool(row.get("mutation")) or str(row.get("risk") or "").lower() in {"high", "critical"}
    tenant_ok = (not tenant_required) or (
        ("assertnotfound" in text or "404" in text)
        and any(token in text for token in ("tenant", "foreign", "cross-tenant", "cross_tenant"))
    )
    auth_ok = (not auth_required) or ("assertforbidden" in text or "403" in text)
    return {
        "tenant_required": tenant_required,
        "tenant_ok": tenant_ok,
        "authorization_required": auth_required,
        "authorization_ok": auth_ok,
    }


def main() -> int:
    payload = json.loads(RECON.read_text(encoding="utf-8"))
    rows = payload.get("operations") or []
    if len(rows) != 931:
        raise SystemExit(f"expected 931 canonical rows, found {len(rows)}")

    production, tests, routes = read_text_files()
    prod_by_id: dict[str, list[str]] = defaultdict(list)
    test_by_id: dict[str, list[str]] = defaultdict(list)
    for path, text in production.items():
        for op_id in set(OP_RE.findall(text)):
            prod_by_id[op_id].append(path)
    for path, text in tests.items():
        for op_id in set(OP_RE.findall(text)):
            test_by_id[op_id].append(path)

    evidence_files: dict[str, dict[str, Any]] = {}
    evidence_by_id: dict[str, list[str]] = defaultdict(list)
    terminal_evidence_by_id: dict[str, list[str]] = defaultdict(list)
    route_contracts: dict[str, dict[str, str]] = {}

    for path in sorted(EVIDENCE.glob("*.json")):
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            continue
        rel = path.relative_to(ROOT).as_posix()
        evidence_files[rel] = document
        ids = op_ids(document)
        for op_id in ids:
            evidence_by_id[op_id].append(rel)

        state = explicit_terminal_state(document) if isinstance(document, dict) else None
        if state and len(ids) == 1:
            terminal_evidence_by_id[next(iter(ids))].append(rel)

        # Conservative multi-operation route/API evidence: each item in operations[]
        # is explicitly declared as terminalized by the document inventory and carries
        # a concrete canonical_route + proof. This is evidence classification only.
        if isinstance(document, dict):
            operations = document.get("operations")
            inventory = document.get("inventory")
            declared = inventory.get("terminalized_by_implementation_snapshot") if isinstance(inventory, dict) else None
            if isinstance(operations, list) and isinstance(declared, int) and declared == len(operations):
                for item in operations:
                    if not isinstance(item, dict):
                        continue
                    op_id = item.get("operation_id")
                    canonical_route = item.get("canonical_route")
                    proof = item.get("proof")
                    if isinstance(op_id, str) and OP_RE.fullmatch(op_id) and isinstance(canonical_route, str) and proof:
                        terminal_evidence_by_id[op_id].append(rel)
                        route_contracts[op_id] = {
                            "evidence": rel,
                            "canonical_route": canonical_route,
                            "proof": str(proof),
                        }

    route_text = "\n".join(routes.values())
    audited = []
    category_counts: Counter[str] = Counter()
    by_kind: dict[str, Counter[str]] = defaultdict(Counter)
    by_domain: dict[str, Counter[str]] = defaultdict(Counter)

    for row in rows:
        op_id = str(row.get("operation_id") or "")
        prod = sorted(prod_by_id.get(op_id, []))
        test = sorted(test_by_id.get(op_id, []))
        evidence = sorted(set(evidence_by_id.get(op_id, [])))
        terminal_evidence = sorted(set(terminal_evidence_by_id.get(op_id, [])))
        security = security_signals(row, test, tests)
        contract = route_contracts.get(op_id)
        route_present = False
        if contract:
            canonical = contract["canonical_route"]
            route_present = canonical in route_text
            if not route_present:
                # Laravel route declarations omit the leading / in some prefix groups;
                # retain a conservative secondary literal check.
                route_present = canonical.lstrip("/") in route_text

        exact_complete = bool(terminal_evidence and prod and test and security["tenant_ok"] and security["authorization_ok"])
        route_complete = bool(contract and route_present and test and security["tenant_ok"] and security["authorization_ok"])

        if exact_complete or route_complete:
            category = "evidence_contract_complete"
        elif terminal_evidence and test and not prod and not contract:
            category = "production_marker_debt"
        elif terminal_evidence and prod and not test:
            category = "focused_test_debt"
        elif terminal_evidence:
            category = "terminal_evidence_incomplete"
        elif evidence:
            category = "nonterminal_or_broad_evidence_only"
        elif prod or test:
            category = "code_or_test_without_terminal_evidence"
        else:
            category = "no_operation_specific_evidence"

        category_counts[category] += 1
        by_kind[str(row.get("kind"))][category] += 1
        by_domain[str(row.get("domain"))][category] += 1
        audited.append({
            "operation_id": op_id,
            "domain": row.get("domain"),
            "kind": row.get("kind"),
            "route_screen": row.get("route_screen"),
            "visible_control": row.get("visible_control"),
            "mutation": row.get("mutation"),
            "tenant_owned": row.get("tenant_owned"),
            "risk": row.get("risk"),
            "committed_reconciliation_state": row.get("migration_state"),
            "category": category,
            "production_markers": prod,
            "test_markers": test,
            "evidence_files": evidence,
            "terminal_evidence_files": terminal_evidence,
            "route_contract": contract,
            "route_literal_present": route_present,
            "security": security,
        })

    report = {
        "schema_version": 1,
        "authority": "AIMWWeb Issue #257",
        "mode": "NON_AUTHORITATIVE_AUDIT_ONLY",
        "warning": "This report does not change or regenerate canonical parity and must not be used as a terminal-count claim.",
        "rows": len(audited),
        "category_counts": dict(category_counts),
        "by_kind": {kind: dict(counts) for kind, counts in sorted(by_kind.items())},
        "by_domain": {domain: dict(counts) for domain, counts in sorted(by_domain.items())},
        "operations": audited,
    }
    OUT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"rows": 931, "category_counts": dict(category_counts)}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
