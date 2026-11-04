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
    # Opening the domain on a clean installation must enter the wizard
    # automatically, not show a framework starter/500 before setup.
    entrypoint = """$setupRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ((! is_file(__DIR__.'/../app/.env') ||
    is_file(__DIR__.'/../app/storage/app/aiwm-install/fresh-migration-pending.lock')) &&
    is_file(__DIR__.'/install.php')) {
    if ($setupRequestPath === '/install') {
        require __DIR__.'/install.php';
        exit;
    }
    if ($setupRequestPath === '/' || $setupRequestPath === '/setup') {
        header('Location: /install', true, 302);
        exit;
    }
}

"""
    content = content.replace("define('LARAVEL_START', microtime(true));",
        "define('LARAVEL_START', microtime(true));\n\n" + entrypoint)
    return content.encode("utf-8")


def package_wordpress_connector(commit: str) -> Path:
    """Build an installable WordPress plugin ZIP from this exact repository source.

    Both clean Setup ZIP and signed Admin Update ZIP must deliver the same plugin
    artifact. Never fetch a moving GitHub branch from a production web request.
    """
    plugin = VARIANT / "connector" / "aimw-connector"
    main_file = plugin / "aimw-connector.php"
    if not main_file.is_file() or main_file.is_symlink():
        raise FileNotFoundError("Canonical WordPress Connector plugin is missing.")
    if b"Plugin Name:" not in main_file.read_bytes():
        raise ValueError("WordPress Connector lacks the required plugin header.")

    target = BACKEND / "public" / "downloads" / "aimw-connector.zip"
    target.parent.mkdir(parents=True, exist_ok=True)
    source_files = sorted(plugin.rglob("*"))
    files = []
    total = 0
    for item in source_files:
        if item.is_symlink():
            raise ValueError(f"Connector source symlink is forbidden: {item}")
        if item.is_dir():
            continue
        if not item.is_file():
            raise ValueError(f"Connector source must be a regular file: {item}")
        name = "aimw-connector/" + item.relative_to(plugin).as_posix()
        safe_name(name)
        size = item.stat().st_size
        total += size
        if size > 10 * 1024 * 1024 or total > 25 * 1024 * 1024:
            raise ValueError("Connector plugin exceeded package size limits.")
        files.append((name, item))
    if len(files) < 1 or len(files) > 1000:
        raise ValueError("Invalid Connector file count.")

    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for name, item in files:
            archive.write(item, arcname=name)
        archive.writestr(
            "aimw-connector/AIMW-CONNECTOR-MANIFEST.json",
            json.dumps({
                "product": "AIMW Connector",
                "source_sha": commit,
                "install_path": "wp-content/plugins/aimw-connector",
            }, sort_keys=True, separators=(",", ":")).encode("utf-8"),
        )
    with zipfile.ZipFile(target) as archive:
        names = archive.namelist()
        if "aimw-connector/aimw-connector.php" not in names or archive.testzip() is not None:
            raise ValueError("Generated WordPress Connector ZIP failed verification.")
    return target


def write_zip(target: Path, commit: str, version: str) -> None:
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Exact 40-char source commit is required.")

    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("Setup version must be MAJOR.MINOR.PATCH.")
    if not (BACKEND / "vendor/autoload.php").is_file():
        raise FileNotFoundError("Build missing Composer vendor/autoload.php.")
    if not (BACKEND / "public/build/manifest.json").is_file():
        raise FileNotFoundError("Build missing production Vite manifest.")

    installer = HERE.with_name("cpanel-one-time-install.php")
    if not installer.is_file():
        raise FileNotFoundError("Missing owner-gated one-time web installer.")

    connector_zip = package_wordpress_connector(commit)
    assert connector_zip.is_file()
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
            "version": version,
            "file_count": len(metadata),
            "files": metadata,
            "preserves": ["app/.env", "app/storage/**", "app/bootstrap/cache/**", "production database"],
            "document_root": "public/",
            "first_run": "/install",
            "future_updates": "/admin/system-updates",
            "wordpress_connector_download": "/downloads/aimw-connector.zip",
        }, sort_keys=True, separators=(",", ":")).encode()
        archive.writestr("SETUP-MANIFEST.json", info)
        archive.writestr("BUILD-INFO.json", json.dumps({
            "product": "LARAVEL_AIWMWEB", "version": version,
            "source_sha": commit, "runtime": "Laravel",
            "php_minimum": "8.3", "database": "MySQL/MariaDB",
            "deployment": "cPanel app/ and public/ layout",
            "release_acceptance": "Must pass external signing, fresh install, upgrade and required CI gates",
        }, sort_keys=True, indent=2).encode("utf-8"))
        archive.writestr("RELEASE-NOTES.txt", (
            "AIWMWeb Laravel FOODEX-standard installation candidate\n"
            f"Version: {version}\nSource: {commit}\n"
            "15-stage ownership-gated browser setup with tenant/RBAC initialization.\n"
            "Admin updates require verified signed ZIPs and separately validated backup/recovery.\n"
            "A successful ZIP build by itself is NOT a production release approval.\n"
        ).encode("utf-8"))

        readme = (
            "AIWMWeb Laravel — cPanel FULL CLEAN INSTALL\n"
            f"SOURCE_SHA={commit}\n"
            f"VERSION={version}\n"
            "Setup ZIP includes production Laravel vendor and built frontend.\n"
            "For a BRAND-NEW installation use an EMPTY MySQL database and a\n"
            "CLEAN folder; never overwrite existing production .env or data.\n"
            "1. Extract this ZIP in the new folder, creating app/ and public/.\n"
            "2. In cPanel Domains point document root to this folder's public/.\n"
            "3. In cPanel MySQL Databases create one empty database and user.\n"
            "4. Open https://your-domain/install over HTTPS (legacy /install.php supported).\n"
            "5. Complete 15 stages: ownership, PHP, permissions, domain, MySQL,\n"
            "   platform, workspace, admin, env, storage, queue, mail, Cron,\n"
            "   migrations and health check.\n"
            "6. The wizard runs all migrations, creates the first tenant Owner\n"
            "   AND platform administrator, validates database/frontend,\n"
            "   closes the installer and offers the login page.\n"
            "After login the platform is ready for core functions; external\n"
            "AI keys, WordPress connections and cron/queue hosting services\n"
            "still need REAL credentials/provisioning; never fabricate them.\n"
            "Download the WordPress connector from /downloads/aimw-connector.zip.\n"
            "In WordPress: Plugins > Add New > Upload Plugin > Install Now.\n"
            "The Settings > System Updates page accepts signed ZIPs only.\n"
            "Signing remains disabled until a NEW trusted private CI key and\n"
            "its matching public verification key are configured. DO NOT USE\n"
            "any previously screenshot-shared/compromised signing key.\n"
            "Do NOT upload this full Setup ZIP into the admin update form.\n"
            "Never extract over an existing database/site without backups.\n"
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
    parser.add_argument("--version", default="0.0.0")
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        write_zip(Path(args.output).resolve(), args.sha, args.version)
        return 0
    except Exception as exc:
        print(f"SETUP BUILD FAIL CLOSED: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
