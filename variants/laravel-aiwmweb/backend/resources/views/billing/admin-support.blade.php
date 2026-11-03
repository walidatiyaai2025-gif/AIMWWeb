<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing support</title>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than previous baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 62.5%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }

        @media (min-width: 1140px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        }
        @media (min-width: 701px) and (max-width: 1139px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        }
        @media (max-width: 700px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
        }
            /* Three-column forms, plan cards and history records; full-width actions retained. */
        @media (min-width: 1140px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
        main form:has(> label ~ label ~ label) > :not(label), main section:has(> article ~ article) > :not(article), main section:has(> .record ~ .record) > :not(.record) { grid-column: 1 / -1; }
        main :is(.grid, .cards, .record-grid) > * { min-width: 0; }
</style>
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
