#!/usr/bin/env python3
"""Fail-closed compatibility and per-file integrity gate for signed cPanel ZIP.

Run after signing and BEFORE uploading/publishing any artifact. Mirrors the
allowlist enforced by installed SystemUpdateService::validatePath, including
0.0.0 restrictions, without running untrusted code from the archive. Signature
verification is deliberately a separate required CI step.
"""
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path

ALLOWED_PREFIXES = (
    "app/", "config/", "resources/", "routes/", "vendor/", "public/build/",
    "database/migrations/", "database/seeders/",
)
ALLOWED_FILES = {
    "composer.json", "composer.lock", "artisan", "bootstrap/app.php",
    "bootstrap/providers.php", "public/downloads/aimw-connector.zip",
    "aiwm-cpanel-https-pull.php",
}
LEGACY_BLOCKED_FILES = {
    "public/downloads/aimw-connector.zip", "aiwm-cpanel-https-pull.php",
}
HASH = re.compile(r"[a-f0-9]{64}\Z")
VERSION = re.compile(r"[0-9]+\.[0-9]+\.[0-9]+\Z")
FILE = re.compile(r"[A-Za-z0-9_./@+~-]+\Z")
MIGRATION = re.compile(r"database/migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+\.php\Z")
SEEDER = re.compile(r"database/seeders/[A-Za-z0-9_/]+\.php\Z")


def validate_path(path: str, legacy: bool) -> None:
    if (
        not isinstance(path, str) or not path or len(path) > 240
        or not FILE.fullmatch(path) or path.startswith("/")
        or re.search(r"(^|/)(\.{1,2}|\.env|\.git)(/|$)", path, flags=re.I)
        or re.search(r"^(storage|app/storage|public/storage)(/|$)", path, flags=re.I)
    ):
        raise ValueError("Unsafe signed update path")
    if path not in ALLOWED_FILES and not path.startswith(ALLOWED_PREFIXES):
        raise ValueError("Update path outside approved directories: " + path)
    if path.startswith("database/migrations/") and not MIGRATION.fullmatch(path):
        raise ValueError("Invalid migration path: " + path)
    if path.startswith("database/seeders/") and not SEEDER.fullmatch(path):
        raise ValueError("Invalid seeder path: " + path)
    if path.startswith("resources/") and "private" in path:
        raise ValueError("Legacy updater rejects private-named resource: " + path)
    if legacy and path in LEGACY_BLOCKED_FILES:
        raise ValueError("0.0.0 updater rejects added executable/connector path: " + path)


def check(manifest_path: Path, zip_path: Path) -> tuple[int, int]:
    manifest = json.loads(manifest_path.read_text(encoding="utf8"))
    files = manifest.get("files")
    min_version = manifest.get("minimum_supported_version")
    if (
        manifest.get("product") != "LARAVEL_AIWMWEB"
        or not isinstance(files, dict) or not files or len(files) > 25000
        or not isinstance(min_version, str) or not VERSION.fullmatch(min_version)
    ):
        raise ValueError("Invalid signed manifest or unsupported version")
    parts = tuple(int(n) for n in min_version.split("."))
    legacy = parts < (1, 0, 0)
    if any(not isinstance(key, str) or not isinstance(value, str) or not HASH.fullmatch(value)
           for key, value in files.items()):
        raise ValueError("Malformed signed file SHA256 inventory")
    for path in files:
        validate_path(path, legacy)
    migrations = [p for p in files if p.startswith("database/migrations/")]
    if manifest.get("schema_migrations") != migrations:
        raise ValueError("Signed migration inventory mismatch")
    policy = "forward_only" if migrations else "none"
    if manifest.get("migration_policy", policy) != policy:
        raise ValueError("Signed migration policy mismatch")
    with zip_path.open("rb") as payload:
        payload_hash = hashlib.file_digest(payload, "sha256").hexdigest()
    if manifest.get("sha256") != payload_hash:
        raise ValueError("Signed inner ZIP checksum mismatch")

    total_uncompressed = 0
    with zipfile.ZipFile(zip_path, "r") as archive:
        names = archive.namelist()
        if len(names) != len(files) or len(set(names)) != len(names) or set(names) != set(files):
            raise ValueError("Signed ZIP file inventory disagrees with manifest")
        for info in archive.infolist():
            if (info.create_system == 3 and
                    (info.external_attr >> 16) & 0o170000 == 0o120000):
                raise ValueError("Symlink found in signed ZIP")
            if info.file_size > 26214400:
                raise ValueError("Oversized signed ZIP file")
            total_uncompressed += info.file_size
            if total_uncompressed > 314572800:
                raise ValueError("Signed ZIP exceeds safe total uncompressed size")
            digest = hashlib.sha256()
            with archive.open(info, "r") as src:
                while True:
                    chunk = src.read(1024 * 1024)
                    if not chunk:
                        break
                    digest.update(chunk)
            if digest.hexdigest() != files[info.filename]:
                raise ValueError("Signed ZIP file hash mismatch: " + info.filename)
    return len(files), total_uncompressed


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit("Usage: verify-signed-update-compat.py MANIFEST.json UPDATE.zip")
    try:
        count, total = check(Path(sys.argv[1]), Path(sys.argv[2]))
        print(f"PASS: signed ZIP compatible with deployed updater; {count} files, {total} bytes")
    except (OSError, ValueError, json.JSONDecodeError, zipfile.BadZipFile, EOFError) as exc:
        sys.exit("BLOCKED: signed package compatibility or integrity failed: " + str(exc))
