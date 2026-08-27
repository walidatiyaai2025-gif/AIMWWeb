# Capability Parity Ledger

Authority: AIMWWeb Issue #257

This is the initial ledger structure. Broad feature census and porting are intentionally deferred until Tenant Core is accepted.

Allowed terminal states: `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, `BLOCKED`.

| Capability ID | Operation ID | Current AIMWWeb source | User-visible behavior | Tenant-owned data | Laravel destination | State | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| _inventory pending_ | _inventory pending_ | _pending census_ | _pending census_ | _pending classification_ | _not yet ported_ | `BLOCKED` | Tenant Core first slice |

No capability may be removed from the denominator or marked terminal without source and acceptance evidence.
