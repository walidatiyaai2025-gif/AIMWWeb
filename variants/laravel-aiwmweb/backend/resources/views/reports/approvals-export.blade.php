<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports &amp; Exports</title>
</head>
<body>
<main>
    <section>
        <p>BUSINESS INTELLIGENCE</p>
        <h1>Reports &amp; Exports</h1>
        <p>Live reports from real application data.</p>
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
