<header class="topbar site-email-topbar">
    <div class="topbar-title">
        <nav class="breadcrumb" aria-label="{{ $isArabic ? 'مسار التنقل' : 'Breadcrumb' }}">
            <a href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}">{{ $isArabic ? 'المواقع' : 'Sites' }}</a>
            @if ($selectedSite)
                <span aria-hidden="true">›</span>
                <a href="{{ route('canonical.site.details', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) }}">{{ $selectedSite->name }}</a>
            @endif
            <span aria-hidden="true">›</span>
            <span aria-current="page">{{ $copy['title'] }}</span>
        </nav>
        <h1>{{ $copy['title'] }}</h1>
    </div>
    <div class="topbar-actions">
        @if ($selectedSite && $activeEmailView === 'settings')
            <a class="btn" href="{{ route('canonical.workspace.site-email-diagnostics', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) }}">{{ $isArabic ? 'تشخيص البريد' : 'Email diagnostics' }}</a>
        @elseif ($selectedSite && $activeEmailView === 'diagnostics')
            <a class="btn" href="{{ route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) }}">{{ $isArabic ? 'إعداد البريد' : 'Email settings' }}</a>
        @endif
    </div>
</header>
