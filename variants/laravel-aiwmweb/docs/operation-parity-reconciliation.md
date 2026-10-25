# Laravel AIWMWeb 931-Operation Parity Reconciliation

Authority: AIMWWeb Issue #257. Evidence-only reconciliation; no feature completion is inferred from file, class, route, or UI presence alone.

## Exact totals

| Metric | Value |
|---|---:|
| TOTAL | 931 |
| PORTED | 0 |
| ADAPTED | 791 |
| PENDING | 140 |
| BLOCKED | 0 |
| VERIFIED_UNAVAILABLE_EXTERNAL | 0 |
| TERMINAL | 791 |
| OVERALL_PARITY_PERCENT | 84.96% |

Terminal progress is strictly `PORTED + ADAPTED + VERIFIED_UNAVAILABLE_EXTERNAL`; `BLOCKED` is not progress.

## Visible controls

- Total: **446**
- Terminal: **314**
- Pending: **132**
- Blocked: **0**
- Parity: **70.40%**

## By domain

| Domain | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| ai | 92 | 0 | 92 | 0 | 0 | 0 | 92 | 100.00% |
| approvals | 25 | 0 | 25 | 0 | 0 | 0 | 25 | 100.00% |
| automation | 59 | 0 | 59 | 0 | 0 | 0 | 59 | 100.00% |
| backup | 14 | 0 | 14 | 0 | 0 | 0 | 14 | 100.00% |
| billing | 178 | 0 | 173 | 5 | 0 | 0 | 173 | 97.19% |
| comments | 8 | 0 | 8 | 0 | 0 | 0 | 8 | 100.00% |
| content | 164 | 0 | 109 | 55 | 0 | 0 | 109 | 66.46% |
| email | 82 | 0 | 76 | 6 | 0 | 0 | 76 | 92.68% |
| identity | 7 | 0 | 7 | 0 | 0 | 0 | 7 | 100.00% |
| media | 15 | 0 | 6 | 9 | 0 | 0 | 6 | 40.00% |
| operations | 5 | 0 | 5 | 0 | 0 | 0 | 5 | 100.00% |
| platform | 18 | 0 | 18 | 0 | 0 | 0 | 18 | 100.00% |
| reports | 1 | 0 | 1 | 0 | 0 | 0 | 1 | 100.00% |
| seo | 24 | 0 | 24 | 0 | 0 | 0 | 24 | 100.00% |
| settings | 1 | 0 | 1 | 0 | 0 | 0 | 1 | 100.00% |
| sites | 12 | 0 | 12 | 0 | 0 | 0 | 12 | 100.00% |
| sync | 213 | 0 | 151 | 62 | 0 | 0 | 151 | 70.89% |
| taxonomy | 13 | 0 | 10 | 3 | 0 | 0 | 10 | 76.92% |

## By kind

| Kind | Total | Ported | Adapted | Pending | Blocked | VUE | Terminal | % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| api | 31 | 0 | 31 | 0 | 0 | 0 | 31 | 100.00% |
| background_job | 21 | 0 | 21 | 0 | 0 | 0 | 21 | 100.00% |
| route | 84 | 0 | 76 | 8 | 0 | 0 | 76 | 90.48% |
| service | 349 | 0 | 349 | 0 | 0 | 0 | 349 | 100.00% |
| visible_control | 446 | 0 | 314 | 132 | 0 | 0 | 314 | 70.40% |

## Reproducibility / guard results

- Denominator exactly 931: **PASS**
- Duplicate operation IDs: **0**
- Allowed statuses only: **PASS**
- Totals reconcile: **PASS**
- Evidence references exist for terminal code rows: **PASS**
- Explicit route contracts: **44**
- Focused visible-control contracts: **314**
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

### billing (5)

- `AIMW-BILL-616576D8F7`
- `AIMW-BILL-7BD7B04B26`
- `AIMW-BILL-BC093D6418`
- `AIMW-BILL-D307963B59`
- `AIMW-BILL-EE42461195`

### comments (0)

- None

### content (55)

- `AIMW-CONT-10AFCDF334`
- `AIMW-CONT-1456BE001F`
- `AIMW-CONT-2752ED9351`
- `AIMW-CONT-349017BDF5`
- `AIMW-CONT-371D22489E`
- `AIMW-CONT-38FC74B9DE`
- `AIMW-CONT-40CD7F080F`
- `AIMW-CONT-4147188AB1`
- `AIMW-CONT-47007EE040`
- `AIMW-CONT-47C0477703`
- `AIMW-CONT-4A64732FC6`
- `AIMW-CONT-50DF6B23CC`
- `AIMW-CONT-566A1899F1`
- `AIMW-CONT-6187F8DF8A`
- `AIMW-CONT-63EDB4BA6F`
- `AIMW-CONT-6458D3030B`
- `AIMW-CONT-659BEF8765`
- `AIMW-CONT-6700342002`
- `AIMW-CONT-69B5134C7E`
- `AIMW-CONT-6B051BD8C0`
- `AIMW-CONT-6B6790BE84`
- `AIMW-CONT-6CB3D0BE1B`
- `AIMW-CONT-6EC0601E18`
- `AIMW-CONT-73E4F88038`
- `AIMW-CONT-7957ECC794`
- `AIMW-CONT-7D7BCD462F`
- `AIMW-CONT-7D83B09470`
- `AIMW-CONT-7EF5524637`
- `AIMW-CONT-7F2BC55FF4`
- `AIMW-CONT-80EE7CA296`
- `AIMW-CONT-8C19E4E3B2`
- `AIMW-CONT-916E34454C`
- `AIMW-CONT-918BE3DBF1`
- `AIMW-CONT-932A09E4A9`
- `AIMW-CONT-98918A6C27`
- `AIMW-CONT-9F0D652C67`
- `AIMW-CONT-A3A2CD23B6`
- `AIMW-CONT-A6867078EA`
- `AIMW-CONT-A6EA54E66D`
- `AIMW-CONT-B1024628D9`
- `AIMW-CONT-B44DA81C28`
- `AIMW-CONT-B60F3F750B`
- `AIMW-CONT-B6BB292F85`
- `AIMW-CONT-BB31987A53`
- `AIMW-CONT-BCAD1E3A49`
- `AIMW-CONT-C3EC665880`
- `AIMW-CONT-C49C37E2C9`
- `AIMW-CONT-CA4920CBC8`
- `AIMW-CONT-CF4BE4EC3B`
- `AIMW-CONT-DF6FFA9D87`
- `AIMW-CONT-E4D7C73D4E`
- `AIMW-CONT-ED2F1E1572`
- `AIMW-CONT-EDC262499D`
- `AIMW-CONT-EE659E3F13`
- `AIMW-CONT-FE6EE5740C`

