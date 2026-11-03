<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>AI WordPress Manager — Laravel</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography scale 50%. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;600;700;800;900&display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 50%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
    <div id="app"></div>
    <noscript>This application requires JavaScript to provide its governed tenant workspace.</noscript>
</body>
</html>
