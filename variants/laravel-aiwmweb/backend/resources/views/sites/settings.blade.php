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
            <p>Authoritative settings summary for <strong>{{ $site->name }}</strong>.</p>
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <dl>
            <div><dt>Name</dt><dd>{{ $site->name }}</dd></div>
            <div><dt>URL</dt><dd>{{ $site->url }}</dd></div>
            <div><dt>Status</dt><dd>{{ $site->status }}</dd></div>
        </dl>

        @if ($canManageCredential)
            <section aria-labelledby="wordpress-credential-heading">
                <h2 id="wordpress-credential-heading">WordPress credential</h2>
                @if ($credential)
                    <dl>
                        <div><dt>Username</dt><dd>{{ $credential->username ?: '—' }}</dd></div>
                        <div><dt>Application Password</dt><dd>••••••••</dd></div>
                    </dl>
                    <form method="POST" action="{{ route('canonical.site.settings.credential.destroy', ['tenant' => $tenant, 'site' => $site->getKey()]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" data-canonical-operation="AIMW-BILL-E36C3E1427">Remove credential</button>
                    </form>
                @else
                    <p>No stored credential.</p>
                @endif
            </section>
        @endif

        @if ($canManageSite)
            <section aria-labelledby="danger-zone-heading" data-canonical-operation="AIMW-BILL-D7D075EF3C">
                <h2 id="danger-zone-heading">Danger zone</h2>
                <p>Deletion removes this site from active lists. Type the exact site name to confirm.</p>
                <form method="POST" action="{{ route('canonical.site.settings.delete', ['tenant' => $tenant, 'site' => $site->getKey()]) }}" autocomplete="off">
                    @csrf
                    @method('DELETE')
                    <label for="site-delete-confirmation">Site name</label>
                    <input id="site-delete-confirmation" name="confirmation" type="text" required maxlength="255" autocomplete="off">
                    @error('confirmation')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit">Delete site</button>
                </form>
            </section>
        @endif

        <nav aria-label="Site settings navigation">
            <a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}">Back to site details</a>
        </nav>
    </main>
</body>
</html>
