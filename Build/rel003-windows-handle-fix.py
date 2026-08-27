from pathlib import Path


def replace_count(path: str, old: str, new: str, minimum: int = 1) -> None:
    target = Path(path)
    text = target.read_text(encoding="utf-8")
    count = text.count(old)
    if count < minimum:
        raise SystemExit(f"Expected at least {minimum} occurrence(s) in {path}, found {count}: {old!r}")
    target.write_text(text.replace(old, new), encoding="utf-8")


replace_count(
    "src/AIWordPressManager.Web/Services/BackupManagementService.cs",
    "Mode = SqliteOpenMode.ReadOnly\n        };",
    "Mode = SqliteOpenMode.ReadOnly,\n            Pooling = false\n        };",
    minimum=2,
)
replace_count(
    "src/AIWordPressManager.Web/Services/BackupManagementService.cs",
    "Mode = SqliteOpenMode.ReadWriteCreate\n        };",
    "Mode = SqliteOpenMode.ReadWriteCreate,\n            Pooling = false\n        };",
)
replace_count(
    "src/AIWordPressManager.Infrastructure/Security/OfflineApplicationRestoreService.cs",
    "Mode = SqliteOpenMode.ReadOnly\n        };",
    "Mode = SqliteOpenMode.ReadOnly,\n            Pooling = false\n        };",
)
replace_count(
    "tests/AIWordPressManager.Tests/SqliteTestDatabase.cs",
    "Mode = SqliteOpenMode.ReadWriteCreate\n        };",
    "Mode = SqliteOpenMode.ReadWriteCreate,\n            Pooling = false\n        };",
)
