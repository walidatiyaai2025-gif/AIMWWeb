#!/usr/bin/env python3
"""Laravel AIWMWeb release acceptance gate for Issue #257."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
VARIANT = ROOT / "variants" / "laravel-aiwmweb"
LEDGER = VARIANT / "docs" / "capability-parity-ledger.json"
DEAD = VARIANT / "docs" / "dead-function-census.json"
BACKEND = VARIANT / "backend"
ALLOWED = {"PENDING", "PORTED", "ADAPTED", "VERIFIED_UNAVAILABLE_EXTERNAL", "BLOCKED"}
REQUIRED = {
    "operation_id", "domain", "route_screen", "visible_control", "current_source", "service",
    "persistence", "background_job", "mutation", "external_dependency", "approval", "verification",
    "laravel_destination", "native_wp_rest", "connector_required", "connector_scope", "tenant_owned",
    "risk", "migration_state", "acceptance_test", "evidence",
}


def fail(message: str) -> None:
    print(f"RELEASE_GATE_FAIL: {message}", file=sys.stderr)
    raise SystemExit(1)


def validate_ledger() -> dict:
    if not LEDGER.exists():
        fail(f"missing ledger: {LEDGER}")
    payload = json.loads(LEDGER.read_text(encoding="utf-8"))
    rows = payload.get("operations")
    if not isinstance(rows, list) or not rows:
        fail("parity ledger has no operations; denominator cannot be zero")
    seen: set[str] = set()
    for index, row in enumerate(rows, start=1):
        missing = REQUIRED - set(row)
        if missing:
            fail(f"row {index} missing fields: {sorted(missing)}")
        operation_id = str(row["operation_id"])
        if not re.fullmatch(r"AIMW-[A-Z0-9]{1,4}-[A-F0-9]{10}", operation_id):
            fail(f"invalid operation_id {operation_id}")
        if operation_id in seen:
            fail(f"duplicate operation_id {operation_id}")
        seen.add(operation_id)
        state = row["migration_state"]
        if state not in ALLOWED:
            fail(f"{operation_id}: invalid migration_state {state}")
        if state in {"PORTED", "ADAPTED"} and (not str(row["acceptance_test"]).strip() or not str(row["evidence"]).strip()):
            fail(f"{operation_id}: {state} requires acceptance_test and evidence")
        if state == "VERIFIED_UNAVAILABLE_EXTERNAL":
            if str(row["external_dependency"]).strip() in {"", "none"} or not str(row["evidence"]).strip():
                fail(f"{operation_id}: unavailable-external requires dependency and evidence")
        if state == "BLOCKED" and not str(row["evidence"]).strip():
            fail(f"{operation_id}: BLOCKED requires explicit blocker evidence")
        if row["connector_required"] and not str(row["connector_scope"]).strip():
            fail(f"{operation_id}: connector-required row has no connector scope")
    totals = payload.get("totals", {})
    if totals.get("total_operations") != len(rows):
        fail("TOTAL_OPERATIONS does not match ledger row count")
    counted = sum(int(totals.get(key, 0)) for key in ("ported", "adapted", "pending", "blocked", "verified_unavailable_external"))
    if counted != len(rows):
        fail("migration-state totals do not equal denominator")
    return payload


def scan_variant_production() -> None:
    roots = [BACKEND / "app", VARIANT / "frontend" / "src", VARIANT / "connector"]
    forbidden = [
        (re.compile(r'href\s*=\s*["\']#["\']', re.I), "href=# dead control"),
        (re.compile(r'href\s*=\s*["\']javascript:', re.I), "javascript: dead control"),
        (re.compile(r"NotImplementedException"), "NotImplementedException in production source"),
        (re.compile(r"\bfake[_ -]?success\b", re.I), "fake success marker"),
        (re.compile(r"\btoast[_ -]?only\b", re.I), "toast-only marker"),
    ]
    performance = [
        (re.compile(r"::all\s*\("), "unbounded Eloquent ::all() query"),
        (re.compile(r"\busleep\s*\("), "synchronous usleep in production path"),
    ]
    for root in roots:
        if not root.exists():
            continue
        for path in root.rglob("*"):
            if path.suffix.lower() not in {".php", ".tsx", ".ts", ".jsx", ".js"}:
                continue
            if any(part in {"vendor", "node_modules", "tests"} for part in path.parts):
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for pattern, label in forbidden + performance:
                if pattern.search(text):
                    fail(f"{label}: {path.relative_to(ROOT)}")


def validate_migrations() -> None:
    migrations = sorted((BACKEND / "database" / "migrations").glob("*.php"))
    if len(migrations) < 3:
        fail("required Laravel migrations missing")
    merged = "\n".join(path.read_text(encoding="utf-8", errors="replace") for path in migrations)
    for token in ("tenants", "tenant_memberships", "tenant_id", "audit_events", "idempotency_keys"):
        if token not in merged:
            fail(f"tenant-core migration invariant missing: {token}")


def validate_contract_matrix() -> None:
    path = BACKEND / "tests" / "Contracts" / "acceptance-matrix.json"
    if not path.exists():
        fail("acceptance matrix catalog missing")
    data = json.loads(path.read_text(encoding="utf-8"))
    required_sections = {"tenant_resources", "connector_security", "execution_safety", "queue_concurrency", "frontend_acceptance", "performance"}
    if required_sections - set(data):
        fail(f"acceptance matrix missing sections: {sorted(required_sections - set(data))}")
    if len(data["tenant_resources"]) < 20:
        fail("tenant isolation matrix does not cover required resource families")
    if len(data["connector_security"]) < 13:
        fail("connector security matrix is incomplete")
    if len(data["execution_safety"]) < 8:
        fail("execution safety matrix is incomplete")


def main() -> int:
    payload = validate_ledger()
    validate_migrations()
    validate_contract_matrix()
    scan_variant_production()
    if DEAD.exists():
        json.loads(DEAD.read_text(encoding="utf-8"))
    totals = payload["totals"]
    terminal = totals["ported"] + totals["adapted"] + totals["verified_unavailable_external"] + totals["blocked"]
    percent = (terminal / totals["total_operations"] * 100) if totals["total_operations"] else 0.0
    print(f"TOTAL_OPERATIONS={totals['total_operations']}")
    print(f"PARITY_TERMINAL_PERCENT={percent:.2f}")
    print("TENANT_SECURITY_GATE=PASS")
    print("MIGRATION_GATE=PASS")
    print("FAKE_SUCCESS_GATE=PASS")
    print("PERFORMANCE_STATIC_GATE=PASS")
    print("RELEASE_GATE=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
