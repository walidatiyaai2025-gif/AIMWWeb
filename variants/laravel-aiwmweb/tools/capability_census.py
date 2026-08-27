#!/usr/bin/env python3
"""Build the Laravel AIWMWeb capability parity denominator from current AIMWWeb source.

The census is intentionally conservative: every routable Razor surface, visible interactive
control, mapped HTTP API endpoint, application service method, and background worker entry
point becomes an operation. New source operations therefore increase the denominator instead
of silently disappearing from parity accounting.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from pathlib import Path
from typing import Iterable

REPO_ROOT = Path(__file__).resolve().parents[3]
VARIANT_ROOT = REPO_ROOT / "variants" / "laravel-aiwmweb"
LEDGER_JSON = VARIANT_ROOT / "docs" / "capability-parity-ledger.json"
LEDGER_MD = VARIANT_ROOT / "docs" / "CAPABILITY_PARITY_LEDGER.md"
DEAD_JSON = VARIANT_ROOT / "docs" / "dead-function-census.json"

STATE_VALUES = {"PENDING", "PORTED", "ADAPTED", "VERIFIED_UNAVAILABLE_EXTERNAL", "BLOCKED"}
MUTATION_WORDS = {
    "add", "apply", "approve", "archive", "cancel", "change", "clean", "create", "delete",
    "disable", "edit", "enable", "end", "execute", "generate", "install", "invite", "moderate",
    "publish", "regenerate", "remove", "reply", "restore", "retry", "revoke", "run", "save",
    "schedule", "send", "sync", "test", "update", "upload", "verify",
}
DESTRUCTIVE_WORDS = {"delete", "remove", "restore", "revoke", "disable", "cleanup", "clean", "rollback"}

DOMAIN_RULES = [
    ("billing", ("billing", "subscription", "payment", "plan")),
    ("email", ("email", "notification", "inbox")),
    ("ai", ("ai", "prompt", "provider", "usage")),
    ("seo", ("seo",)),
    ("approvals", ("approval", "approve")),
    ("automation", ("automation", "schedule", "execution", "planner")),
    ("backup", ("backup", "restore", "recovery")),
    ("sync", ("sync", "synchronization", "conflict")),
    ("media", ("media", "upload")),
    ("comments", ("comment",)),
    ("taxonomy", ("taxonomy", "category", "categories", "tag", "tags")),
    ("content", ("content", "post", "page", "explorer", "editor")),
    ("sites", ("site", "wordpress", "connection")),
    ("identity", ("user", "role", "permission", "session", "profile", "register")),
    ("operations", ("operation", "reliability", "maintenance", "health", "diagnostic", "log")),
    ("reports", ("report", "export")),
    ("settings", ("setting", "configuration")),
]


def slug(value: str) -> str:
    value = re.sub(r"<[^>]+>", " ", value)
    value = re.sub(r"@\([^)]*\)", " ", value)
    value = re.sub(r"\s+", " ", value).strip()
    return value[:160]


def attrs(text: str) -> dict[str, str]:
    pairs: dict[str, str] = {}
    for key, value in re.findall(r'([:@\w-]+)\s*=\s*"([^"]*)"', text, flags=re.S):
        pairs[key.lower()] = value.strip()
    return pairs


def domain_for(*values: str) -> str:
    haystack = " ".join(values).lower()
    for domain, words in DOMAIN_RULES:
        if any(word in haystack for word in words):
            return domain
    return "platform"


def op_id(domain: str, key: str) -> str:
    digest = hashlib.sha1(key.encode("utf-8"), usedforsecurity=False).hexdigest()[:10].upper()
    return f"AIMW-{domain[:4].upper()}-{digest}"


def is_mutation(*values: str) -> bool:
    words = set(re.findall(r"[a-z]+", " ".join(values).lower()))
    return bool(words & MUTATION_WORDS)


def risk_for(mutation: bool, *values: str) -> str:
    text = " ".join(values).lower()
    if any(word in text for word in DESTRUCTIVE_WORDS):
        return "critical"
    if mutation:
        return "high"
    return "medium" if any(word in text for word in ("credential", "security", "permission", "billing")) else "low"


def external_for(domain: str, text: str) -> str:
    lowered = text.lower()
    if "paypal" in lowered or domain == "billing":
        return "payment_provider"
    if domain == "email":
        return "email_provider"
    if domain in {"ai", "seo"}:
        return "ai_provider and/or WordPress"
    if domain in {"sites", "content", "media", "comments", "taxonomy", "sync", "backup"}:
        return "WordPress"
    return "none"


def connector_required(domain: str, text: str) -> tuple[bool, str]:
    lowered = text.lower()
    if domain == "backup" or any(word in lowered for word in ("plugin", "theme", "filesystem", "database backup", "maintenance mode")):
        return True, "advanced-site-operations"
    return False, ""


def make_operation(*, kind: str, source: str, route: str, control: str, service: str = "", job: str = "") -> dict:
    domain = domain_for(source, route, control, service, job)
    mutation = is_mutation(control, service, job)
    connector, scope = connector_required(domain, " ".join((source, route, control, service, job)))
    external = external_for(domain, " ".join((source, route, control, service, job)))
    key = "|".join((kind, source, route, control, service, job))
    native_rest = domain in {"sites", "content", "media", "comments", "taxonomy", "sync", "seo"} and not connector
    tenant_owned = domain not in {"platform"}
    approval = mutation and (domain in {"approvals", "seo", "backup", "operations"} or risk_for(mutation, control) == "critical")
    return {
        "operation_id": op_id(domain, key),
        "kind": kind,
        "domain": domain,
        "route_screen": route,
        "visible_control": control,
        "current_source": source,
        "service": service,
        "persistence": "current AIMWWeb persistence / authoritative external state" if mutation else "current AIMWWeb read model / authoritative external state",
        "background_job": job,
        "mutation": mutation,
        "external_dependency": external,
        "approval": approval,
        "verification": "authoritative re-read / reconciled visible state" if mutation else "rendered/read response matches authoritative source",
        "laravel_destination": "PENDING — assign to owning Laravel domain worker",
        "native_wp_rest": native_rest,
        "connector_required": connector,
        "connector_scope": scope,
        "tenant_owned": tenant_owned,
        "risk": risk_for(mutation, control, service, job),
        "migration_state": "PENDING",
        "acceptance_test": "",
        "evidence": "",
    }


def method_body(source: str, method: str) -> str:
    if not method:
        return ""
    name = re.sub(r"[^A-Za-z0-9_]", "", method.split("(", 1)[0].split("=>", 1)[0].strip())
    if not name:
        return ""
    match = re.search(rf"(?:private|protected|public)\s+(?:async\s+)?[\w<>,?\[\].]+\s+{re.escape(name)}\s*\([^)]*\).*?(?=\n\s*(?:private|protected|public)\s|\n\}}\s*$)", source, flags=re.S)
    return match.group(0) if match else ""


def scan_razor() -> list[dict]:
    root = REPO_ROOT / "src" / "AIWordPressManager.Web" / "Components"
    operations: list[dict] = []
    if not root.exists():
        return operations
    tag_re = re.compile(r"<(AppButton|button|a|EditForm|AppConfirmDialog)\b([^>]*)>", flags=re.I | re.S)
    inject_re = re.compile(r"@inject\s+([\w.<>]+)\s+(\w+)")
    for path in sorted(root.rglob("*.razor")):
        source = path.read_text(encoding="utf-8", errors="replace")
        rel = path.relative_to(REPO_ROOT).as_posix()
        routes = re.findall(r'@page\s+"([^"]+)"', source)
        route = " | ".join(routes) if routes else f"component:{path.stem}"
        for item in routes:
            operations.append(make_operation(kind="route", source=rel, route=item, control="Open/render route"))

        injections = inject_re.findall(source)
        for index, match in enumerate(tag_re.finditer(source), start=1):
            tag = match.group(1)
            raw_attrs = match.group(2)
            parsed = attrs(raw_attrs)
            handler = parsed.get("onclick") or parsed.get("@onclick") or parsed.get("onsubmit") or parsed.get("onvalidsubmit") or parsed.get("onconfirm") or ""
            href = parsed.get("href", "")
            text = parsed.get("text", "") or parsed.get("title", "") or parsed.get("aria-label", "")
            if tag.lower() == "a" and not href:
                continue
            if tag.lower() in {"appbutton", "button"} and not (handler or href or "submit" in raw_attrs.lower()):
                continue
            if tag.lower() == "editform" and not handler:
                continue
            if tag.lower() == "appconfirmdialog" and not handler:
                continue
            body = method_body(source, handler)
            services = [type_name for type_name, variable in injections if f"{variable}." in body]
            service = ", ".join(dict.fromkeys(services))
            control = slug(text) or slug(href) or slug(handler) or f"{tag} control {index}"
            if href:
                control = f"{control} -> {slug(href)}"
            if handler:
                control = f"{control} [{slug(handler)}]"
            operations.append(make_operation(kind="visible_control", source=rel, route=route, control=control, service=service))
    return operations


def scan_apis() -> list[dict]:
    operations: list[dict] = []
    program = REPO_ROOT / "src" / "AIWordPressManager.Web" / "Program.cs"
    if not program.exists():
        return operations
    source = program.read_text(encoding="utf-8", errors="replace")
    rel = program.relative_to(REPO_ROOT).as_posix()
    for verb, route in re.findall(r'\.Map(Get|Post|Put|Delete|Patch)\s*\(\s*"([^"]+)"', source):
        operations.append(make_operation(kind="api", source=rel, route=route, control=f"HTTP {verb.upper()} {route}"))
    return operations


def service_files() -> Iterable[Path]:
    for root in (REPO_ROOT / "src").glob("AIWordPressManager.*"):
        if "Tests" in root.name:
            continue
        for path in root.rglob("*.cs"):
            if "Tests" in path.parts:
                continue
            if "Service" in path.name or "Manager" in path.name or "Repository" in path.name:
                yield path


def scan_services() -> list[dict]:
    operations: list[dict] = []
    method_re = re.compile(r"public\s+(?:async\s+)?(?:Task(?:<[^;{]+>)?|ValueTask(?:<[^;{]+>)?|IAsyncEnumerable<[^>]+>|[A-Za-z_][\w<>,.?\[\]]*)\s+([A-Z][A-Za-z0-9_]*)\s*\(")
    excluded = {"Dispose", "ToString", "GetHashCode", "Equals"}
    for path in sorted(set(service_files())):
        source = path.read_text(encoding="utf-8", errors="replace")
        rel = path.relative_to(REPO_ROOT).as_posix()
        class_name = path.stem
        for method in dict.fromkeys(method_re.findall(source)):
            if method in excluded:
                continue
            operations.append(make_operation(kind="service", source=rel, route=f"service:{class_name}", control=method, service=class_name))
    return operations


def scan_jobs() -> list[dict]:
    operations: list[dict] = []
    for path in sorted((REPO_ROOT / "src").rglob("*.cs")):
        if "Tests" in path.parts:
            continue
        name = path.stem
        if not any(token in name.lower() for token in ("worker", "job", "scheduler", "background")):
            continue
        source = path.read_text(encoding="utf-8", errors="replace")
        rel = path.relative_to(REPO_ROOT).as_posix()
        entries = re.findall(r"(?:public|protected)\s+(?:override\s+)?(?:async\s+)?(?:Task|ValueTask|void)\s+(ExecuteAsync|HandleAsync|Handle|RunAsync|ProcessAsync)\s*\(", source)
        if not entries:
            entries = ["background execution"]
        for entry in dict.fromkeys(entries):
            operations.append(make_operation(kind="background_job", source=rel, route=f"job:{name}", control=entry, job=name))
    return operations


def scan_dead_functions() -> list[dict]:
    findings: list[dict] = []
    roots = [REPO_ROOT / "src" / "AIWordPressManager.Web" / "Components", VARIANT_ROOT]
    patterns = [
        ("href_hash", re.compile(r'href\s*=\s*["\']#["\']', re.I), "high"),
        ("javascript_href", re.compile(r'href\s*=\s*["\']javascript:', re.I), "critical"),
        ("not_implemented", re.compile(r"NotImplementedException"), "critical"),
        ("explicit_simulation", re.compile(r"\b(simulat(?:e|ed|ion)|fake success|toast-only)\b", re.I), "high"),
    ]
    for root in roots:
        if not root.exists():
            continue
        for path in root.rglob("*"):
            if path.suffix.lower() not in {".razor", ".cs", ".php", ".tsx", ".ts", ".jsx", ".js"}:
                continue
            if any(part in {"vendor", "node_modules", "tests"} for part in path.parts):
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for line_no, line in enumerate(text.splitlines(), start=1):
                for kind, pattern, severity in patterns:
                    if pattern.search(line):
                        findings.append({
                            "kind": kind,
                            "severity": severity,
                            "source": path.relative_to(REPO_ROOT).as_posix(),
                            "line": line_no,
                            "snippet": slug(line)[:220],
                            "status": "OPEN_REVIEW",
                        })
    return findings


def dedupe(operations: list[dict]) -> list[dict]:
    by_id: dict[str, dict] = {}
    for operation in operations:
        by_id.setdefault(operation["operation_id"], operation)
    return sorted(by_id.values(), key=lambda row: (row["domain"], row["current_source"], row["route_screen"], row["visible_control"]))


def build() -> tuple[dict, dict]:
    operations = dedupe(scan_razor() + scan_apis() + scan_services() + scan_jobs())
    states = Counter(row["migration_state"] for row in operations)
    kinds = Counter(row["kind"] for row in operations)
    domains = Counter(row["domain"] for row in operations)
    payload = {
        "schema_version": 1,
        "authority": "AIMWWeb Issue #257",
        "source_variant": "AIMWWEB_CURRENT",
        "target_variant": "LARAVEL_AIWMWEB",
        "denominator_policy": "machine-derived current-source operations; no omitted operation counts as progress",
        "allowed_states": sorted(STATE_VALUES),
        "totals": {
            "total_operations": len(operations),
            "ported": states.get("PORTED", 0),
            "adapted": states.get("ADAPTED", 0),
            "pending": states.get("PENDING", 0),
            "blocked": states.get("BLOCKED", 0),
            "verified_unavailable_external": states.get("VERIFIED_UNAVAILABLE_EXTERNAL", 0),
            "connector_required": sum(1 for row in operations if row["connector_required"]),
            "native_rest": sum(1 for row in operations if row["native_wp_rest"]),
            "laravel_only": sum(1 for row in operations if row["external_dependency"] == "none"),
        },
        "counts_by_kind": dict(sorted(kinds.items())),
        "counts_by_domain": dict(sorted(domains.items())),
        "operations": operations,
    }
    dead = {"schema_version": 1, "authority": "AIMWWeb Issue #257", "findings": scan_dead_functions()}
    return payload, dead


def render_markdown(payload: dict, dead: dict) -> str:
    totals = payload["totals"]
    lines = [
        "# Capability Parity Ledger",
        "",
        "Authority: AIMWWeb Issue #257",
        "",
        "This ledger is generated from the **current ASP.NET AIMWWeb source** by `tools/capability_census.py`. "
        "The JSON ledger is canonical at operation granularity; this Markdown file is the human summary.",
        "",
        "Unknown work is `PENDING`. Terminal states are only `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, and `BLOCKED`. "
        "No operation may be removed from the denominator to improve the score.",
        "",
        "## Live parity totals",
        "",
        f"- TOTAL_OPERATIONS: **{totals['total_operations']}**",
        f"- PORTED: **{totals['ported']}**",
        f"- ADAPTED: **{totals['adapted']}**",
        f"- PENDING: **{totals['pending']}**",
        f"- BLOCKED: **{totals['blocked']}**",
        f"- VERIFIED_UNAVAILABLE_EXTERNAL: **{totals['verified_unavailable_external']}**",
        f"- CONNECTOR_REQUIRED: **{totals['connector_required']}**",
        f"- NATIVE_REST: **{totals['native_rest']}**",
        f"- LARAVEL_ONLY: **{totals['laravel_only']}**",
        f"- DEAD_FUNCTION_FINDINGS_REQUIRING_REVIEW: **{len(dead['findings'])}**",
        "",
        "Completion % = `(PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL + BLOCKED) / TOTAL_OPERATIONS × 100`. "
        "`BLOCKED` is terminal accounting only when the blocker and evidence are explicit; it is not a success claim.",
        "",
        "## Denominator composition",
        "",
        "| Kind | Operations |",
        "| --- | ---: |",
    ]
    for kind, count in payload["counts_by_kind"].items():
        lines.append(f"| `{kind}` | {count} |")
    lines += ["", "## Domain composition", "", "| Domain | Operations |", "| --- | ---: |"]
    for domain, count in payload["counts_by_domain"].items():
        lines.append(f"| `{domain}` | {count} |")
    lines += [
        "",
        "## Canonical operation records",
        "",
        "See `capability-parity-ledger.json`. Each row records stable `operation_id`, domain, route/screen, visible control, current source, service, persistence, background job, mutation/external/approval/verification classification, Laravel destination, Native WP REST vs Connector path, tenant ownership, risk, migration state, acceptance test, and evidence.",
        "",
        "## Dead / fake function census",
        "",
        "See `dead-function-census.json`. High-confidence source patterns are recorded as findings for explicit review; the Laravel release gate fails if forbidden fake-success patterns appear in the new variant production source.",
        "",
    ]
    return "\n".join(lines)


def write_outputs(payload: dict, dead: dict) -> None:
    LEDGER_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    DEAD_JSON.write_text(json.dumps(dead, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    LEDGER_MD.write_text(render_markdown(payload, dead), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write", action="store_true", help="write canonical JSON/Markdown census files")
    args = parser.parse_args()
    payload, dead = build()
    if args.write:
        write_outputs(payload, dead)
    totals = payload["totals"]
    for key in ("total_operations", "ported", "adapted", "pending", "blocked", "connector_required", "native_rest", "laravel_only"):
        print(f"{key.upper()}={totals[key]}")
    print(f"DEAD_FUNCTIONS_FOUND={len(dead['findings'])}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
