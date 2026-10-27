## Summary

Describe the change and why it is needed.

## GitHub plan tracking

- Tracking issue / plan item: Refs #
- Authority / umbrella: #257
- Handoff leaf issue:
- Lane:
- [ ] The required change is recorded in a GitHub issue/plan item.
- [ ] Any newly discovered follow-up work has been added to GitHub before this PR is merged.

## Execution-unit discipline

- [ ] I checked active pull requests/issues for overlapping work.
- [ ] I am continuing the existing branch/PR if this unit was already claimed.
- [ ] This PR owns one independently reviewable execution unit.
- [ ] This PR does not mix Laravel and ASP.NET runtime changes.

## Dependencies

- Depends on:
- Unblocks:
- Existing active worker/PR collision checked: yes/no

## Risk

- [ ] No committed secret/local/generated file.
- [ ] No destructive migration.
- [ ] Destructive migration is explicitly approved with `migration:approved`.

## Validation

- [ ] Build/tests relevant to this change pass.
- [ ] Laravel AIWMWeb Repo Hygiene passes when applicable.
- [ ] Laravel AIWMWeb Acceptance passes when applicable.
- [ ] Laravel AIWMWeb Convergence Preflight passes when applicable.
- [ ] Laravel AIWMWeb Parity Reconciliation passes when applicable.
- [ ] Laravel AIWMWeb Cross-Domain Security passes when applicable.
- [ ] I checked the changed files for unrelated modifications.

## Handoff

Use a closing keyword only for the leaf execution issue, for example `Closes #123`. Keep umbrella references such as `Refs #257` non-closing.

## Team coordination

- [ ] This PR is scoped so another team member can review/merge it independently.
