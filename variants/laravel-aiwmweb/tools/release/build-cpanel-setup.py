#!/usr/bin/env python3
"""Build cPanel-ready *complete* Laravel setup ZIP from an exact trusted CI checkout.

Output layout is deliberately:
    app/      Laravel code, vendor, bundled React build, public skeleton
    public/   standalone cPanel web document root (must be set as domain root)
The installer initializes the secret .env only on the hosting server; no
build/CI password, APP_KEY or signing secret enters a downloadable artifact.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile

HERE = Path(__file__).resolve()
VARIANT = HERE.parents[2]
BACKEND = VARIANT / "backend"
MAX_FILES = 35000
MAX_SINGLE = 30 * 1024 * 1024
MAX_TOTAL = 420 * 1024 * 1024
DIRS = ("app", "bootstrap", "config", "database", "public", "resources", "routes", "vendor")
OPTIONAL_DIRS = ("lang",)
FILES = ("artisan", "composer.json", "composer.lock", ".env.example")
SKELETON = ("app/storage/app/", "app/storage/framework/cache/data/", "app/storage/framework/sessions/", "app/storage/framework/testing/", "app/storage/framework/views/", "app/storage/logs/", "app/bootstrap/cache/")
OMIT_COMPONENTS = {".git", "node_modules", "tests", ".github", ".idea", ".vscode", ".next", "__pycache__"}
OMIT_NAMES = {".env", ".env.production", ".env.testing", ".DS_Store", "auth.json", "hot", "installed.json", "history.jsonl"}


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def safe_name(name: str) -> None:
    posix = PurePosixPath(name)
    if not name or name.startswith("/") or "\\" in name or ".." in posix.parts:
        raise ValueError(f"Unsafe archive member: {name}")
    if not re.fullmatch(r"[a-zA-Z0-9_@+./~ -]+", name):
        raise ValueError(f"Unexpected characters in archive member: {name}")


def entries():
    for directory in (*DIRS, *OPTIONAL_DIRS):
        root = BACKEND / directory
        if not root.is_dir():
            if directory in OPTIONAL_DIRS:
                continue
            raise FileNotFoundError(f"Required Laravel directory missing: {root}")
        for candidate in sorted(root.rglob("*")):
            if candidate.is_symlink():
                # Some packages carry only local symlinks; never follow them into ZIP.
                raise ValueError(f"Symlink in release is forbidden: {candidate}")
            if not candidate.is_file():
                continue
            relative = candidate.relative_to(BACKEND)
            if any(part in OMIT_COMPONENTS for part in relative.parts):
                continue
            if candidate.name in OMIT_NAMES or candidate.suffix in {".log", ".sqlite", ".sqlite3"}:
                continue
            if relative.parts[:2] == ("bootstrap", "cache"):
                continue
            if relative.parts[:2] == ("public", "storage"):
                continue
            yield "app/" + relative.as_posix(), candidate
    for leaf in FILES:
        path = BACKEND / leaf
        if not path.is_file():
            raise FileNotFoundError(f"Required Laravel file missing: {path}")
        yield "app/" + leaf, path


def cpanel_index(contents: bytes) -> bytes:
    content = contents.decode("utf-8")
    old = {
        "__DIR__.'/../storage/framework/maintenance.php'": "__DIR__.'/../app/storage/framework/maintenance.php'",
        "__DIR__.'/../vendor/autoload.php'": "__DIR__.'/../app/vendor/autoload.php'",
        "__DIR__.'/../bootstrap/app.php'": "__DIR__.'/../app/bootstrap/app.php'",
    }
    for before, after in old.items():
        if content.count(before) != 1:
            raise ValueError(f"Laravel front controller contract changed: {before}")
        content = content.replace(before, after)
    # Runtime public_path() must be the domain document root.
    marker = "$app = require_once __DIR__.'/../app/bootstrap/app.php';"
    if content.count(marker) != 1:
        raise ValueError("Cannot attach public_path to cPanel front controller.")
    content = content.replace(marker, marker+"\n$app->usePublicPath(__DIR__);")
    return content.encode("utf-8")


def write_zip(target: Path, commit: str) -> None:
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Exact 40-char source commit is required.")

    if not (BACKEND / "vendor/autoload.php").is_file():
        raise FileNotFoundError("Build missing Composer vendor/autoload.php.")
    if not (BACKEND / "public/build/manifest.json").is_file():
        raise FileNotFoundError("Build missing production Vite manifest.")

    installer = HERE.with_name("cpanel-one-time-install.php")
    if not installer.is_file():
        raise FileNotFoundError("Missing owner-gated one-time web installer.")

    pairs: dict[str, Path] = dict(entries())
    for path in sorted((BACKEND / "public").rglob("*")):
        if not path.is_file() or path.is_symlink() or path.name in OMIT_NAMES:
            continue
        relative = path.relative_to(BACKEND / "public")
        if "storage" in relative.parts or "hot" in relative.parts:
            continue
        pairs["public/" + relative.as_posix()] = path
    pairs["public/install.php"] = installer

    if len(pairs) > MAX_FILES:
        raise ValueError("Too many source files for shared hosting.")

    target.parent.mkdir(parents=True, exist_ok=True)
    metadata: dict[str, str] = {}
    total = 0

    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6, allowZip64=True) as archive:
        for archive_name, path in sorted(pairs.items()):
            safe_name(archive_name)
            if path.is_symlink() or not path.is_file():
                raise ValueError(f"Unsafe or missing file: {path}")
            if path.stat().st_size > MAX_SINGLE:
                raise ValueError(f"File too large for shared hosting: {path}")
            data = path.read_bytes()
            if archive_name == "public/index.php":
                data = cpanel_index(data)
            total += len(data)
            if total > MAX_TOTAL:
                raise ValueError("Uncompressed setup exceeds maximum total size.")
            metadata[archive_name] = digest(data)
            archive.writestr(archive_name, data)

        for directory in SKELETON:
            safe_name(directory)
            archive.writestr(directory, b"")

        # The archive manifest describes the exact source, not a fabricated release number.
        info = json.dumps({
            "product": "LARAVEL_AIWMWEB",
            "channel": "cpanel-full-setup",
            "source_sha": commit,
            "file_count": len(metadata),
            "files": metadata,
            "preserves": ["app/.env", "app/storage/**", "app/bootstrap/cache/**", "production database"],
            "document_root": "public/",
            "first_run": "/install.php",
            "future_updates": "/admin/system-updates",
        }, sort_keys=True, separators=(",", ":")).encode()
        archive.writestr("SETUP-MANIFEST.json", info)

        readme = (
            "AIWMWeb Laravel — cPanel FULL SETUP\n"
            f"SOURCE_SHA={commit}\n"
            "This package contains Laravel, production Composer vendor and Vite assets.\n"
            "Extract this ZIP into the folder containing your app/ and public/ dirs.\n"
            "In cPanel Domains point the domain document root at this folder's public/.\n"
            "Open https://your-domain/install.php, enter the private owner-code from\n"
            "app/storage/app/aiwm-install/owner-code.txt and finish the one-time setup.\n"
            "Existing .env, storage and user database remain untouched by extraction.\n"
            "For a new install create a blank MySQL DB/user in cPanel first.\n"
            "After setup: /admin/system-updates accepts only signed future update ZIPs.\n"
            "KEEP THE ONE-TIME SIGNING SECRET PRIVATE. Save it in GitHub Actions\n"
            "AIWM_UPDATES_SECRET_KEY. The public verification key is set on the server.\n"
            "Do not upload this setup ZIP into the admin update form.\n"
            "Do not extract if a backup of existing code and DB is missing.\n"
        ).encode("utf-8")
        archive.writestr("START-HERE.txt", readme)

    print(json.dumps({
        "artifact": str(target), "source_sha": commit,
        "files": len(metadata), "uncompressed_bytes": total,
        "compressed_bytes": target.stat().st_size, "sha256": digest(target.read_bytes())
    }, sort_keys=True))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--sha", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        write_zip(Path(args.output).resolve(), args.sha)
        return 0
    except Exception as exc:
        print(f"SETUP BUILD FAIL CLOSED: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
