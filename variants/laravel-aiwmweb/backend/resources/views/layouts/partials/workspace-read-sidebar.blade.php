{{-- Callers pass only server-authored, permission-appropriate links; do not derive paths from requests. --}}
<aside class="sidebar" aria-label="{{ $isArabic ? 'التنقل الرئيسي' : 'Primary navigation' }}">
    <div class="brand-row">
        <a class="brand" href="{{ $shellHomeUrl }}">
            <span class="brand-mark" aria-hidden="true">AI</span>
            <span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $shellScope }}</small></span>
        </a>
    </div>
    <nav class="main-nav" aria-label="{{ $isArabic ? 'تنقل مساحة العمل' : 'Workspace navigation' }}">
        @foreach ($shellLinks as $shellLink)
            <a href="{{ $shellLink['url'] }}" @if ($shellLink['active'] ?? false) class="active" aria-current="page" @endif>{{ $shellLink['label'] }}</a>
        @endforeach
    </nav>
    <div class="sidebar-footer">{{ $isArabic ? 'مساحة عمل محمية' : 'Protected workspace' }}</div>
</aside>
