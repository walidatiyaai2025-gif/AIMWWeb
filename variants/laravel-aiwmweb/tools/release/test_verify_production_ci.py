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
        {"name": name, "id": n + 1, "created_at": "2026-11-04T07:00:00Z",
         "event": "push", "head_sha": SHA, "head_branch": "main",
         "status": "completed", "conclusion": "success"}
        for n, name in enumerate(module.REQUIRED)
    ]}


class ReleaseGateTests(unittest.TestCase):
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
