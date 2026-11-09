#!/usr/bin/env python3
"""Release and private-Cron transport boundaries for no-SSH cPanel delivery."""
from pathlib import Path
import importlib.util
import json
import hashlib
import tempfile
import zipfile
import unittest
import subprocess
from unittest.mock import patch

VARIANT = Path(__file__).resolve().parents[2]
REPO = VARIANT.parents[1]
CRON = VARIANT / "backend" / "aiwm-cpanel-https-pull.php"
WORKFLOW = REPO / ".github/workflows/laravel-aiwmweb-cpanel-https-release.yml"
SSH_WORKFLOW = REPO / ".github/workflows/laravel-aiwmweb-cpanel-ssh-deploy.yml"
STAGING_WORKFLOW = REPO / ".github/workflows/laravel-aiwmweb-cpanel-signed-candidate.yml"
PACKAGER = VARIANT / "tools/release/build-signed-update.php"
SETUP = VARIANT / "tools/release/build-cpanel-setup.py"
UPDATER = VARIANT / "backend/app/Platform/SystemUpdateService.php"
CONFIG = VARIANT / "backend/config/system_updates.php"
COMPAT = VARIANT / "tools/release/verify-signed-update-compat.py"
PRODUCTION_WORKFLOW = WORKFLOW


class HttpsPullReleaseTests(unittest.TestCase):
    def test_actual_setup_zip_includes_bounded_private_worker_entrypoint(self):
        source = (VARIANT / "backend/aiwm-cpanel-cron.php").read_text(encoding="utf8")
        packager = SETUP.read_text(encoding="utf8")
        installer = (VARIANT / "tools/release/cpanel-one-time-install.php").read_text(encoding="utf8")
        acceptance = (VARIANT / "tools/release/smoke-cpanel-wizard.php").read_text(encoding="utf8")
        for expected in ("PHP_SAPI !== 'cli'", "LOCK_EX | LOCK_NB", "setTimeout(110)", "cpanel:tick", "memory_limit=192M"):
            self.assertIn(expected, source)
        self.assertIn('"aiwm-cpanel-cron.php"', packager)
        self.assertIn("Actual Setup ZIP is missing the private bounded Cron entrypoint", acceptance)
        for expected in ("'QUEUE_CONNECTION' => 'database'", "'CACHE_STORE' => 'database'", "'SESSION_DRIVER' => 'database'", "'CPANEL_CRON_ENABLED' => 'false'"):
            self.assertIn(expected, installer)
        self.assertNotIn("queue:work --daemon", source)
        self.assertNotIn("schedule:work", source)

    def test_cron_entry_is_cli_only_and_explicitly_opted_in(self):
        text = CRON.read_text(encoding="utf8")
        for anchor in (
            "PHP_SAPI !== 'cli'", "isset($_SERVER['REQUEST_METHOD'])",
            "AIWM_AUTO_UPDATES_ENABLED", "system_updates.enabled",
            "system_updates.auto_pull_enabled", "aiwm-install/installed.lock",
            "LOCK_EX | LOCK_NB", "aiwm.walidatiya.com",
            "system_updates.public_root", "SystemUpdateService::class",
            "$updates->latest()", "$updates->install(0)",
            "health/live", "SELECT 1 AS ok", "build/manifest.json",
        ):
            self.assertIn(anchor, text)
        self.assertNotIn("shell_exec(", text)
        self.assertNotIn("exec('git ", text)
        self.assertNotIn("$_GET", text)

    def test_no_ssh_release_job_is_separate_and_immutably_gated(self):
        workflow = WORKFLOW.read_text(encoding="utf8")
        for anchor in (
            "AIWM_HTTPS_CD_ENABLED == 'true'",
            "event == 'push'", "head_branch == 'main'",
            "head_repository.full_name == github.repository",
            "verify-production-ci.py", "workflow_run",
            "AIWM_UPDATES_SIGNER_ROTATED", "AIWM_UPDATES_SECRET_KEY",
            "AIWM_UPDATES_TRUSTED_PUBLIC_KEY", "AIWM_CPANEL_ACCEPTANCE_VERIFIED",
            "prepare_signed_deploy.py", "build-signed-update.php",
            "sodium_crypto_sign_verify_detached", "gh release create",
            "AIWMWeb-Setup-v", "AIWMWeb-Laravel-release.sig",
            "LIVE_DEPLOYMENT_NOT_VERIFIED",
        ):
            # Production signer rotation variable uses a local alias,
            # but the real secret input must stay the rotated one.
            if anchor == "AIWM_UPDATES_SIGNER_ROTATED":
                self.assertIn("secrets.AIWM_UPDATES_SIGNER_ROTATED", workflow)
            elif anchor == "AIWM_UPDATES_TRUSTED_PUBLIC_KEY":
                self.assertIn("secrets.AIWM_UPDATES_TRUSTED_PUBLIC_KEY", workflow)
            else:
                self.assertIn(anchor, workflow)
        for forbidden in ("ssh ", "scp ", "rsync ", "StrictHostKeyChecking=no", "AIWM_DEPLOY_SSH_PRIVATE_KEY"):
            self.assertNotIn(forbidden, workflow)
        self.assertIn("AIWM_SSH_CD_ENABLED == 'true'", SSH_WORKFLOW.read_text(encoding="utf8"))

    def test_signed_staging_candidate_is_manual_only_and_never_publishes(self):
        workflow = STAGING_WORKFLOW.read_text(encoding="utf8")
        for anchor in (
            "workflow_dispatch:", "github.ref == 'refs/heads/main'",
            "verify-production-ci.py", "AIWM_MIN_SUPPORTED_VERSION",
            "secrets.AIWM_UPDATES_SECRET_KEY",
            "secrets.AIWM_UPDATES_TRUSTED_PUBLIC_KEY",
            "secrets.AIWM_UPDATES_SIGNER_ROTATED",
            "prepare_signed_deploy.py", "build-signed-update.php",
            "sodium_crypto_sign_verify_detached",
            "hash_file(\"sha256\",", "actions/upload-artifact@v4",
            "SIGNED_STAGING_CANDIDATE", "NOT_PUBLISHED. NOT_DEPLOYED.",
        ):
            self.assertIn(anchor, workflow)
        for forbidden in (
            "workflow_run:", "gh release create", "AIWM_HTTPS_CD_ENABLED",
            "AIWM_CPANEL_ACCEPTANCE_VERIFIED", "ssh ", "scp ", "rsync ",
            "StrictHostKeyChecking=no", "AIWM_DEPLOY_SSH_PRIVATE_KEY",
        ):
            self.assertNotIn(forbidden, workflow)

    def test_signed_update_build_excludes_compile_only_private_named_sources(self):
        package = PACKAGER.read_text(encoding="utf8")
        self.assertIn("str_starts_with($name, 'resources/js/') && str_contains($name, 'private')", package)
        self.assertIn("unset($files[$name]);", package)
        self.assertIn("Private Laravel resource cannot be safely updated", package)
        for workflow in (WORKFLOW, STAGING_WORKFLOW):
            contents = workflow.read_text(encoding="utf8")
            self.assertIn("verify-signed-update-compat.py", contents)

    def test_signed_update_legacy_compat_gate_accepts_valid_zip_and_rejects_private_paths(self):
        spec = importlib.util.spec_from_file_location("aiwm_signed_compat", COMPAT)
        self.assertIsNotNone(spec)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            update = parent / "update.zip"
            manifest_file = parent / "release.json"
            safe = "public/build/assets/site.js"
            data = b"console.log('compiled');"
            with zipfile.ZipFile(update, "w") as package:
                package.writestr(safe, data)
            manifest = {
                "product": "LARAVEL_AIWMWEB",
                "minimum_supported_version": "0.0.0",
                "files": {safe: hashlib.sha256(data).hexdigest()},
                "schema_migrations": [],
                "migration_policy": "none",
                "sha256": hashlib.sha256(update.read_bytes()).hexdigest(),
            }
            manifest_file.write_text(json.dumps(manifest), encoding="utf8")
            self.assertEqual(module.check(manifest_file, update)[0], 1)
            for path in (
                "resources/js/global-pages-private-selected-control.ts",
                "resources/js/__tests__/global-posts-private-selected-control.test.ts",
                "app/.env", "storage/logs/secret.log",
                "public/downloads/aimw-connector.zip", "aiwm-cpanel-https-pull.php",
            ):
                with self.subTest(path=path):
                    with self.assertRaises(ValueError):
                        module.validate_path(path, legacy=True)
            module.validate_path("public/build/assets/site.js", legacy=True)

    def test_every_release_contains_same_private_cron_script_and_blocks_public_install(self):
        self.assertIn('"aiwm-cpanel-https-pull.php"', SETUP.read_text(encoding="utf8"))
        self.assertIn("'aiwm-cpanel-https-pull.php'", PACKAGER.read_text(encoding="utf8"))
        signed = PACKAGER.read_text(encoding="utf8")
        self.assertIn("version_compare($minimumSupportedVersion, '1.0.0', '<')", signed)
        self.assertIn("unset($files['public/downloads/aimw-connector.zip'], $files['aiwm-cpanel-https-pull.php'])", signed)
        self.assertIn("minimum_supported_version", signed)
        self.assertIn("'aiwm-cpanel-https-pull.php'", UPDATER.read_text(encoding="utf8"))
        config = CONFIG.read_text(encoding="utf8")
        self.assertIn("'auto_pull_enabled'", config)
        self.assertIn("AIWM_AUTO_UPDATES_ENABLED", config)
        self.assertIn("false", config)


    def test_release_packagers_fail_closed_on_composer_advisories(self):
        setup = SETUP.read_text(encoding="utf8")
        signed = PACKAGER.read_text(encoding="utf8")
        self.assertIn("require_secure_composer_lock()", setup)
        self.assertLess(setup.index("    require_secure_composer_lock()"),
                        setup.index('    if not (BACKEND / "vendor/autoload.php").is_file():'))
        self.assertIn("proc_open(", signed)
        self.assertIn("['composer', 'audit', '--locked', '--no-interaction']", signed)
        self.assertLess(signed.index("$securityProcess = @proc_open("),
                        signed.index("$output = $argv[3];"))

        spec = importlib.util.spec_from_file_location("aiwm_setup_guard", SETUP)
        self.assertIsNotNone(spec)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)

        with tempfile.TemporaryDirectory() as tmp:
            backend = Path(tmp)
            (backend / "composer.lock").write_text("{}", encoding="utf8")
            with patch.object(module, "BACKEND", backend):
                vulnerable = subprocess.CompletedProcess([], 1, "", "seven vulnerable advisories")
                with patch.object(module.subprocess, "run", return_value=vulnerable) as run:
                    with self.assertRaisesRegex(RuntimeError, "Composer locked-security audit failed"):
                        module.require_secure_composer_lock()
                    run.assert_called_once()
                    args, kwargs = run.call_args
                    self.assertEqual(args[0], ["composer", "audit", "--locked", "--no-interaction"])
                    self.assertEqual(kwargs["cwd"], backend)
                    self.assertFalse(kwargs["check"])

                with patch.object(module.subprocess, "run", side_effect=OSError("composer missing")):
                    with self.assertRaisesRegex(RuntimeError, "was not completed"):
                        module.require_secure_composer_lock()

                healthy = subprocess.CompletedProcess([], 0, "No advisories", "")
                with patch.object(module.subprocess, "run", return_value=healthy):
                    module.require_secure_composer_lock()


if __name__ == "__main__":
    unittest.main()
