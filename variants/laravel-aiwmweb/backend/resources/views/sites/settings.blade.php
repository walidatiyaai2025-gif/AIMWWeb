<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site settings · {{ $site->name }}</title>
</head>
<body>
    <main data-canonical-operation="AIMW-SITE-9F9F2977B5">
        <header>
            <p>Site workspace</p>
            <h1>Settings</h1>
            <p>Authoritative read-only settings summary for <strong>{{ $site->name }}</strong>.</p>
        </header>

        <dl>
            <div><dt>Name</dt><dd>{{ $site->name }}</dd></div>
            <div><dt>URL</dt><dd>{{ $site->url }}</dd></div>
            <div><dt>Status</dt><dd>{{ $site->status }}</dd></div>
        </dl>

        <p>No settings mutation is exposed by this canonical navigation control.</p>
        <nav aria-label="Site settings navigation">
            <a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}">Back to site details</a>
        </nav>
    </main>
</body>
</html>
