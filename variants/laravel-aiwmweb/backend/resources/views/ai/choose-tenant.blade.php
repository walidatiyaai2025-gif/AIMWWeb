<!doctype html>
<html lang="{{ session('public_welcome_locale') === 'ar' ? 'ar' : 'en' }}" dir="{{ session('public_welcome_locale') === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ session('public_welcome_locale') === 'ar' ? 'اختر مساحة العمل' : 'Choose your workspace' }} — AIWMWeb</title>
    <style>
        body { margin: 0; background: #f3f7f6; color: #17362f; font-family: system-ui, sans-serif; }
        main { max-width: 660px; margin: 8vh auto; padding: 24px; }
        h1 { font-size: 1.7rem; }
        p { line-height: 1.6; color: #3e5b54; }
        ul { list-style: none; padding: 0; display: grid; gap: 12px; }
        li a { display: block; padding: 18px 20px; background: #fff; border: 1px solid #c5d8d1; border-radius: 12px; color: #0c614a; text-decoration: none; font-weight: 650; }
        li a:hover, li a:focus-visible { border-color: #13845c; outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <h1>{{ session('public_welcome_locale') === 'ar' ? 'اختر مساحة العمل' : 'Choose a workspace' }}</h1>
        <p>{{ session('public_welcome_locale') === 'ar' ? 'لديك أكثر من حساب نشط. اختر الحساب الذي تريد فتح لوحة تحكمه.' : 'You have more than one active account. Select the workspace you want to open.' }}</p>
        <ul aria-label="{{ session('public_welcome_locale') === 'ar' ? 'مساحات العمل المتاحة' : 'Available workspaces' }}">
            @foreach ($tenants as $tenant)
                <li><a href="/tenants/{{ rawurlencode((string) $tenant->slug) }}">{{ $tenant->name }}</a></li>
            @endforeach
        </ul>
    </main>
</body>
</html>
