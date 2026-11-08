<aside class="sidebar site-email-sidebar" aria-label="{{ $isArabic ? 'التنقل الرئيسي' : 'Primary navigation' }}">
    <div class="brand-row">
        <a class="brand" href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}">
            <span class="brand-mark" aria-hidden="true">AI</span>
            <span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $tenant }}</small></span>
        </a>
    </div>
    <nav class="main-nav" aria-label="{{ $isArabic ? 'تنقل بريد الموقع' : 'Site email navigation' }}">
        <details class="nav-section" open>
            <summary><span>{{ $isArabic ? 'الموقع والبريد' : 'Site and email' }}</span></summary>
            <div class="nav-links">
                <a href="{{ route('canonical.workspace.sites', ['tenant' => $tenant], false) }}">{{ $isArabic ? 'المواقع' : 'Sites' }}</a>
                @if ($selectedSite)
                    <a href="{{ route('canonical.site.details', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) }}">{{ $isArabic ? 'تفاصيل الموقع' : 'Site details' }}</a>
                @endif
                <a href="{{ $selectedSite ? route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) : route('canonical.workspace.site-email-settings.module', ['tenant' => $tenant], false) }}"
                    @if ($activeEmailView === 'settings') class="active" aria-current="page" @endif
                >{{ $isArabic ? 'إعدادات بريد الموقع' : 'Site email settings' }}</a>
                @if ($selectedSite)
                    <a href="{{ route('canonical.workspace.site-email-diagnostics', ['tenant' => $tenant, 'site' => $selectedSite->getKey()], false) }}"
                        @if ($activeEmailView === 'diagnostics') class="active" aria-current="page" @endif
                    >{{ $isArabic ? 'تشخيص بريد الموقع' : 'Site email diagnostics' }}</a>
                @endif
            </div>
        </details>
    </nav>
    <div class="sidebar-footer">
        <strong>{{ $selectedSite?->name ?? $tenant }}</strong>
        <small>{{ $isArabic ? 'مساحة عمل محمية' : 'Protected tenant workspace' }}</small>
    </div>
</aside>
