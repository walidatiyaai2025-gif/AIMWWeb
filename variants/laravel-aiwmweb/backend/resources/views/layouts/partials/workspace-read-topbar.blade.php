<header class="topbar">
    <div class="topbar-title">
        <nav class="breadcrumb" aria-label="{{ $isArabic ? 'مسار التنقل' : 'Breadcrumb' }}">
            <a href="{{ $shellHomeUrl }}">{{ $shellScope }}</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">{{ $shellTitle }}</span>
        </nav>
        <h1>{{ $shellTitle }}</h1>
    </div>
</header>
