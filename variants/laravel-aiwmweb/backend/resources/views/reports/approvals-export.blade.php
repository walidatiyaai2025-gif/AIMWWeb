<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports &amp; Exports</title>
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
<main>
    <section>
        <p>BUSINESS INTELLIGENCE</p>
        <h1>Reports &amp; Exports</h1>
        <p>Live reports from real application data.</p>
        <a
            data-canonical-operation="AIMW-BILL-A6E9CF63BC"
            href="{{ request()->getRequestUri() }}"
            aria-label="Refresh reports"
        >Refresh</a>
    </section>

    <section data-canonical-operation="AIMW-SYNC-D8581471A2">
        <header>
            <h2>Sites report</h2>
            @if ($canExport)
                <a href="{{ $sitesDownloadUrl }}" download="sites-report.csv">CSV</a>
            @else
                <span aria-disabled="true">CSV — reports.manage required</span>
            @endif
        </header>

        @if ($siteRows->isEmpty())
            <p>No site rows are available for this tenant.</p>
        @else
            <table>
                <thead>
                <tr><th>Name</th><th>URL</th><th>Status</th></tr>
                </thead>
                <tbody>
                @foreach ($siteRows->take(12) as $site)
                    <tr>
                        <td>{{ $site['name'] }}</td>
                        <td>{{ $site['url'] }}</td>
                        <td>{{ $site['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section data-canonical-operation="AIMW-BILL-5E76AD4FAE">
        <header>
            <h2>Content planner report</h2>
            @if ($canExport)
                <a href="{{ $plannerDownloadUrl }}" download="content-planner-report.csv">CSV</a>
            @else
                <span aria-disabled="true">CSV — reports.manage required</span>
            @endif
        </header>

        @if ($plannerRows->isEmpty())
            <p>No content planner rows are available for this tenant.</p>
        @else
            <table>
                <thead>
                <tr><th>Title</th><th>Site</th><th>Status</th></tr>
                </thead>
                <tbody>
                @foreach ($plannerRows->take(12) as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['site'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section data-canonical-operation="AIMW-APPR-A8F5FB3762">
        <header>
            <h2>Approvals report</h2>
            @if ($canExport)
                <a href="{{ $downloadUrl }}" download="approvals-report.csv">CSV</a>
            @else
                <span aria-disabled="true">CSV — reports.manage required</span>
            @endif
        </header>

        @if ($rows->isEmpty())
            <p>No approval rows are available for this tenant.</p>
        @else
            <table>
                <thead>
                <tr><th>Title</th><th>Site</th><th>Status</th></tr>
                </thead>
                <tbody>
                @foreach ($rows->take(12) as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['site'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
</main>
</body>
</html>
