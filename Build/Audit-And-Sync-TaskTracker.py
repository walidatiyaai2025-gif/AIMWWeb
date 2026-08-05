from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ROOT = Path(__file__).resolve().parents[1]
DOCX = ROOT / "AI_WordPress_Manager_Web_ASPNET_Core_Full_Task_Tracker_AR.docx"
JSON_FILE = ROOT / "task-tracker.json"
BATCH_SIZE = 100
DONE_COLOR = "E2F0D9"
PENDING_COLOR = "FCE4EC"
HEADER_COLOR = "D9EAF7"

TEXT_EXTENSIONS = {".cs", ".razor", ".json", ".yml", ".yaml", ".md", ".ps1", ".bat", ".props", ".sln", ".csproj", ".css", ".js"}
IGNORE_PARTS = {".git", "bin", "obj", ".vs", "node_modules"}

EXPLICIT_RULES: list[tuple[tuple[str, ...], tuple[str, ...]]] = [
    (("نطاق mvp", "scope"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("المستخدمين والأدوار", "roles"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("definition of done", "dod"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("git workflow", "الإصدارات"), ("docs/governance/PROJECT_GOVERNANCE.md", ".github/workflows/dotnet-build.yml")),
    (("backlog", "مراحل الإطلاق"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("سجل المخاطر", "risk register"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("development/staging/production", "البيئات"), ("docs/governance/PROJECT_GOVERNANCE.md",)),
    (("aiwordpressmanager.web.sln", "solution"), ("AIWordPressManager.Web.sln",)),
    (("blazor server", "web project"), ("src/AIWordPressManager.Web/AIWordPressManager.Web.csproj",)),
    (("إنشاء domain", "domain project"), ("src/AIWordPressManager.Domain/AIWordPressManager.Domain.csproj",)),
    (("إنشاء application", "application project"), ("src/AIWordPressManager.Application/AIWordPressManager.Application.csproj",)),
    (("إنشاء infrastructure", "infrastructure project"), ("src/AIWordPressManager.Infrastructure/AIWordPressManager.Infrastructure.csproj",)),
    (("wordpress integration", "عميل rest"), ("src/AIWordPressManager.Application/Abstractions/WordPress/IWordPressApiClient.cs",)),
    (("ai integration", "مزود قابل للتبديل"), ("src/AIWordPressManager.Application/Abstractions/AI", "src/AIWordPressManager.Infrastructure/AI")),
    (("automation", "jobs"), ("src/AIWordPressManager.Application/Abstractions/Automation", "src/AIWordPressManager.Web/Services/Automation")),
    (("unit/integration/e2e", "tests"), ("tests",)),
    (("sqlite", "appdbcontext"), ("src/AIWordPressManager.Persistence/AppDbContext.cs",)),
    (("site", "كيان الموقع"), ("src/AIWordPressManager.Domain/Entities/Site.cs",)),
    (("credential", "بيانات الاعتماد"), ("src/AIWordPressManager.Domain/Entities/SiteCredential.cs",)),
    (("connection", "اختبار الاتصال"), ("src/AIWordPressManager.Web/Services/SiteWebService.cs",)),
    (("notification", "الإشعارات"), ("src/AIWordPressManager.Web/Services/AppNotificationService.cs", "src/AIWordPressManager.Web/Components/Shared/GlobalNotifications.razor")),
    (("localization", "الترجمة", "العربية", "الإنجليزية"), ("src/AIWordPressManager.Web/Services/AppLanguageService.cs",)),
    (("health", "الصحة"), ("src/AIWordPressManager.Web/Services/SystemHealthService.cs",)),
    (("sync", "المزامنة"), ("src/AIWordPressManager.Web/Services/WordPressSyncWebService.cs",)),
    (("seo", "تحليل seo"), ("src/AIWordPressManager.Web/Services/SeoAuditExecutionService.cs",)),
    (("execution center", "مركز التنفيذ"), ("src/AIWordPressManager.Web/Components/Pages/ExecutionCenter.razor",)),
    (("content explorer", "مستكشف المحتوى"), ("src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor",)),
    (("media", "الوسائط"), ("src/AIWordPressManager.Web/Components/Pages/Media.razor",)),
    (("comments", "التعليقات"), ("src/AIWordPressManager.Web/Components/Pages/Comments.razor",)),
    (("users", "المستخدمين"), ("src/AIWordPressManager.Web/Components/Pages/Users.razor",)),
    (("categories", "التصنيفات", "tags", "الوسوم"), ("src/AIWordPressManager.Web/Components/Pages/Taxonomies.razor",)),
    (("install", "تثبيت أول مرة"), ("Install-First-Time.ps1", "Install-First-Time.bat")),
    (("update", "التحديث"), ("Update-System.ps1", "Update-System.bat")),
    (("build", "ci"), (".github/workflows/dotnet-build.yml", "Build/Run-Web.ps1")),
]


def normalize(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip().lower()


def repo_paths() -> set[str]:
    result: set[str] = set()
    for p in ROOT.rglob("*"):
        if any(part in IGNORE_PARTS for part in p.parts):
            continue
        if p.is_file():
            result.add(p.relative_to(ROOT).as_posix())
    return result


def path_exists(paths: set[str], required: str) -> bool:
    required = required.rstrip("/")
    return required in paths or any(p.startswith(required + "/") for p in paths)


def task_text(task: dict) -> str:
    return normalize(" | ".join(str(v) for v in task.get("values", [])))


def rule_evidence(text: str, paths: set[str]) -> list[str]:
    found: list[str] = []
    for keywords, required_paths in EXPLICIT_RULES:
        if not any(normalize(k) in text for k in keywords):
            continue
        matches = [p for p in required_paths if path_exists(paths, p)]
        if matches:
            found.extend(matches)
    return sorted(set(found))


def generic_evidence(text: str, paths: set[str]) -> list[str]:
    tokens = [t for t in re.findall(r"[a-zA-Z][a-zA-Z0-9_.-]{3,}", text) if t.lower() not in {"project", "document", "manager", "developer", "wordpress"}]
    evidence: list[str] = []
    for token in tokens[:8]:
        low = token.lower()
        for p in paths:
            if low in p.lower():
                evidence.append(p)
                break
    return sorted(set(evidence))


def audit(data: dict) -> dict:
    paths = repo_paths()
    now = datetime.now(timezone.utc).isoformat()
    processed = 0
    for task in data.get("tasks", []):
        try:
            task_id = int(str(task.get("id", "0")))
        except ValueError:
            continue
        if task_id < 1 or task_id > BATCH_SIZE:
            continue
        processed += 1
        text = task_text(task)
        evidence = rule_evidence(text, paths)
        if not evidence:
            evidence = generic_evidence(text, paths)

        if evidence:
            task["status"] = "completed"
            task["status_text"] = "مكتمل - تم التنفيذ"
            task["notes"] = "Evidence: " + "; ".join(evidence[:6])
        else:
            task["status"] = "pending"
            task["status_text"] = "غير مكتمل"
            task["notes"] = "تمت المراجعة ضمن دفعة أول 100 مهمة ولم يُعثر على دليل تنفيذ كافٍ بعد."
        task["updated_at_utc"] = now

    data["audit"] = {
        "batch": f"1-{BATCH_SIZE}",
        "processed": processed,
        "method": "repository evidence audit",
        "updated_at_utc": now,
    }
    data["summary"] = {
        "total": len(data.get("tasks", [])),
        "completed": sum(1 for t in data.get("tasks", []) if t.get("status") == "completed"),
        "pending": sum(1 for t in data.get("tasks", []) if t.get("status") != "completed"),
    }
    data["generated_at_utc"] = now
    return data


def shade(cell, fill: str) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = tc_pr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tc_pr.append(shd)
    shd.set(qn("w:fill"), fill)


def find_status_column(headers: Iterable[str]) -> int | None:
    for i, header in enumerate(headers):
        h = normalize(header)
        if any(x in h for x in ("الحالة", "حالة", "status", "الإنجاز", "التنفيذ")):
            return i
    return None


def apply_to_docx(data: dict) -> None:
    document = Document(DOCX)
    positions = {(int(t["table_index"]), int(t["row_index"])): t for t in data.get("tasks", []) if "table_index" in t and "row_index" in t}
    for ti, table in enumerate(document.tables):
        if not table.rows:
            continue
        for cell in table.rows[0].cells:
            shade(cell, HEADER_COLOR)
        status_col = find_status_column(cell.text for cell in table.rows[0].cells)
        for ri, row in enumerate(table.rows[1:], 1):
            task = positions.get((ti, ri))
            if not task:
                continue
            done = task.get("status") == "completed"
            for cell in row.cells:
                shade(cell, DONE_COLOR if done else PENDING_COLOR)
            if status_col is not None and status_col < len(row.cells):
                row.cells[status_col].text = "مكتمل - تم التنفيذ" if done else "غير مكتمل"
            if len(row.cells) >= 11:
                row.cells[10].text = task.get("notes", "")
    document.save(DOCX)


def main() -> int:
    if not JSON_FILE.exists() or not DOCX.exists():
        raise SystemExit("task-tracker.json and tracker DOCX are required")
    data = json.loads(JSON_FILE.read_text(encoding="utf-8"))
    data = audit(data)
    JSON_FILE.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    apply_to_docx(data)
    print(json.dumps(data["summary"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
