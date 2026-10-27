#!/usr/bin/env python3
"""Evidence-first operation parity reconciliation for AIMWWeb Issue #257.

This tool never treats an open PR, controller, frontend placeholder, or unpushed
work as parity by itself. It rebuilds an operation-by-operation reconciliation
from the canonical 931-operation ledger and an exact-SHA evidence manifest.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[3]
VARIANT = ROOT / "variants" / "laravel-aiwmweb"
LEDGER = VARIANT / "docs" / "capability-parity-ledger.json"
MANIFEST = VARIANT / "docs" / "operation-parity-evidence-sources.json"

ALLOWED = {"PORTED", "ADAPTED", "PENDING", "BLOCKED", "VERIFIED_UNAVAILABLE_EXTERNAL"}
GENERIC = {
    "service", "services", "controller", "controllers", "job", "worker", "async",
    "get", "set", "save", "update", "create", "delete", "remove", "add", "run",
    "execute", "handle", "read", "write", "list", "load", "find", "try", "process",
    "manager", "provider", "repository", "gateway", "api", "http", "app",
}
CODE_SUFFIXES = {".php", ".py", ".ts", ".tsx", ".js", ".jsx", ".sh", ".blade.php"}
TEST_MARKERS = ("/tests/", "Test.php", ".test.ts", ".test.tsx", ".spec.ts", ".spec.tsx")
DOMAIN_TEST_HINTS = {
    "ai": ("ai", "provider", "planner"),
    "approvals": ("approval", "demoverticalslice"),
    "automation": ("automation", "adminoperations"),
    "backup": ("backup", "connector", "adminoperations"),
    "billing": ("billing",),
    "comments": ("contentplatform", "comment"),
    "content": ("contentplatform", "contentrevision"),
    "email": ("email", "notification"),
    "identity": ("tenantisolation", "adminoperations", "frontend"),
    "media": ("contentplatform", "media"),
    "operations": ("adminoperations", "runtime", "connector"),
    "platform": ("acceptanceframework", "tenantisolation", "runtime"),
    "reports": ("adminoperations", "report"),
    "seo": ("seo", "demoverticalslice"),
    "settings": ("adminoperations", "tenantisolation"),
    "sites": ("demoverticalslice", "site", "connector"),
    "sync": ("contentplatform", "demoverticalslice", "sync"),
    "taxonomy": ("contentplatform", "taxonomy"),
}

# Explicit semantic aliases are deliberately narrow. They bridge known architecture
# renames; they do not turn broad domain presence into operation evidence.
ALIASES = {
    "ai": {
        "AIPlatformServices": ("AiCenterService", "AiGenerationService", "AiUsageService"),
        "ApplicationSettingsService": ("ProviderConfigService", "ProviderSecretStore"),
    },
    "approvals": {
        "Approval": ("Approval", "ExecutionCreator"),
        "ApprovalService": ("Approval", "ExecutionCreator"),
    },
    "automation": {
        "Automation": ("AdministrationService", "OperationsControlPlaneService"),
        "Scheduler": ("OperationsControlPlaneService",),
    },
    "backup": {
        "Backup": ("ConnectorBackupGateway", "class-aimw-connector-runtime"),
        "Restore": ("ConnectorBackupGateway", "class-aimw-connector-runtime"),
    },
    "billing": {
        "Billing": ("BillingController", "SubscriptionService", "EntitlementService"),
        "Subscription": ("SubscriptionService", "SubscriptionStateMachine"),
        "Plan": ("BillingPlan", "BillingPlanAdminController"),
        "Quota": ("UsageQuotaService", "EntitlementService"),
        "PayPal": ("PayPalProvider", "PayPalWebhookController"),
    },
    "comments": {
        "Comment": ("ContentPlatformService", "ContentApiController", "BulkCommentModerationJob"),
    },
    "content": {
        "Content": ("ContentPlatformService", "ContentApiController"),
        "Revision": ("ContentPlatformService", "ContentRevision"),
        "Import": ("ContentTransferJob", "ContentPlatformService"),
        "Export": ("ContentTransferJob", "ContentPlatformService"),
    },
    "email": {
        "EmailOutboxService": ("EmailDeliveryService", "SendEmailDeliveryJob"),
        "OperationalEmailAlertService": ("DomainNotificationBridge", "NotificationPlatformService"),
        "AccountEmailSettingsService": ("MailConfigurationService", "EmailSecretStore"),
        "NotificationInboxService": ("NotificationPlatformService",),
        "AppNotificationService": ("NotificationPlatformService",),
        "EmailDeliveryHistoryService": ("EmailDeliveryService",),
        "EmailOutboxWorker": ("SendEmailDeliveryJob", "EmailDeliveryService"),
        "EmailSchedule": ("EmailScheduleService", "RunEmailSchedulesJob"),
        "SecurityAuditEmailAlertWorker": ("DomainNotificationBridge", "NotificationPlatformService"),
        "ExecutionJobFailureAlert": ("DomainNotificationBridge", "NotificationPlatformService"),
        "SiteSyncFailureAlert": ("DomainNotificationBridge", "NotificationPlatformService"),
    },
    "media": {
        "Media": ("ContentPlatformService", "ContentApiController", "MediaUploadJob"),
    },
    "operations": {
        "Operations": ("OperationsControlPlaneService", "AdministrationService"),
        "Diagnostics": ("SiteDiagnosticsService", "SiteDiagnosticsController"),
    },
    "reports": {
        "Report": ("GenerateReportExport", "OperationsControlPlaneService"),
        "Export": ("GenerateReportExport",),
    },
    "seo": {
        "Seo": ("SeoAuditService", "SeoRunService", "SitesSeoAuditController", "SeoRunController"),
        "SEO": ("SeoAuditService", "SeoRunService", "SitesSeoAuditController", "SeoRunController"),
    },
    "settings": {
        "Settings": ("AdministrationService", "OperationsControlPlaneService"),
    },
    "sites": {
        "Site": ("SiteManagementController", "SiteDiagnosticsService", "PairingService", "DemoController"),
        "Connector": ("PairingService", "ConnectorProtocol", "ConnectorScopePolicy"),
    },
    "sync": {
        "Sync": ("SyncContentJob", "SyncSiteJob", "ContentPlatformService"),
        "SiteSync": ("SyncSiteJob", "SyncRun"),
    },
    "taxonomy": {
        "Taxonomy": ("ContentPlatformService", "ContentApiController", "BulkTaxonomyAssignmentJob"),
        "Category": ("ContentPlatformService", "ContentApiController"),
        "Tag": ("ContentPlatformService", "ContentApiController"),
    },
}

@dataclass
class FileEvidence:
    path: str
    text: str
    test: bool

@dataclass
class Snapshot:
    label: str
    sha: str
    domains: set[str]
    files: list[FileEvidence]
    supporting_only: bool = False
    operation_ids: set[str] = field(default_factory=set)

    @property
    def code(self) -> list[FileEvidence]:
        return [f for f in self.files if not f.test]

    @property
    def tests(self) -> list[FileEvidence]:
        return [f for f in self.files if f.test]


def run_git(*args: str, check: bool = True) -> str:
    proc = subprocess.run(["git", *args], cwd=ROOT, text=True, capture_output=True)
    if check and proc.returncode != 0:
        raise RuntimeError(f"git {' '.join(args)} failed: {proc.stderr.strip()}")
    return proc.stdout


def split_words(value: str) -> list[str]:
    value = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", value or "")
    return [w.lower() for w in re.findall(r"[A-Za-z0-9]+", value) if len(w) > 1]


def meaningful(value: str) -> set[str]:
    return {w for w in split_words(value) if w not in GENERIC and not w.isdigit()}


def method_name(row: dict) -> str:
    value = str(row.get("visible_control") or "")
    # HTTP lines are routes, not method names.
    if value.upper().startswith("HTTP "):
        return ""
    bracketed = re.findall(r"\[([A-Za-z_][A-Za-z0-9_]*)\]", value)
    if bracketed:
        return bracketed[-1]
    first = re.match(r"\s*([A-Za-z_][A-Za-z0-9_]*)", value)
    return first.group(1) if first else ""


def normalize_route(value: str) -> tuple[str, ...]:
    value = (value or "").split("|")[0].strip().lower()
    value = re.sub(r"\{[^}]+\}", "{}", value)
    literals = []
    for part in value.strip("/").split("/"):
        part = part.strip()
        if not part or part == "{}" or part in {"api", "v1", "v2", "tenants", "tenant"}:
            continue
        part = re.sub(r"[^a-z0-9_-]", "", part)
        if part:
            literals.append(part)
    return tuple(literals)


def is_text_path(path: str) -> bool:
    if path.endswith(".blade.php"):
        return True
    return Path(path).suffix.lower() in CODE_SUFFIXES


def is_test_path(path: str) -> bool:
    low = path.lower()
    return any(marker.lower() in low for marker in TEST_MARKERS)


def load_snapshot(source: dict) -> Snapshot:
    sha = source["sha"]
    paths = run_git("ls-tree", "-r", "--name-only", sha, "--", "variants/laravel-aiwmweb").splitlines()
    files: list[FileEvidence] = []
    for path in paths:
        if not is_text_path(path):
            continue
        low = path.lower()
        if any(part in low for part in ("/vendor/", "/node_modules/", "package-lock.json")):
            continue
        if low.endswith("capability-parity-ledger.json") or ".sync-payload." in low:
            continue
        text = run_git("show", f"{sha}:{path}", check=False)
        if not text:
            continue
        files.append(FileEvidence(path=path, text=text, test=is_test_path(path)))
    return Snapshot(
        label=source["label"],
        sha=sha,
        domains=set(source.get("domains", [])),
        files=files,
        supporting_only=bool(source.get("supporting_only", False)),
        operation_ids={str(operation_id) for operation_id in source.get("operation_ids", []) if str(operation_id)},
    )


def target_aliases(domain: str, row: dict) -> tuple[str, ...]:
    values: list[str] = []
    seen: set[str] = set()
    hay = " ".join([
        str(row.get("service") or ""),
        str(row.get("background_job") or ""),
        str(row.get("route_screen") or ""),
        str(row.get("visible_control") or ""),
        Path(str(row.get("current_source") or "")).stem,
    ])
    for key, aliases in ALIASES.get(domain, {}).items():
        if key.lower() in hay.lower():
            for alias in aliases:
                if alias not in seen:
                    seen.add(alias)
                    values.append(alias)
    return tuple(values)


def file_symbol_score(row: dict, file: FileEvidence) -> tuple[int, list[str]]:
    path_name = Path(file.path).name
    text_head = file.text[:30000]
    source_tokens = meaningful(" ".join([
        str(row.get("service") or ""),
        str(row.get("background_job") or ""),
        Path(str(row.get("current_source") or "")).stem,
    ]))
    target_tokens = meaningful(path_name + " " + text_head[:1500])
    overlap = sorted(source_tokens & target_tokens)
    score = len(overlap) * 2
    reasons = [f"token:{x}" for x in overlap]

    service = str(row.get("service") or "")
    job = str(row.get("background_job") or "")
    method = method_name(row)
    for symbol in (service, job):
        if symbol and symbol.lower() in text_head.lower():
            score += 5
            reasons.append(f"symbol:{symbol}")
    if method:
        core = re.sub(r"Async$", "", method, flags=re.I)
        if method.lower() in text_head.lower() or (len(core) >= 5 and core.lower() in text_head.lower()):
            score += 3
            reasons.append(f"method:{method}")
    for alias in target_aliases(str(row.get("domain")), row):
        if alias.lower() in (file.path + "\n" + text_head).lower():
            score += 4
            reasons.append(f"alias:{alias}")
    return score, reasons


def route_score(row: dict, file: FileEvidence) -> tuple[int, list[str]]:
    literals = normalize_route(str(row.get("route_screen") or ""))
    if not literals:
        return 0, []
    low = (file.path + "\n" + file.text).lower()
    matched = [x for x in literals if x in low]
    score = len(matched) * 2
    reasons = [f"route:{x}" for x in matched]
    if len(literals) >= 2 and tuple(matched[-2:]) == literals[-2:]:
        score += 3
    visible = str(row.get("visible_control") or "")
    verb = re.match(r"HTTP\s+(GET|POST|PUT|PATCH|DELETE)", visible, re.I)
    if verb and verb.group(1).lower() in low:
        score += 1
        reasons.append(f"verb:{verb.group(1).upper()}")
    return score, reasons


def tenant_security_ok(row: dict, snap: Snapshot, matched: FileEvidence) -> tuple[bool, list[str]]:
    if not row.get("tenant_owned"):
        return True, []
    low = (matched.text + "\n" + "\n".join(f.text for f in snap.code if "/Models/" in f.path or "/database/migrations/" in f.path))[:400000].lower()
    tokens = ("belongstotenant", "tenant_id", "tenantcontext", "tenant.context", "tenantauthorizer")
    found = [t for t in tokens if t in low]
    return bool(found), [f"tenant:{t}" for t in found[:3]]


def mutation_security_ok(row: dict, snap: Snapshot, matched: FileEvidence) -> tuple[bool, list[str]]:
    if not row.get("mutation") and str(row.get("risk") or "").lower() not in {"high", "critical"}:
        return True, []
    low = (matched.text + "\n" + "\n".join(f.text for f in snap.code if "policy" in f.path.lower() or "authorization" in f.path.lower())).lower()
    tokens = ("authorize", "policy", "permission", "tenantauthorizer", "gate::", "scope")
    found = [t for t in tokens if t in low]
    return bool(found), [f"auth:{t}" for t in found[:3]]


def test_evidence(row: dict, snap: Snapshot, matched: FileEvidence) -> tuple[bool, str]:
    if not snap.tests:
        return False, ""
    stem = Path(matched.path).stem.lower()
    route_literals = normalize_route(str(row.get("route_screen") or ""))
    method = method_name(row).lower()
    for test in snap.tests:
        low = (test.path + "\n" + test.text).lower()
        if stem and len(stem) >= 5 and stem in low:
            return True, test.path
        if method and len(method) >= 5 and method in low:
            return True, test.path
        if route_literals and len(route_literals[-1]) >= 4 and route_literals[-1] in low:
            return True, test.path
    hints = DOMAIN_TEST_HINTS.get(str(row.get("domain")), ())
    for test in snap.tests:
        low = test.path.lower()
        if any(h in low for h in hints):
            return True, test.path
    return False, ""


def best_code_evidence(row: dict, snapshots: list[Snapshot]) -> tuple[Snapshot, FileEvidence, int, list[str]] | None:
    domain = str(row.get("domain"))
    kind = str(row.get("kind"))
    best = None
    operation_id = str(row.get("operation_id") or "")
    for snap in snapshots:
        if snap.supporting_only or domain not in snap.domains:
            continue
        if snap.operation_ids and operation_id not in snap.operation_ids:
            continue
        for file in snap.code:
            score, reasons = file_symbol_score(row, file)
            if kind == "api":
                rscore, rreasons = route_score(row, file)
                score += rscore
                reasons += rreasons
            elif kind == "background_job":
                if "/Jobs/" in file.path or "job" in Path(file.path).name.lower():
                    score += 2
                if snap.operation_ids:
                    job = str(row.get("background_job") or "")
                    concrete_job = job[1:] if len(job) > 1 and job.startswith("I") and job[1].isupper() else job
                    if concrete_job and Path(file.path).stem.lower() == concrete_job.lower():
                        score += 4
                        reasons.append(f"scoped-job:{concrete_job}")
            if best is None or score > best[2]:
                best = (snap, file, score, reasons)
    return best


def classify(row: dict, snapshots: list[Snapshot], exclusions: dict[str, str]) -> dict:
    result = dict(row)
    result["migration_state"] = "PENDING"
    result["laravel_destination"] = ""
    result["acceptance_test"] = ""
    result["evidence"] = ""
    result["reconciliation"] = {
        "decision": "PENDING",
        "reason": "",
        "source_label": None,
        "source_sha": None,
        "destination_path": None,
    }

    domain = str(row.get("domain"))
    kind = str(row.get("kind"))

    if kind in {"visible_control", "route"}:
        result["reconciliation"]["reason"] = "Visible/screen operation lacks converged functional UI+backend evidence; frontend foundation placeholders are not parity."
        return result

    best = best_code_evidence(row, snapshots)
    if best is None:
        result["reconciliation"]["reason"] = "No countable pushed source owns this domain operation."
        return result
    snap, file, score, reasons = best

    threshold = {"api": 7, "service": 6, "background_job": 6}.get(kind, 8)
    if score < threshold:
        result["reconciliation"]["reason"] = f"No operation-specific destination met evidence threshold ({score} < {threshold})."
        return result

    tenant_ok, tenant_reasons = tenant_security_ok(row, snap, file)
    if not tenant_ok:
        result["reconciliation"]["reason"] = "Candidate destination lacks operation-linked tenant ownership evidence."
        return result
    auth_ok, auth_reasons = mutation_security_ok(row, snap, file)
    if not auth_ok:
        result["reconciliation"]["reason"] = "Mutation/high-risk candidate lacks operation-linked authorization evidence."
        return result
    tested, test_path = test_evidence(row, snap, file)
    if not tested:
        result["reconciliation"]["reason"] = "Candidate destination lacks test evidence."
        return result

    result["migration_state"] = "ADAPTED"
    result["laravel_destination"] = file.path
    result["acceptance_test"] = test_path
    evidence_bits = reasons + tenant_reasons + auth_reasons
    result["evidence"] = f"{snap.label}@{snap.sha}: {file.path}; " + ", ".join(evidence_bits[:10])
    result["reconciliation"] = {
        "decision": "ADAPTED",
        "reason": "Operation-specific pushed destination, tenancy/authorization where required, and test evidence found.",
        "source_label": snap.label,
        "source_sha": snap.sha,
        "destination_path": file.path,
        "score": score,
        "signals": evidence_bits[:12],
    }
    return result


def summarize(rows: list[dict], manifest: dict) -> dict:
    states = Counter(r["migration_state"] for r in rows)
    total = len(rows)
    terminal = total - states["PENDING"]
    visible = [r for r in rows if r.get("kind") == "visible_control"]
    visible_terminal = sum(1 for r in visible if r["migration_state"] != "PENDING")

    by_domain: dict[str, dict] = {}
    for domain in sorted({str(r["domain"]) for r in rows}):
        subset = [r for r in rows if r["domain"] == domain]
        s = Counter(r["migration_state"] for r in subset)
        t = len(subset)
        term = t - s["PENDING"]
        by_domain[domain] = {
            "total": t,
            "ported": s["PORTED"],
            "adapted": s["ADAPTED"],
            "pending": s["PENDING"],
            "blocked": s["BLOCKED"],
            "verified_unavailable_external": s["VERIFIED_UNAVAILABLE_EXTERNAL"],
            "terminal": term,
            "percent": round(term / t * 100, 2) if t else 0.0,
        }

    return {
        "schema_version": 1,
        "authority": "AIMWWeb Issue #257",
        "base_main_sha": manifest["base_main_sha"],
        "classification_policy": {
            "terminal_requires": [
                "pushed exact-SHA source",
                "operation-specific destination",
                "tenant ownership evidence when tenant-owned",
                "authorization evidence for mutations/high-risk operations",
                "test evidence",
            ],
            "frontend_placeholder_policy": "not_counted",
            "unpushed_work_policy": "not_counted",
            "percentage_formula": "(TOTAL - PENDING) / TOTAL * 100",
        },
        "totals": {
            "total": total,
            "ported": states["PORTED"],
            "adapted": states["ADAPTED"],
            "pending": states["PENDING"],
            "blocked": states["BLOCKED"],
            "verified_unavailable_external": states["VERIFIED_UNAVAILABLE_EXTERNAL"],
            "terminal": terminal,
            "overall_parity_percent": round(terminal / total * 100, 2) if total else 0.0,
        },
        "visible_controls": {
            "total": len(visible),
            "terminal": visible_terminal,
            "percent": round(visible_terminal / len(visible) * 100, 2) if visible else 0.0,
        },
        "domains": by_domain,
        "uncounted_work": manifest.get("excluded_sources", []),
        "operations": rows,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--summary-output", type=Path)
    parser.add_argument("--check-total", type=int, default=931)
    args = parser.parse_args()

    ledger = json.loads(LEDGER.read_text(encoding="utf-8"))
    source_manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    rows = ledger.get("operations", [])
    if len(rows) != args.check_total:
        raise SystemExit(f"expected {args.check_total} canonical operations, found {len(rows)}")

    snapshots = [load_snapshot(s) for s in source_manifest["countable_sources"]]
    exclusions = {s["label"]: s["reason"] for s in source_manifest.get("excluded_sources", [])}
    reconciled = [classify(row, snapshots, exclusions) for row in rows]
    payload = summarize(reconciled, source_manifest)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.summary_output:
        compact = {k: v for k, v in payload.items() if k != "operations"}
        args.summary_output.write_text(json.dumps(compact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    totals = payload["totals"]
    print("TOTAL=", totals["total"], sep="")
    print("PORTED=", totals["ported"], sep="")
    print("ADAPTED=", totals["adapted"], sep="")
    print("PENDING=", totals["pending"], sep="")
    print("BLOCKED=", totals["blocked"], sep="")
    print("VERIFIED_UNAVAILABLE_EXTERNAL=", totals["verified_unavailable_external"], sep="")
    print("PARITY_PERCENT=", f'{totals["overall_parity_percent"]:.2f}', sep="")
    print("VISIBLE_CONTROL_PERCENT=", f'{payload["visible_controls"]["percent"]:.2f}', sep="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
