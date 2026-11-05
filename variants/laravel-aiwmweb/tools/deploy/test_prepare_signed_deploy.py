#!/usr/bin/env python3
"""Adversarial tests for untrusted cPanel release payload extraction."""
import hashlib
import importlib.util
import json
from pathlib import Path
import stat
import tempfile
import unittest
import zipfile

script = Path(__file__).with_name("prepare_signed_deploy.py")
spec = importlib.util.spec_from_file_location("prepare_signed_deploy", script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
SHA = "a" * 40
VERSION = "1.0.101"


def build(path, bad_hash=False, extra=None, link=False):
    payloads = {
        "app/vendor/autoload.php": b"<?php // composer\n",
        "public/build/manifest.json": b'{"resources/js/app.tsx": {"file": "assets/app.js"}}',
        "public/downloads/aimw-connector.zip": b"plugin archive",
    }
    if extra:
        payloads[extra] = b"unexpected file"
    hashes = {name: hashlib.sha256(raw).hexdigest() for name, raw in payloads.items()}
    if bad_hash:
        hashes["app/vendor/autoload.php"] = "b" * 64
    with zipfile.ZipFile(path, "w") as archive:
        for name, raw in payloads.items():
            info = zipfile.ZipInfo(name)
            info.create_system = 3
            info.external_attr = (stat.S_IFLNK if link and name == "app/vendor/autoload.php"
                                  else stat.S_IFREG) << 16
            archive.writestr(info, raw)
        archive.writestr("SETUP-MANIFEST.json", json.dumps({
            "product": "LARAVEL_AIWMWEB", "source_sha": SHA,
            "version": VERSION, "files": hashes,
        }))


class SignedDeployPreparation(unittest.TestCase):
    def run_case(self, bad_hash=False, extra=None, link=False, sha=SHA, version=VERSION):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            z = root / "setup.zip"
            backend = root / "backend"
            backend.mkdir()
            build(z, bad_hash=bad_hash, extra=extra, link=link)
            return module.prepare(z, backend, sha, version)

    def test_success_only_extracts_hashed_production_assets(self):
        self.assertEqual(self.run_case(), 3)

    def test_bad_hash_fails(self):
        with self.assertRaisesRegex(ValueError, "mismatch"):
            self.run_case(bad_hash=True)

    def test_traversal_member_fails_even_outside_selected_directories(self):
        with self.assertRaisesRegex(ValueError, "path traversal"):
            self.run_case(extra="../unsafe.php")

    def test_archive_symlink_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.run_case(link=True)

    def test_wrong_sha_and_version_fail_closed(self):
        with self.assertRaisesRegex(ValueError, "provenance"):
            self.run_case(sha="b" * 40)
        with self.assertRaisesRegex(ValueError, "provenance"):
            self.run_case(version="1.0.102")


if __name__ == "__main__":
    unittest.main()
