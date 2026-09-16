<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing support</title>
</head>
<body>
    <main data-canonical-operation="{{ $canonicalOperationId }}">
        <header>
            <p>Administration</p>
            <h1>Billing support</h1>
            <p>Inspect and support account billing from an authenticated platform-administrator boundary.</p>
        </header>

        <section aria-labelledby="billing-support-safety">
            <h2 id="billing-support-safety">Support safety</h2>
            <p>Payment state is authoritative only when confirmed by committed billing evidence or the configured payment provider. Browser returns and caller-supplied identifiers are not treated as payment success.</p>
            <p>This route is read-only. Billing support commands and their audited success or failure states remain separately governed operations and are not executed by opening this page.</p>
        </section>

        <section aria-labelledby="billing-support-scope">
            <h2 id="billing-support-scope">Access scope</h2>
            <p>The route accepts no tenant, account, subscription, payment-provider, or user resource identifier. Access is derived from the authenticated server-side platform-administrator identity.</p>
        </section>
    </main>
</body>
</html>
