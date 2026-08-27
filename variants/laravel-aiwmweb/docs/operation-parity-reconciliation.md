{
  "schema_version": 1,
  "authority": "AIMWWeb Issue #257",
  "base_main_sha": "bd6d272748753e21b65655901eaed8bb65a267c7",
  "classification_policy": {
    "terminal_requires": [
      "pushed exact-SHA source",
      "operation-specific destination",
      "tenant ownership evidence when tenant-owned",
      "authorization evidence for mutations/high-risk operations",
      "test evidence"
    ],
    "frontend_placeholder_policy": "not_counted",
    "unpushed_work_policy": "not_counted",
    "percentage_formula": "(TOTAL - PENDING) / TOTAL * 100"
  },
  "totals": {
    "total": 931,
    "ported": 0,
    "adapted": 333,
    "pending": 598,
    "blocked": 0,
    "verified_unavailable_external": 0,
    "terminal": 333,
    "overall_parity_percent": 35.77
  },
  "visible_controls": {
    "total": 446,
    "terminal": 0,
    "percent": 0.0
  },
  "domains": {
    "ai": {
      "total": 92,
      "ported": 0,
      "adapted": 20,
      "pending": 72,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 20,
      "percent": 21.74
    },
    "approvals": {
      "total": 25,
      "ported": 0,
      "adapted": 21,
      "pending": 4,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 21,
      "percent": 84.0
    },
    "automation": {
      "total": 59,
      "ported": 0,
      "adapted": 18,
      "pending": 41,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 18,
      "percent": 30.51
    },
    "backup": {
      "total": 14,
      "ported": 0,
      "adapted": 12,
      "pending": 2,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 12,
      "percent": 85.71
    },
    "billing": {
      "total": 178,
      "ported": 0,
      "adapted": 71,
      "pending": 107,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 71,
      "percent": 39.89
    },
    "comments": {
      "total": 8,
      "ported": 0,
      "adapted": 0,
      "pending": 8,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 0,
      "percent": 0.0
    },
    "content": {
      "total": 164,
      "ported": 0,
      "adapted": 2,
      "pending": 162,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 2,
      "percent": 1.22
    },
    "email": {
      "total": 82,
      "ported": 0,
      "adapted": 49,
      "pending": 33,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 49,
      "percent": 59.76
    },
    "identity": {
      "total": 7,
      "ported": 0,
      "adapted": 0,
      "pending": 7,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 0,
      "percent": 0.0
    },
    "media": {
      "total": 15,
      "ported": 0,
      "adapted": 2,
      "pending": 13,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 2,
      "percent": 13.33
    },
    "operations": {
      "total": 5,
      "ported": 0,
      "adapted": 0,
      "pending": 5,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 0,
      "percent": 0.0
    },
    "platform": {
      "total": 18,
      "ported": 0,
      "adapted": 0,
      "pending": 18,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 0,
      "percent": 0.0
    },
    "reports": {
      "total": 1,
      "ported": 0,
      "adapted": 1,
      "pending": 0,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 1,
      "percent": 100.0
    },
    "seo": {
      "total": 24,
      "ported": 0,
      "adapted": 14,
      "pending": 10,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 14,
      "percent": 58.33
    },
    "settings": {
      "total": 1,
      "ported": 0,
      "adapted": 1,
      "pending": 0,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 1,
      "percent": 100.0
    },
    "sites": {
      "total": 12,
      "ported": 0,
      "adapted": 9,
      "pending": 3,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 9,
      "percent": 75.0
    },
    "sync": {
      "total": 213,
      "ported": 0,
      "adapted": 113,
      "pending": 100,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 113,
      "percent": 53.05
    },
    "taxonomy": {
      "total": 13,
      "ported": 0,
      "adapted": 0,
      "pending": 13,
      "blocked": 0,
      "verified_unavailable_external": 0,
      "terminal": 0,
      "percent": 0.0
    }
  },
  "uncounted_work": [
    {
      "label": "PR #268 email integration handoff",
      "sha": "2b54783b24834b41ed60a4ee73d7f50213b16a21",
      "reason": "Handoff only; superseded by real Email implementation PR #276."
    }
  ]
}
