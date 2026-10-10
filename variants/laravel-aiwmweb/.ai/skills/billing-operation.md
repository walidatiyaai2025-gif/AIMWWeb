# Skill: Billing Operation

## Trigger
Plans, trials, subscriptions, PayPal/provider calls, webhooks, transactions, entitlements, usage/limits, billing admin.

## Procedure
1. Scope billing profile/subscription/credentials/events/transactions to tenant.
2. Server/provider state is authoritative; browser return/cancel URLs do not establish payment success.
3. Verify webhooks/provider events, signature/authenticity, replay/idempotency, provider reference uniqueness, and ordering.
4. Keep provider credentials encrypted/server-side.
5. Separate plan catalog/admin lifecycle from subscriber/provider lifecycle.
6. Enforce entitlements server-side, not only by hiding UI.
7. Reconcile ambiguous provider responses before persisting success.
8. Audit plan, subscription, transaction, and entitlement mutations.
9. Test replay, duplicate provider refs, failure/timeout, foreign tenant, invalid caller state, and entitlement enforcement.
10. Run exact-head billing/full-suite gates before merge.

## Never
Mark subscription/payment success from browser navigation alone.
