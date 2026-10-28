# Laravel AIWMWeb 931-Operation Parity Reconciliation

Authority: AIMWWeb Issue #257. Evidence-only reconciliation; no feature completion is inferred from file, class, route, or UI presence alone.

## Exact totals

| Metric | Value |
|---|---:|
| TOTAL | 931 |
| PORTED | 0 |
| ADAPTED | 877 |
| PENDING | 54 |
| BLOCKED | 0 |
| VERIFIED_UNAVAILABLE_EXTERNAL | 0 |
| TERMINAL | 877 |
| OVERALL_PARITY_PERCENT | 94.20% |

Terminal progress is strictly `PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL`; `BLOCKED` is not progress.

## Visible controls

- Total: **446**
- Terminal: **393**
- Pending: **53**
- Blocked: **0**
- Parity: **88.12%**

## By domain

| Domain | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| ai | 92 | 0 | 92 | 0 | 0 | 0 | 92 | 100.00% |
| approvals | 25 | 0 | 25 | 0 | 0 | 0 | 25 | 100.00% |
| automation | 59 | 0 | 59 | 0 | 0 | 0 | 59 | 100.00% |
| backup | 14 | 0 | 14 | 0 | 0 | 0 | 14 | 100.00% |
| billing | 178 | 0 | 178 | 0 | 0 | 0 | 178 | 100.00% |
| comments | 8 | 0 | 8 | 0 | 0 | 0 | 8 | 100.00% |
| content | 164 | 0 | 157 | 7 | 0 | 0 | 157 | 95.73% |
| email | 82 | 0 | 81 | 1 | 0 | 0 | 81 | 98.78% |
| identity | 7 | 0 | 7 | 0 | 0 | 0 | 7 | 100.00% |
| media | 15 | 0 | 13 | 2 | 0 | 0 | 13 | 86.67% |
| operations | 5 | 0 | 5 | 0 | 0 | 0 | 5 | 100.00% |
| platform | 18 | 0 | 18 | 0 | 0 | 0 | 18 | 100.00% |
| reports | 1 | 0 | 1 | 0 | 0 | 0 | 1 | 100.00% |
| seo | 24 | 0 | 24 | 0 | 0 | 0 | 24 | 100.00% |
| settings | 1 | 0 | 1 | 0 | 0 | 0 | 1 | 100.00% |
| sites | 12 | 0 | 12 | 0 | 0 | 0 | 12 | 100.00% |
| sync | 213 | 0 | 169 | 44 | 0 | 0 | 169 | 79.34% |
| taxonomy | 13 | 0 | 13 | 0 | 0 | 0 | 13 | 100.00% |

## By kind

| Kind | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| api | 31 | 0 | 31 | 0 | 0 | 0 | 31 | 100.00% |
| background_job | 21 | 0 | 21 | 0 | 0 | 0 | 21 | 100.00% |
| route | 84 | 0 | 83 | 1 | 0 | 0 | 83 | 98.81% |
| service | 349 | 0 | 349 | 0 | 0 | 0 | 349 | 100.00% |
| visible_control | 446 | 0 | 393 | 53 | 0 | 0 | 393 | 88.12% |

## Reproducibility / guard results

- Denominator exactly 931: **PASS**
- Duplicate operation IDs: **0**
- Allowed statuses only: **PASS**
- Totals reconcile: **PASS**
- Evidence references exist for terminal code rows: **PASS**
- Explicit route contracts: **44**
- Focused visible-control contracts: **393**
- Focused service contracts: **32**
- Focused background-job contracts: **7**
- Unpushed countable sources: **0**
- Frontend placeholder terminals: **0**
- BLOCKED excluded from progress: **PASS**

## Exact remaining PENDING operation IDs by domain

### ai (0)

- None

### approvals (0)

- None

### automation (0)

- None

### backup (0)

- None

### billing (0)

- None

### comments (0)

- None

### content (7)

- `AIMW-CONT-2752ED9351`
- `AIMW-CONT-6458D3030B`
- `AIMW-CONT-6B051BD8C0`
- `AIMW-CONT-A6867078EA`
- `AIMW-CONT-B6BB292F85`
- `AIMW-CONT-BCAD1E3A49`
- `AIMW-CONT-C49C37E2C9`

### email (1)

- `AIMW-EMAI-0AA71A5EF6`

### identity (0)

- None

### media (2)

- `AIMW-MEDI-799B36D113`
- `AIMW-MEDI-F55A549341`

### operations (0)

- None

### platform (0)

- None

### reports (0)

- None

### seo (0)

- None

### settings (0)

- None

### sites (0)

- None

### sync (44)

- `AIMW-SYNC-1A20057999`
- `AIMW-SYNC-1B63FBC758`
- `AIMW-SYNC-21483C88FA`
- `AIMW-SYNC-246AD1A2AE`
- `AIMW-SYNC-2A256826D2`
- `AIMW-SYNC-3389FB622C`
- `AIMW-SYNC-38D8A24B15`
- `AIMW-SYNC-3E7DCF4C71`
- `AIMW-SYNC-425B82511E`
- `AIMW-SYNC-45A3B43BB9`
- `AIMW-SYNC-476CDB769B`
- `AIMW-SYNC-4B986B2651`
- `AIMW-SYNC-4FDCFDA33F`
- `AIMW-SYNC-5A397204EF`
- `AIMW-SYNC-5EE3F98A28`
- `AIMW-SYNC-6766C503DA`
- `AIMW-SYNC-7A726BAF5F`
- `AIMW-SYNC-7C77CF7F7A`
- `AIMW-SYNC-80B81C07B8`
- `AIMW-SYNC-8409FB077A`
- `AIMW-SYNC-8C35E9A7A0`
- `AIMW-SYNC-9504A57CAE`
- `AIMW-SYNC-9B87CACA17`
- `AIMW-SYNC-AA26ED0909`
- `AIMW-SYNC-AE16CF49C6`
- `AIMW-SYNC-B11D963A9E`
- `AIMW-SYNC-B78216241F`
- `AIMW-SYNC-B83AB5A666`
- `AIMW-SYNC-B955324F04`
- `AIMW-SYNC-BDBA0B4F01`
- `AIMW-SYNC-C1B6FA6677`
- `AIMW-SYNC-C43EFD36B1`
- `AIMW-SYNC-CC2B584BC4`
- `AIMW-SYNC-D5CE5A213B`
- `AIMW-SYNC-D805D5D9A8`
- `AIMW-SYNC-E0791EA9E9`
- `AIMW-SYNC-E3EB27C678`
- `AIMW-SYNC-E51F091C0B`
- `AIMW-SYNC-ECAB44A389`
- `AIMW-SYNC-ED1442005E`
- `AIMW-SYNC-EE7D7534DF`
- `AIMW-SYNC-EEFDDFFF87`
- `AIMW-SYNC-F7BCCD650C`
- `AIMW-SYNC-FD27B795ED`

### taxonomy (0)

- None
