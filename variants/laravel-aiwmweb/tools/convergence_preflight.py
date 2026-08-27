#!/usr/bin/env python3
"""Mechanical convergence invariants for Laravel AIWMWeb.

This intentionally does not decide product architecture. It validates conditions that
must be true after Codex composes the authoritative worker branches.
"""

from __future__ import annotations

import argparse
import json
import re
from collections import defaultdict
from pathlib import Path


def rel(path: Path, root: Path) -> str:
    return path.relative_to(root).as_posix()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--route-json", type=Path)
    parser.add_argument("--json-out", type=Path)
    parser.add_argument("--require-composed", action="store_true")
    args = parser.parse_args()

    root = args.root.resolve()
    backend = root / "variants/laravel-aiwmweb/backend"
    errors: list[str] = []
    warnings: list[str] = []
    facts: dict[str, object] = {}

    migrations = sorted((backend / "database/migrations").glob("*.php"))
    table_creates: dict[str, list[str]] = defaultdict(list)
    explicit_indexes: dict[str, list[str]] = defaultdict(list)
    timestamp_groups: dict[str, list[str]] = defaultdict(list)

    create_re = re.compile(r"Schema::create\(\s*['\"]([^'\"]+)['\"]")
    explicit_index_re = re.compile(
        r"\$table->(?:unique|index)\(\s*\[[^\]]*\]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        re.S,
    )

    for path in migrations:
        text = path.read_text(encoding="utf-8")
        path_rel = rel(path, root)
        for table in create_re.findall(text):
            table_creates[table].append(path_rel)
        for index in explicit_index_re.findall(text):
            explicit_indexes[index].append(path_rel)
        stamp = "_".join(path.name.split("_")[:4])
        timestamp_groups[stamp].append(path.name)

    for table, owners in sorted(table_creates.items()):
        if len(owners) > 1:
            errors.append(f"duplicate table creation {table}: {owners}")

    # SQLite index names are schema-global, unlike MySQL where they are per-table.
    for index, owners in sorted(explicit_indexes.items()):
        if len(owners) > 1:
            errors.append(f"SQLite explicit index-name collision {index}: {owners}")

    for stamp, names in sorted(timestamp_groups.items()):
        if len(names) > 1:
            warnings.append(f"shared migration timestamp {stamp}: {sorted(names)}")

    declarations: dict[str, list[str]] = defaultdict(list)
    namespace_re = re.compile(r"^namespace\s+([^;]+);", re.M)
    decl_re = re.compile(r"^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)", re.M)
    for path in sorted((backend / "app").rglob("*.php")):
        text = path.read_text(encoding="utf-8")
        ns = namespace_re.search(text)
        if not ns:
            continue
        for name in decl_re.findall(text):
            fqcn = ns.group(1).strip() + "\\" + name
            declarations[fqcn].append(rel(path, root))

    for fqcn, owners in sorted(declarations.items()):
        if len(owners) > 1:
            errors.append(f"duplicate PHP declaration {fqcn}: {owners}")

    provider = backend / "app/Providers/AppServiceProvider.php"
    bindings: dict[str, set[str]] = defaultdict(set)
    if provider.exists():
        text = provider.read_text(encoding="utf-8")
        for abstract, concrete in re.findall(
            r"->bind\(\s*([A-Za-z0-9_\\]+)::class\s*,\s*([A-Za-z0-9_\\]+)::class\s*\)", text
        ):
            bindings[abstract].add(concrete)
        for abstract, concretes in sorted(bindings.items()):
            if len(concretes) > 1:
                errors.append(f"service binding collision {abstract}: {sorted(concretes)}")

    package_json = backend / "package.json"
    package_lock = backend / "package-lock.json"
    if package_json.exists():
        pkg = json.loads(package_json.read_text(encoding="utf-8"))
        desired: dict[str, str] = {}
        for section in ("dependencies", "devDependencies", "optionalDependencies"):
            desired.update(pkg.get(section, {}))
        if not package_lock.exists():
            errors.append("package.json exists without package-lock.json; convergence requires deterministic npm ci")
        else:
            lock = json.loads(package_lock.read_text(encoding="utf-8"))
            root_pkg = lock.get("packages", {}).get("", {})
            locked: dict[str, str] = {}
            for section in ("dependencies", "devDependencies", "optionalDependencies"):
                locked.update(root_pkg.get(section, {}))
            missing = sorted(set(desired) - set(locked))
            stale = sorted(name for name in desired if name in locked and desired[name] != locked[name])
            extra = sorted(set(locked) - set(desired))
            if missing or stale or extra:
                errors.append(
                    "package-lock root contract does not match package.json: "
                    f"missing={missing} stale={stale} extra={extra}"
                )

    conflict_markers: list[str] = []
    for base in (backend / "app", backend / "routes", backend / "bootstrap", backend / "config"):
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if path.is_file() and path.suffix in {".php", ".json", ".ts", ".tsx", ".js"}:
                text = path.read_text(encoding="utf-8", errors="replace")
                if "<<<<<<<" in text or ">>>>>>>" in text:
                    conflict_markers.append(rel(path, root))
    if conflict_markers:
        errors.append(f"unresolved conflict markers: {sorted(conflict_markers)}")

    route_count = None
    if args.route_json and args.route_json.exists():
        routes = json.loads(args.route_json.read_text(encoding="utf-8"))
        route_count = len(routes)
        route_keys: dict[tuple[str, str], list[str]] = defaultdict(list)
        for route in routes:
            methods = str(route.get("method", "")).split("|")
            uri = str(route.get("uri", ""))
            action = str(route.get("action", ""))
            for method in methods:
                method = method.strip().upper()
                if method and method != "HEAD":
                    route_keys[(method, uri)].append(action)
        for (method, uri), actions in sorted(route_keys.items()):
            if len(actions) > 1:
                errors.append(f"duplicate route {method} {uri}: {actions}")

    if args.require_composed:
        required = {
            "App\\Models\\Site",
            "App\\Connector\\ConnectorProtocol",
            "App\\Connector\\AdvancedWordPressGateway",
            "App\\Content\\ContentPlatformService",
            "App\\Billing\\UsageQuotaService",
            "App\\AI\\Platform\\Services\\AiGenerationService",
            "App\\Services\\SeoManagerService",
            "App\\Sites\\SiteDiagnosticsService",
            "App\\Http\\Controllers\\HealthController",
        }
        missing = sorted(required - set(declarations))
        if missing:
            errors.append(f"composed authority classes missing: {missing}")

        # #268 and sync staging payloads are deliberately not convergence product inputs.
        leaked_payloads = sorted(path.name for path in root.glob(".sync-payload.part*"))
        if leaked_payloads:
            errors.append(f"staging sync payloads leaked into composed product tree: {leaked_payloads}")

    facts.update(
        {
            "migration_count": len(migrations),
            "created_tables": sorted(table_creates),
            "explicit_indexes": sorted(explicit_indexes),
            "php_declaration_count": len(declarations),
            "service_bindings": {key: sorted(value) for key, value in sorted(bindings.items())},
            "route_count": route_count,
        }
    )

    report = {"ok": not errors, "errors": errors, "warnings": warnings, "facts": facts}
    if args.json_out:
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        args.json_out.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
