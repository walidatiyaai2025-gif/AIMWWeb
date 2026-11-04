#!/usr/bin/env python3
"""Deterministic release-preflight adversarial acceptance, no network needed."""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "verify_production_ci", Path(__file__).with_name("verify-production-ci.py")
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
SHA = "a" * 40


def main(sha=SHA):
    return {"object": {"sha": sha}}


def sample():
    return {"workflow_runs": [
        {"name": name, "path": module.REQUIRED_WORKFLOWS[name], "id": n + 1, "created_at": "2026-11-04T07:00:00Z",
         "event": "push", "head_sha": SHA, "head_branch": "main",
         "status": "completed", "conclusion": "success"}
        for n, name in enumerate(module.REQUIRED)
    ]}


class ReleaseGateTests(unittest.TestCase):
    def test_release_workflow_rechecks_ci_at_publication(self):
        path = Path(__file__).resolve().parents[4] / ".github/workflows/laravel-aiwmweb-cpanel-package.yml"
        workflow = path.read_text(encoding="utf-8")
        publish = workflow.split("      - name: Publish verified signed artifacts as one GitHub Release", 1)[1]
        before_release = publish.split('          gh release create "v${BUILD_VERSION}"', 1)[0]
        self.assertIn("/tmp/aiwm-publish-main.json", before_release)
        self.assertIn("/tmp/aiwm-publish-gates.json", before_release)
        self.assertIn("verify-production-ci.py", before_release)
        self.assertIn("event=push", before_release)
        self.assertIn('"$SOURCE_SHA"', before_release)

    def test_exact_current_main_and_all_push_gates_pass(self):
        module.verify(main(), sample(), SHA)

    def test_main_moves_before_signing(self):
        with self.assertRaisesRegex(ValueError, "main moved"):
            module.verify(main("b" * 40), sample(), SHA)

    def test_missing_required_check_is_blocked(self):
        data = sample()
        data["workflow_runs"].pop()
        with self.assertRaisesRegex(ValueError, "absent"):
            module.verify(main(), data, SHA)

    def test_manual_or_pr_success_cannot_replace_main_push(self):
        for event in ("pull_request", "workflow_dispatch"):
            data = sample()
            data["workflow_runs"][0]["event"] = event
            with self.assertRaisesRegex(ValueError, "absent"):
                module.verify(main(), data, SHA)

    def test_workflow_name_spoof_does_not_satisfy_required_ci(self):
        data = sample()
        data["workflow_runs"][0]["path"] = ".github/workflows/unrelated-build.yml"
        with self.assertRaisesRegex(ValueError, "absent"):
            module.verify(main(), data, SHA)

    def test_wrong_commit_or_branch_does_not_count(self):
        for key, value in (("head_sha", "b" * 40), ("head_branch", "feature")):
            data = sample()
            data["workflow_runs"][0][key] = value
            with self.assertRaisesRegex(ValueError, "absent"):
                module.verify(main(), data, SHA)

    def test_newer_failed_run_overrides_old_green(self):
        data = sample()
        newer = dict(data["workflow_runs"][0], id=1000,
                     created_at="2026-11-04T07:01:00Z", conclusion="failure")
        data["workflow_runs"].append(newer)
        with self.assertRaisesRegex(ValueError, "not green"):
            module.verify(main(), data, SHA)

    def test_failed_rerun_of_older_run_invalidates_newer_green_run(self):
        data = sample()
        # An older run may be rerun after another successful run. GitHub
        # preserves its original created_at and run ID, but updates updated_at.
        older = dict(data["workflow_runs"][0], id=0,
                     created_at="2026-11-04T06:00:00Z",
                     updated_at="2026-11-04T09:00:00Z",
                     conclusion="failure", run_attempt=2)
        data["workflow_runs"].append(older)
        with self.assertRaisesRegex(ValueError, "not green"):
            module.verify(main(), data, SHA)

    def test_successful_old_rerun_does_not_mask_later_failed_run(self):
        data = sample()
        data["workflow_runs"][0]["updated_at"] = "2026-11-04T09:00:00Z"
        data["workflow_runs"][0]["conclusion"] = "failure"
        earlier_success = dict(data["workflow_runs"][0], id=0,
                               updated_at="2026-11-04T08:00:00Z",
                               conclusion="success")
        data["workflow_runs"].append(earlier_success)
        with self.assertRaisesRegex(ValueError, "not green"):
            module.verify(main(), data, SHA)

    def test_in_progress_run_never_counts(self):
        data = sample()
        data["workflow_runs"][0]["status"] = "in_progress"
        data["workflow_runs"][0]["conclusion"] = None
        with self.assertRaisesRegex(ValueError, "not green"):
            module.verify(main(), data, SHA)

    def test_malformed_or_missing_payload_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "unavailable"):
            module.verify(main(), {}, SHA)
        with self.assertRaisesRegex(ValueError, "invalid"):
            module.verify(main(), sample(), "not-a-commit")


if __name__ == "__main__":
    unittest.main()
