#!/usr/bin/env python3
"""Release and private-Cron transport boundaries for no-SSH cPanel delivery."""
from pathlib import Path
import unittest

VARIANT = Path(__file__).resolve().parents[2]
REPO = VARIANT.parents[1]
CRON = VARIANT / "backend" / "aiwm-cpanel-https-pull.php"
WORKFLOW = REPO / ".github/workflows/laravel-aiwmweb-cpanel-https-release.yml"
SSH_WORKFLOW = REPO / ".github/workflows/laravel-aiwmweb-cpanel-ssh-deploy.yml"
PACKAGER = VARIANT / "tools/release/build-signed-update.php"
SETUP = VARIANT / "tools/release/build-cpanel-setup.py"
UPDATER = VARIANT / "backend/app/Platform/SystemUpdateService.php"
CONFIG = VARIANT / "backend/config/system_updates.php"


class HttpsPullReleaseTests(unittest.TestCase):
    def test_cron_entry_is_cli_only_and_explicitly_opted_in(self):
        text = CRON.read_text(encoding="utf8")
        for anchor in (
            "PHP_SAPI !== 'cli'", "isset($_SERVER['REQUEST_METHOD'])",
            "AIWM_AUTO_UPDATES_ENABLED", "system_updates.enabled",
            "system_updates.auto_pull_enabled", "aiwm-install/installed.lock",
            "LOCK_EX | LOCK_NB", "aiwm.walidatiya.com",
            "system_updates.public_root", "SystemUpdateService::class",
            "$updates->latest()", "$updates->install(0)",
            "runtime:health", "--ready",
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

    def test_every_release_contains_same_private_cron_script_and_blocks_public_install(self):
        self.assertIn('"aiwm-cpanel-https-pull.php"', SETUP.read_text(encoding="utf8"))
        self.assertIn("'aiwm-cpanel-https-pull.php'", PACKAGER.read_text(encoding="utf8"))
        self.assertIn("'aiwm-cpanel-https-pull.php'", UPDATER.read_text(encoding="utf8"))
        config = CONFIG.read_text(encoding="utf8")
        self.assertIn("'auto_pull_enabled'", config)
        self.assertIn("AIWM_AUTO_UPDATES_ENABLED", config)
        self.assertIn("false", config)


if __name__ == "__main__":
    unittest.main()
