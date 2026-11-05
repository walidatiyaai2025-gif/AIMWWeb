#!/usr/bin/env python3
"""Safely recover exact-SHA build outputs from a passed cPanel Setup ZIP.

Source files come from a separate checkout of the SAME immutable Git commit.
Only Composer vendor, compiled Vite assets and the packaged WordPress connector
are taken from the acceptance-tested Setup ZIP. File hashes are rechecked
against its manifest before the production update is digitally signed.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import stat
import sys
import zipfile


def prepare(source_zip: pathlib.Path, backend: pathlib.Path, sha: str, version: str) -> int:
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("BLOCKED: exact immutable commit required")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("BLOCKED: semantic version required")

    selected = ("app/vendor/", "public/build/", "public/downloads/aimw-connector.zip")
    extracted = 0
    total = 0
    with zipfile.ZipFile(source_zip) as archive:
        members = archive.infolist()
        names = [m.filename for m in members]
        if len(names) != len(set(names)):
            raise ValueError("BLOCKED: repeated ZIP member")
        if len(names) > 35000:
            raise ValueError("BLOCKED: too many members")
        if any(m.file_size > 30 * 1024 * 1024 for m in members):
            raise ValueError("BLOCKED: unbounded archive member")
        manifest = json.loads(archive.read("SETUP-MANIFEST.json"))
        if (manifest.get("source_sha") != sha or manifest.get("version") != version or
                manifest.get("product") != "LARAVEL_AIWMWEB"):
            raise ValueError("BLOCKED: cPanel Setup provenance mismatch")
        hashes = manifest.get("files")
        if not isinstance(hashes, dict):
            raise ValueError("BLOCKED: invalid manifest hashes")

        for info in members:
            raw = info.filename
            parts = pathlib.PurePosixPath(raw).parts
            if (not raw or raw.startswith("/") or "\\" in raw or ".." in parts or
                    "\x00" in raw or any(p == "." for p in parts)):
                raise ValueError("BLOCKED: archive path traversal")
            mode = (info.external_attr >> 16)
            if stat.S_IFMT(mode) == stat.S_IFLNK:
                raise ValueError("BLOCKED: archive symlink")
            if info.is_dir():
                continue
            total += info.file_size
            if total > 420 * 1024 * 1024:
                raise ValueError("BLOCKED: oversized Setup ZIP")
            if raw not in hashes:
                if raw in ("SETUP-MANIFEST.json", "BUILD-INFO.json", "RELEASE-NOTES.txt", "START-HERE.txt"):
                    continue
                raise ValueError("BLOCKED: unexpected payload in Setup ZIP")
            if not any(raw.startswith(prefix) if prefix.endswith("/") else raw == prefix for prefix in selected):
                continue
            payload = archive.read(info)
            expected = hashes[raw]
            if not isinstance(expected, str) or not re.fullmatch(r"[a-f0-9]{64}", expected):
                raise ValueError("BLOCKED: invalid payload digest")
            if hashlib.sha256(payload).hexdigest() != expected:
                raise ValueError("BLOCKED: cPanel Setup payload mismatch")

            # The deployment-side project is checked out at the same SHA.
            if raw.startswith("app/vendor/"):
                dest = backend / raw.removeprefix("app/")
            else:
                dest = backend / raw
            root = backend.resolve()
            if not dest.resolve().is_relative_to(root):
                raise ValueError("BLOCKED: path escapes checked-out Laravel backend")
            dest.parent.mkdir(parents=True, exist_ok=True)
            if dest.is_symlink():
                raise ValueError("BLOCKED: refusing symlink destination")
            dest.write_bytes(payload)
            extracted += 1

    for required in (
        backend / "vendor/autoload.php",
        backend / "public/build/manifest.json",
        backend / "public/downloads/aimw-connector.zip",
    ):
        if not required.is_file():
            raise ValueError("BLOCKED: compiled runtime or WordPress plugin missing")
    if extracted < 3:
        raise ValueError("BLOCKED: no production dependencies recovered")
    return extracted


if __name__ == "__main__":
    if len(sys.argv) != 5:
        sys.exit("Usage: prepare_signed_deploy.py SETUP_ZIP BACKEND EXACT_SHA VERSION")
    try:
        count = prepare(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), sys.argv[3], sys.argv[4])
        print("Verified exact-source Setup output; staged production files:", count)
    except (ValueError, OSError, KeyError, zipfile.BadZipFile, json.JSONDecodeError) as error:
        sys.exit(str(error))
