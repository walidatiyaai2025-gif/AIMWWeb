<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>About this build</title>
</head>
<body>
    <main>
        <h1>About this build</h1>
        <p>Verify the deployed build and review what shipped in the current release.</p>
        @if ($buildApiUrl)
            <p><a href="{{ $buildApiUrl }}" target="_blank" rel="noopener noreferrer">Open build API</a></p>
        @endif

        <section aria-labelledby="build-information">
            <h2 id="build-information">Build information</h2>
            <dl>
                <dt>Application</dt><dd>{{ $build['assemblyName'] ?: '—' }}</dd>
                <dt>Version</dt><dd>{{ $build['version'] ?: '—' }}</dd>
                <dt>Informational version</dt><dd>{{ $build['informationalVersion'] ?: '—' }}</dd>
                <dt>Branch</dt><dd>{{ $build['branch'] ?: '—' }}</dd>
                <dt>Commit SHA</dt><dd>{{ $build['commit'] ?: '—' }}</dd>
                <dt>Build time UTC</dt><dd>{{ $build['buildTimeUtc'] ?: '—' }}</dd>
            </dl>
        </section>

        <section aria-labelledby="current-release">
            <h2 id="current-release">Current release</h2>
            @if ($currentRelease)
                <h3>{{ $currentRelease['title'] }}</h3>
                <pre>{{ $currentRelease['content'] }}</pre>
            @else
                <p>No release notes were found.</p>
            @endif
        </section>

        @if (count($releases) > 1)
            <section aria-labelledby="release-history">
                <h2 id="release-history">Release history</h2>
                @foreach (array_slice($releases, 1) as $release)
                    <article>
                        <h3>{{ $release['title'] }}</h3>
                        @if (! empty($release['date']))<p>{{ $release['date'] }}</p>@endif
                        <pre>{{ $release['content'] }}</pre>
                    </article>
                @endforeach
            </section>
        @endif

        <section aria-labelledby="technical-details">
            <h2 id="technical-details">Technical details</h2>
            <dl>
                <dt>Runtime</dt><dd>{{ $runtime }}</dd>
                <dt>Framework</dt><dd>{{ $framework }}</dd>
                <dt>Operating system</dt><dd>{{ $operatingSystem }}</dd>
                <dt>Git commit</dt><dd>{{ $build['commit'] ?: '—' }}</dd>
                <dt>API endpoint</dt>
                <dd>
                    @if ($buildApiUrl)
                        <a href="{{ $buildApiUrl }}">/api/build</a>
                    @else
                        <span>/api/build (permission required)</span>
                    @endif
                </dd>
            </dl>
        </section>
    </main>
</body>
</html>
