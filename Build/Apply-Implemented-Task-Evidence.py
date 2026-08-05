from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TRACKER = ROOT / "task-tracker.json"

EVIDENCE: dict[int, list[str]] = {
    141: ["src/AIWordPressManager.Application/Abstractions/AI/IAIProvider.cs"],
    142: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    143: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    144: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    145: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    146: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    147: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs", "src/AIWordPressManager.Web/Program.cs"],
    148: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    149: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    150: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    151: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    152: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    153: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    154: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    156: ["src/AIWordPressManager.Infrastructure/AI/AIPlatformServices.cs"],
    157: ["src/AIWordPressManager.Web/Services/ExecutionCenterService.cs", "src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs"],
    158: ["src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    159: ["src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    161: ["src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs"],
    162: ["src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    163: ["src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    164: ["src/AIWordPressManager.Web/Components/Pages/ExecutionCenter.razor", "src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    166: ["src/AIWordPressManager.Web/Components/Pages/ExecutionCenter.razor", "src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    167: ["src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs"],
    168: ["src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs", "src/AIWordPressManager.Web/Program.cs"],
    169: ["src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs"],
    170: ["src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs"],
    179: ["src/AIWordPressManager.Web/Services/ContentPlannerService.cs"],
    181: ["src/AIWordPressManager.Web/Services/ContentPlannerService.cs", "src/AIWordPressManager.Web/Program.cs"],
    182: ["src/AIWordPressManager.Web/Services/ContentPlannerService.cs", "src/AIWordPressManager.Web/Program.cs"],
    183: ["src/AIWordPressManager.Web/Services/ContentPlannerService.cs", "src/AIWordPressManager.Web/Services/ExecutionCenterService.cs"],
    184: ["src/AIWordPressManager.Web/Services/ContentPlannerService.cs", "src/AIWordPressManager.Web/Services/AppNotificationService.cs", "src/AIWordPressManager.Web/Components/Shared/GlobalNotifications.razor"],
    201: ["tests/AIWordPressManager.Tests/AIWordPressManager.Tests.csproj", "tests/AIWordPressManager.Tests/AIPlatformTests.cs"],
    207: ["tests/AIWordPressManager.Tests/ExecutionCenterTests.cs", "tests/AIWordPressManager.Tests/ApprovalWorkflowTests.cs", ".github/workflows/dotnet-build.yml"],
    209: ["tests/AIWordPressManager.Tests/AIPlatformTests.cs", ".github/workflows/dotnet-build.yml"],
}


def main() -> int:
    if not TRACKER.exists():
        raise SystemExit(f"Tracker JSON not found: {TRACKER}")

    data = json.loads(TRACKER.read_text(encoding="utf-8"))
    tasks = {int(str(task.get("id", "0"))): task for task in data.get("tasks", []) if str(task.get("id", "")).isdigit()}
    now = datetime.now(timezone.utc).isoformat()
    updated = 0

    for task_id, evidence in EVIDENCE.items():
        task = tasks.get(task_id)
        if task is None:
            continue
        missing = [path for path in evidence if not (ROOT / path).exists()]
        if missing:
            task["status"] = "pending"
            task["status_text"] = "غير مكتمل"
            task["notes"] = "Missing evidence: " + "; ".join(missing)
        else:
            task["status"] = "completed"
            task["status_text"] = "مكتمل - تم التنفيذ"
            task["notes"] = "Evidence: " + "; ".join(evidence)
        task["updated_at_utc"] = now
        updated += 1

    data["summary"] = {
        "total": len(data.get("tasks", [])),
        "completed": sum(1 for task in data.get("tasks", []) if task.get("status") == "completed"),
        "pending": sum(1 for task in data.get("tasks", []) if task.get("status") != "completed"),
    }
    data["evidence_sync"] = {"updated": updated, "updated_at_utc": now}
    data["generated_at_utc"] = now
    TRACKER.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(data["summary"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
