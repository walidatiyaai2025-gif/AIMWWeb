<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>SEO Manager — {{ $site->name }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/seo-visible-controls.tsx'])
</head>
<body>
    <div id="seo-visible-controls" data-canonical-operation="AIMW-SEO-5F71B89C92"></div>
    <script id="seo-visible-controls-config" type="application/json">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <noscript>This SEO workspace requires JavaScript to execute its governed visible controls.</noscript>
</body>
</html>
