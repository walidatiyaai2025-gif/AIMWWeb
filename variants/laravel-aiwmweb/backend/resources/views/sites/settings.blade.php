@php($isArabic = app()->getLocale() === 'ar')
<!DOCTYPE html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>{{ $isArabic ? 'إعدادات الموقع' : 'Site settings' }} · {{ $site->name }} — AIWMWeb</title>
    {{-- The CSS is the same production asset loaded by resources/views/app.blade.php. --}}
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Tajawal:wght@400;500;700;800&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
<div class="app-shell site-settings-shell" data-dashboard-shell="aiwmweb">
    <a href="#site-settings-content" class="skip-link">{{ $isArabic ? 'تخط إلى المحتوى' : 'Skip to content' }}</a>
    <aside class="sidebar" aria-label="{{ $isArabic ? 'التنقل الرئيسي' : 'Primary navigation' }}">
        <div class="brand-row">
            <a class="brand" href="/tenants/{{ rawurlencode($tenant) }}">
                <span class="brand-mark" aria-hidden="true">AI</span>
                <span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $tenant }}</small></span>
            </a>
        </div>
        <nav class="main-nav" aria-label="{{ $isArabic ? 'تنقل الموقع' : 'Site navigation' }}">
            <details class="nav-section" open>
                <summary><span>{{ $isArabic ? 'الموقع' : 'Site' }}</span><span aria-hidden="true">⌄</span></summary>
                <div class="nav-links">
                    <a href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}"><span class="nav-icon" aria-hidden="true">▦</span>{{ $isArabic ? 'المواقع' : 'Sites' }}</a>
                    <a href="{{ route('canonical.site.details', ['tenant' => $tenant, 'site' => $site->getKey()], false) }}"><span class="nav-icon" aria-hidden="true">◎</span>{{ $isArabic ? 'تفاصيل الموقع' : 'Site details' }}</a>
                    <a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}/connection"><span class="nav-icon" aria-hidden="true">◇</span>{{ $isArabic ? 'مركز الاتصال' : 'Connection center' }}</a>
                    <a class="active" aria-current="page" href="{{ route('canonical.site.settings', ['tenant' => $tenant, 'site' => $site->getKey()], false) }}"><span class="nav-icon" aria-hidden="true">⚙</span>{{ $isArabic ? 'إعدادات الموقع' : 'Site settings' }}</a>
                </div>
            </details>
            @if ($canManageTenantSettings)
            <details class="nav-section" open>
                <summary><span>{{ $isArabic ? 'الحساب' : 'Account' }}</span><span aria-hidden="true">⌄</span></summary>
                <div class="nav-links">
                    <a href="/tenants/{{ rawurlencode($tenant) }}/settings"><span class="nav-icon" aria-hidden="true">⚙</span>{{ $isArabic ? 'إعدادات الحساب العامة' : 'Tenant settings' }}</a>
                </div>
            </details>
            @endif
        </nav>
        <div class="sidebar-footer"><strong>{{ $site->name }}</strong><small>{{ $isArabic ? 'مساحة عمل محمية' : 'Protected site workspace' }}</small></div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-title"><div class="heading-block">
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}">{{ $isArabic ? 'المواقع' : 'Sites' }}</a>
                    <b aria-hidden="true">›</b>
                    <a href="{{ route('canonical.site.details', ['tenant' => $tenant, 'site' => $site->getKey()], false) }}">{{ $site->name }}</a>
                    <b aria-hidden="true">›</b>
                    <strong>{{ $isArabic ? 'إعدادات الموقع' : 'Site settings' }}</strong>
                </nav>
                <h1>{{ $isArabic ? 'إعدادات الموقع' : 'Site settings' }}</h1>
            </div></div>
            <div class="topbar-actions">
                @if ($canManageCredential)
                    <a class="btn primary" href="#wordpress-credential-heading">{{ $isArabic ? 'بيانات ووردبريس' : 'WordPress credential' }}</a>
                @endif
                @if ($canManageTenantSettings)
                    <a class="btn" href="/tenants/{{ rawurlencode($tenant) }}/settings">{{ $isArabic ? 'إعدادات الحساب' : 'Tenant settings' }}</a>
                @endif
            </div>
        </header>
        <main id="site-settings-content" class="content site-settings-content" data-canonical-operation="AIMW-SITE-9F9F2977B5">

        <header>
            <p class="workspace-kicker">{{ $isArabic ? "مساحة عمل الموقع" : "Site workspace" }}</p>
            <h1>{{ $isArabic ? "إعدادات الموقع" : "Settings" }}</h1>
            <p>{{ $isArabic ? "ملخص إعدادات الموقع المحفوظة لـ" : "Authoritative settings summary for" }} <strong>{{ $site->name }}</strong>.</p>
            <nav class="site-settings-links" aria-label="Site settings shortcuts">
                <a
                    href="{{ route('canonical.site.details', ['tenant' => $tenant, 'site' => $site->getKey()], false) }}"
                    data-canonical-operation="AIMW-CONT-0154E7772B"
                >{{ app()->getLocale() === 'ar' ? 'لوحة الموقع' : 'Site dashboard' }}</a>
                <a
                    href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}/connection"
                    data-canonical-operation="AIMW-CONT-0154E7772B"
                >{{ app()->getLocale() === 'ar' ? 'مركز الاتصال' : 'Connection center' }}</a>
                @if ($canViewEmailHistory)
                    <a
                        href="{{ route('canonical.workspace.email-history', ['tenant' => $tenant], false) }}"
                        data-canonical-operation="AIMW-EMAI-01F5713C4F"
                    >{{ app()->getLocale() === 'ar' ? 'سجل الإرسال' : 'Delivery history' }}</a>
                @endif
            </nav>
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <dl>
            <div><dt>{{ $isArabic ? "الاسم" : "Name" }}</dt><dd>{{ $site->name }}</dd></div>
            <div><dt>{{ $isArabic ? "الرابط" : "URL" }}</dt><dd>{{ $site->url }}</dd></div>
            <div><dt>{{ $isArabic ? "الحالة" : "Status" }}</dt><dd>{{ $site->status }}</dd></div>
            <div><dt>{{ $isArabic ? "حالة الاتصال" : "Connection status" }}</dt><dd>{{ $site->connection_status }}</dd></div>
        </dl>

        @if ($canManageSite)
            <section aria-labelledby="site-profile-heading" data-canonical-operation="AIMW-BILL-5C15471E4B">
                <h2 id="site-profile-heading">{{ $isArabic ? "ملف الموقع" : "Site profile" }}</h2>
                <p>{{ $isArabic ? "تغيير اسم العرض أو رابط الموقع الأساسي." : "Change the display name or primary WordPress URL." }}</p>
                <form method="POST"
                      action="{{ route('canonical.site.settings.profile', ['tenant' => $tenant, 'site' => $site->getKey()]) }}"
                      data-site-profile-form
                      data-canonical-operation="AIMW-BILL-5C15471E4B">
                    @csrf
                    <label for="site-profile-name">{{ $isArabic ? "اسم الموقع" : "Site name" }}</label>
                    <input id="site-profile-name"
                           name="name"
                           type="text"
                           maxlength="150"
                           value="{{ old('name', $site->name) }}"
                           required>
                    @error('name')
                        <p role="alert">{{ $message }}</p>
                    @enderror

                    <label for="site-profile-url">{{ $isArabic ? "رابط الموقع" : "Site URL" }}</label>
                    <input id="site-profile-url"
                           name="url"
                           type="url"
                           maxlength="2048"
                           value="{{ old('url', $site->url) }}"
                           required>
                    @error('url')
                        <p role="alert">{{ $message }}</p>
                    @enderror

                    <button type="submit" data-canonical-operation="AIMW-BILL-5C15471E4B" data-canonical-control-operation="AIMW-CONT-E1A7A8CAA7">{{ app()->getLocale() === 'ar' ? 'حفظ التغييرات' : 'Save changes' }}</button>
                </form>
            </section>
        @endif

        @if ($canManageSite)
            @php($isOperationallyDisabled = strtolower((string) $site->connection_status) === 'disabled')
            <section aria-labelledby="operational-state-heading" data-canonical-operation="AIMW-BILL-84B63E3F42">
                <h2 id="operational-state-heading">{{ $isArabic ? "حالة التشغيل" : "Operational state" }}</h2>
                <p>{{ $isArabic ? "تعطيل مؤقت دون حذف البيانات. عند إعادة التفعيل تصبح حالة الاتصال غير معروفة حتى التحقق مجددًا." : "Temporarily disable this site without deleting its data. Re-enabling resets the connection state to unknown so it can be verified again." }}</p>
                <form method="POST"
                      action="{{ route('canonical.site.settings.operational-state', ['tenant' => $tenant, 'site' => $site->getKey()]) }}"
                      data-site-operational-state-form
                      data-canonical-operation="AIMW-BILL-84B63E3F42">
                    @csrf
                    <input type="hidden" name="disabled" value="{{ $isOperationallyDisabled ? '0' : '1' }}">
                    <button type="submit" data-canonical-operation="AIMW-BILL-84B63E3F42">
                        {{ $isOperationallyDisabled ? ($isArabic ? 'تفعيل الموقع' : 'Enable site') : ($isArabic ? 'تعطيل الموقع' : 'Disable site') }}
                    </button>
                </form>
            </section>
        @endif

        @if ($canManageCredential)
            <section id="site-credential-settings" aria-labelledby="wordpress-credential-heading">
                <h2 id="wordpress-credential-heading">{{ $isArabic ? "بيانات اتصال ووردبريس" : "WordPress credential" }}</h2>

                <form method="POST" action="{{ route('canonical.site.settings.credential.store', ['tenant' => $tenant, 'site' => $site->getKey()]) }}" data-canonical-operation="AIMW-BILL-723BEA8F1D">
                    @csrf
                    <label>
                        {{ $isArabic ? "اسم مستخدم ووردبريس" : "WordPress username" }}
                        <input type="text" name="username" value="{{ old('username', $credential?->username ?? '') }}" maxlength="255" autocomplete="username" required>
                    </label>
                    <label>
                        {{ $isArabic ? "كلمة مرور التطبيق" : "Application Password" }}
                        <input type="password" name="application_password" minlength="8" maxlength="1024" autocomplete="new-password" required>
                    </label>
                    <button type="submit" data-canonical-operation="AIMW-BILL-723BEA8F1D" data-canonical-control-operation="AIMW-CONT-E1A7A8CAA7">{{ app()->getLocale() === 'ar' ? 'حفظ واختبار' : 'Save and test' }}</button>
                </form>

                @if ($credential)
                    <dl>
                        <div><dt>{{ $isArabic ? "اسم المستخدم" : "Username" }}</dt><dd>{{ $credential->username ?: "—" }}</dd></div>
                        <div><dt>{{ $isArabic ? "كلمة مرور التطبيق" : "Application Password" }}</dt><dd>{{ $isArabic ? "محفوظة ومشفرة" : "Stored encrypted" }}</dd></div>
                    </dl>
                    <form method="POST" action="{{ route('canonical.site.settings.credential.destroy', ['tenant' => $tenant, 'site' => $site->getKey()]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" data-canonical-operation="AIMW-BILL-E36C3E1427">{{ $isArabic ? "حذف بيانات الاتصال" : "Remove credential" }}</button>
                    </form>
                @else
                    <p role="status">{{ $isArabic ? "لا توجد بيانات اتصال محفوظة." : "No stored credential." }}</p>
                @endif
            </section>
        @endif

        @if ($canManageSite)
            <section aria-labelledby="danger-zone-heading" data-canonical-operation="AIMW-BILL-D7D075EF3C">
                <h2 id="danger-zone-heading">{{ $isArabic ? "منطقة الخطر" : "Danger zone" }}</h2>
                <p>{{ $isArabic ? "يحذف الموقع من القوائم النشطة. اكتب اسم الموقع بالكامل للتأكيد." : "Deletion removes this site from active lists. Type the exact site name to confirm." }}</p>
                <form method="POST" action="{{ route('canonical.site.settings.delete', ['tenant' => $tenant, 'site' => $site->getKey()]) }}" autocomplete="off">
                    @csrf
                    @method('DELETE')
                    <label for="site-delete-confirmation">{{ $isArabic ? "اسم الموقع" : "Site name" }}</label>
                    <input id="site-delete-confirmation" name="confirmation" type="text" required maxlength="255" autocomplete="off">
                    @error('confirmation')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit">{{ $isArabic ? "حذف الموقع" : "Delete site" }}</button>
                </form>
            </section>
        @endif

        <nav class="site-settings-links" aria-label="Site settings navigation">
            <a
                href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}"
                data-canonical-operation="AIMW-CONT-3D6485D903"
            >{{ app()->getLocale() === 'ar' ? 'العودة للمواقع' : 'Back to sites' }}</a>
            <a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}">{{ $isArabic ? "العودة إلى تفاصيل الموقع" : "Back to site details" }}</a>
        </nav>
        </main>
    </div>
</div>
</body>
</html>
