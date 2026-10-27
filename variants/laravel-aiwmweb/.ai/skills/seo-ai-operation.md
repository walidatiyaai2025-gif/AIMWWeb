# Skill: SEO / AI Operation

## Trigger
SEO audits/findings, AI suggestions/generation/planner behavior, provider usage, approval/execution of AI-derived proposals.

## Procedure
1. Separate analysis/suggestion generation from authorization to mutate.
2. Scope provider configuration, usage/quota, audit, findings, suggestions, and jobs to tenant/site.
3. Persist deterministic request/context metadata needed for evidence and replay safety without leaking secrets.
4. Queue long-running work with tenant context and idempotency.
5. AI output is untrusted proposal data: validate shape, ownership, target, and allowed mutation before apply.
6. Respect approval requirements and never auto-apply because the model is confident.
7. Verify executed remote/local result and persist receipt/evidence.
8. Test provider failure, quota/unconfigured provider, duplicate/replay, foreign tenant/site, and approval path where applicable.

## Completion
A visible AI/SEO control is terminal only when its real runtime destination, failure semantics, and evidence are proven.