### email (6)

- `AIMW-EMAI-01F5713C4F`
- `AIMW-EMAI-0AA71A5EF6`
- `AIMW-EMAI-10F3C44369`
- `AIMW-EMAI-7E1D4105B0`
- `AIMW-EMAI-8C9768BCD0`
- `AIMW-EMAI-9EB09C490D`

### identity (0)

- None

### media (9)

- `AIMW-MEDI-03A0D722AD`
- `AIMW-MEDI-3444B17C11`
- `AIMW-MEDI-34B5C789D2`
- `AIMW-MEDI-34E7DCA832`
- `AIMW-MEDI-56F4EF0AFB`
- `AIMW-MEDI-727FAEEB01`
- `AIMW-MEDI-799B36D113`
- `AIMW-MEDI-8F93BEDD59`
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

### sync (62)

- `AIMW-SYNC-0108E40967`
- `AIMW-SYNC-0239B0E6A3`
- `AIMW-SYNC-078D172C71`
- `AIMW-SYNC-1A20057999`
- `AIMW-SYNC-1B63FBC758`
- `AIMW-SYNC-21483C88FA`
- `AIMW-SYNC-246AD1A2AE`
- `AIMW-SYNC-2A256826D2`
- `AIMW-SYNC-2C6ADCE34C`
- `AIMW-SYNC-2E94028E4E`
- `AIMW-SYNC-3389FB622C`
- `AIMW-SYNC-33EE696FF7`
- `AIMW-SYNC-38D8A24B15`
- `AIMW-SYNC-3BFC8AF183`
- `AIMW-SYNC-3E7DCF4C71`
- `AIMW-SYNC-419C403EFB`
- `AIMW-SYNC-425B82511E`
- `AIMW-SYNC-45A3B43BB9`
- `AIMW-SYNC-476CDB769B`
- `AIMW-SYNC-4B986B2651`
- `AIMW-SYNC-4FDCFDA33F`
- `AIMW-SYNC-5A397204EF`
- `AIMW-SYNC-5EE3F98A28`
- `AIMW-SYNC-627C90DC5C`
- `AIMW-SYNC-6766C503DA`
- `AIMW-SYNC-7A726BAF5F`
- `AIMW-SYNC-7C77CF7F7A`
- `AIMW-SYNC-80B81C07B8`
- `AIMW-SYNC-8409FB077A`
- `AIMW-SYNC-8C35E9A7A0`
- `AIMW-SYNC-9504A57CAE`
- `AIMW-SYNC-9ABD7DEDBB`
- `AIMW-SYNC-9B87CACA17`
- `AIMW-SYNC-9DFB5B4FF9`
- `AIMW-SYNC-AA26ED0909`
- `AIMW-SYNC-ABE31C5C4E`
- `AIMW-SYNC-AE16CF49C6`
- `AIMW-SYNC-B11D963A9E`
- `AIMW-SYNC-B78216241F`
- `AIMW-SYNC-B83AB5A666`
- `AIMW-SYNC-B955324F04`
- `AIMW-SYNC-BDBA0B4F01`
- `AIMW-SYNC-C18ED1F753`
- `AIMW-SYNC-C1B6FA6677`
- `AIMW-SYNC-C43EFD36B1`
- `AIMW-SYNC-CC2B584BC4`
- `AIMW-SYNC-CDE0C219B5`
- `AIMW-SYNC-D5CE5A213B`
- `AIMW-SYNC-D805D5D9A8`
- `AIMW-SYNC-DDC8657C2F`
- `AIMW-SYNC-E0791EA9E9`
- `AIMW-SYNC-E3EB27C678`
- `AIMW-SYNC-E51F091C0B`
- `AIMW-SYNC-E6888B0B4D`
- `AIMW-SYNC-EB9202408A`
- `AIMW-SYNC-ECAB44A389`
- `AIMW-SYNC-ED1442005E`
- `AIMW-SYNC-EE7D7534DF`
- `AIMW-SYNC-EEFDDFFF87`
- `AIMW-SYNC-F7BCCD650C`
- `AIMW-SYNC-FD27B795ED`
- `AIMW-SYNC-FF579F0EBF`

### taxonomy (3)

- `AIMW-TAXO-602DE140EB`
- `AIMW-TAXO-A96714F338`
- `AIMW-TAXO-D6126926AD`
