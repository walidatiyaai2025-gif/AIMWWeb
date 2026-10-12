<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operation details could not be loaded</title>
    @vite(['resources/css/app.css'])
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
