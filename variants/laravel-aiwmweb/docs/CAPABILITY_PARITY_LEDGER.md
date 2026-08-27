# Capability Parity Ledger

Authority: AIMWWeb Issue #257

This ledger is generated from the **current ASP.NET AIMWWeb source** by `tools/capability_census.py`. The JSON ledger is canonical at operation granularity; this Markdown file is the human summary.

Unknown work is `PENDING`. Terminal states are only `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, and `BLOCKED`. No operation may be removed from the denominator to improve the score.

## Live parity totals

- TOTAL_OPERATIONS: **931**
- PORTED: **0**
- ADAPTED: **0**
- PENDING: **931**
- BLOCKED: **0**
- VERIFIED_UNAVAILABLE_EXTERNAL: **0**
- CONNECTOR_REQUIRED: **16**
- NATIVE_REST: **449**
- LARAVEL_ONLY: **116**
- DEAD_FUNCTION_FINDINGS_REQUIRING_REVIEW: **0**

Completion % = `(PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL + BLOCKED) / TOTAL_OPERATIONS × 100`. `BLOCKED` is terminal accounting only when the blocker and evidence are explicit; it is not a success claim.

## Denominator composition

| Kind | Operations |
| --- | ---: |
| `api` | 31 |
| `background_job` | 21 |
| `route` | 84 |
| `service` | 349 |
| `visible_control` | 446 |

## Domain composition

| Domain | Operations |
| --- | ---: |
| `ai` | 92 |
| `approvals` | 25 |
| `automation` | 59 |
| `backup` | 14 |
| `billing` | 178 |
| `comments` | 8 |
| `content` | 164 |
| `email` | 82 |
| `identity` | 7 |
| `media` | 15 |
| `operations` | 5 |
| `platform` | 18 |
| `reports` | 1 |
| `seo` | 24 |
| `settings` | 1 |
| `sites` | 12 |
| `sync` | 213 |
| `taxonomy` | 13 |

## Canonical operation records

See `capability-parity-ledger.json`. Each row records stable `operation_id`, domain, route/screen, visible control, current source, service, persistence, background job, mutation/external/approval/verification classification, Laravel destination, Native WP REST vs Connector path, tenant ownership, risk, migration state, acceptance test, and evidence.

## Dead / fake function census

See `dead-function-census.json`. High-confidence source patterns are recorded as findings for explicit review; the Laravel release gate fails if forbidden fake-success patterns appear in the new variant production source.
