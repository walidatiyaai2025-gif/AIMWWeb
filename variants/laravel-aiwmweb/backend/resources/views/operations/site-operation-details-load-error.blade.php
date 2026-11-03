<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operation details could not be loaded</title>
    @vite(['resources/css/app.css'])
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography scale 50%. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 50%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
    <main class="workspace-main" data-canonical-load-operation="{{ \App\Http\Controllers\SiteOperationDetailsReadController::LOAD_OPERATION_ID }}">
        <section class="panel" style="padding:28px">
            <h1>Operation details could not be loaded</h1>
            <p>The operation could not be read from retained history. The storage failure has not been presented as an operation-not-found result.</p>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn primary" data-load-operation-retry data-canonical-operation="{{ \App\Http\Controllers\SiteOperationDetailsReadController::LOAD_OPERATION_ID }}" href="{{ $retryUrl }}">Retry</a>
                <a class="btn" href="{{ $historyUrl }}">Back to history</a>
            </div>
        </section>
    </main>
</body>
</html>
