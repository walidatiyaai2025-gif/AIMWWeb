@php
    $en = request()->query('lang') === 'en';
@endphp
<!doctype html>
<html lang="{{ $en ? 'en' : 'ar' }}" dir="{{ $en ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="{{ $en ? 'Manage WordPress sites, content, SEO and governed automation from one workspace.' : 'إدارة مواقع WordPress والمحتوى وSEO والأتمتة من مساحة عمل موحدة.' }}">
    <title>{{ $en ? 'AI WordPress Manager | WordPress operations platform' : 'AI WordPress Manager | إدارة مواقع WordPress' }}</title>
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}">
</head>
<body class="marketing-page">
    <header class="site-header">
        <a class="brand" href="/" aria-label="AI WordPress Manager">
            <span class="brand-mark">AI</span>
            <span><strong>AI WordPress Manager</strong><small>WORDPRESS OPERATIONS</small></span>
        </a>
        <nav aria-label="{{ $en ? 'Public navigation' : 'القائمة العامة' }}">
            <a href="#features">{{ $en ? 'Features' : 'المميزات' }}</a>
            <a href="#workflow">{{ $en ? 'How it works' : 'طريقة العمل' }}</a>
            <a href="#security">{{ $en ? 'Security' : 'الأمان' }}</a>
        </nav>
        <div class="header-actions">
            <a class="language-switch" href="{{ $en ? '/' : '/?lang=en' }}" lang="{{ $en ? 'ar' : 'en' }}">{{ $en ? 'العربية' : 'English' }}</a>
            <a href="{{ url('/login') }}" class="secondary-action">{{ $en ? 'Sign in' : 'تسجيل الدخول' }}</a>
            <a href="{{ url('/register') }}" class="primary-action">{{ $en ? 'Create account' : 'إنشاء حساب' }}</a>
        </div>
    </header>
    <main>
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow"><span class="glow-dot"></span>{{ $en ? 'ONE WORKSPACE · MULTIPLE WORDPRESS SITES' : 'مساحة واحدة · مواقع WordPress متعددة' }}</p>
                <h1 id="hero-title">{{ $en ? 'Operate WordPress as one system.' : 'إدارة WordPress من مكان واحد.' }} <em>{{ $en ? 'Not twenty dashboards.' : 'مش عشرين لوحة تحكم.' }}</em></h1>
                <p class="intro">{{ $en ? 'Connect and monitor sites, manage content, investigate SEO issues and review automation or AI suggestions before controlled execution.' : 'اربط مواقعك، تابع حالتها، أدر المحتوى، راجع مشكلات SEO واقتراحات الذكاء الاصطناعي قبل تنفيذ أي تغيير مهم.' }}</p>
                <div class="hero-actions">
                    <a href="{{ url('/register') }}" class="primary-action large">{{ $en ? 'Get started' : 'ابدأ الآن' }} <span aria-hidden="true">↗</span></a>
                    <a href="#workflow" class="secondary-action large">{{ $en ? 'Explore workflow' : 'اكتشف طريقة العمل' }}</a>
                </div>
                <p class="hero-caption">{{ $en ? 'A real WordPress connection and explicit permissions are required for live operations. AI requires provider configuration.' : 'تنفيذ العمليات الفعلية يتطلب ربط موقع WordPress وصلاحيات واضحة. ميزات AI تتطلب إعداد مزود الخدمة.' }}</p>
            </div>
            <div class="preview" aria-label="{{ $en ? 'Illustration of the product workflow, not live customer data' : 'رسم توضيحي لمسار العمل وليس بيانات عملاء حقيقية' }}">
                <div class="preview-top"><span class="preview-logo">AI</span><span>WORDPRESS OPERATIONS</span><span class="preview-label">{{ $en ? 'Workflow preview' : 'معاينة توضيحية' }}</span></div>
                <div class="preview-body">
                    <div class="preview-side"><span>⌂</span><span>▤</span><span>⌕</span><span>✦</span><span>⚙</span></div>
                    <div class="preview-content">
                        <p class="mini-eyebrow">{{ $en ? 'WORKFLOW OVERVIEW' : 'نظرة على مسار العمل' }}</p>
                        <h2>{{ $en ? 'Site management' : 'إدارة المواقع' }}</h2>
                        <div class="preview-grid">
                            <div><span>01</span><strong>{{ $en ? 'Connect' : 'اربط الموقع' }}</strong><small>WordPress REST</small></div>
                            <div><span>02</span><strong>{{ $en ? 'Inspect' : 'افحص المحتوى' }}</strong><small>Content / SEO</small></div>
                            <div><span>03</span><strong>{{ $en ? 'Approve' : 'راجع ووافق' }}</strong><small>Permissions / Audit</small></div>
                        </div>
                        <div class="preview-task"><span class="task-dot"></span><div><strong>{{ $en ? 'Changes require review' : 'التغييرات تحتاج مراجعة' }}</strong><small>{{ $en ? 'The platform keeps evidence and execution history.' : 'سجل للإجراءات والإثباتات وحالة التنفيذ.' }}</small></div><span class="task-badge">{{ $en ? 'CONTROLLED' : 'محكوم' }}</span></div>
                        <div class="preview-bars"><i></i><i></i><i></i></div>
                        <p class="mock-disclaimer">{{ $en ? 'Illustrative interface — no fabricated live site statistics' : 'واجهة توضيحية — بدون أرقام أو بيانات تشغيل مختلقة' }}</p>
                    </div>
                </div>
            </div>
        </section>
        <div class="capability-bar" aria-label="{{ $en ? 'Technology capabilities' : 'قدرات المنصة' }}"><span>WORDPRESS REST</span><span>MULTI-TENANT</span><span>SEO</span><span>AI PROVIDERS</span><span>APPROVALS</span><span>AUDIT TRAIL</span></div>
        <section class="content-section" id="features">
            <div class="section-heading"><p class="eyebrow">{{ $en ? 'PRODUCT CAPABILITIES' : 'إمكانات المنتج' }}</p><h2>{{ $en ? 'Work across sites, without losing control.' : 'كل موقع تحت سيطرتك، من لوحة واحدة.' }}</h2><p>{{ $en ? 'Core capabilities are organized around traceable operations and site-specific access.' : 'وظائف مرتبطة بالمواقع والصلاحيات وسجل واضح لكل إجراء.' }}</p></div>
            <div class="feature-grid">
                <article><span class="feature-icon">◎</span><h3>{{ $en ? 'Sites and connectivity' : 'المواقع والاتصال' }}</h3><p>{{ $en ? 'Add sites with WordPress Application Passwords; verify the connection and inspect its status.' : 'أضف المواقع باستخدام Application Password، اختبر الاتصال وتابع حالة كل موقع.' }}</p></article>
                <article><span class="feature-icon">▤</span><h3>{{ $en ? 'Content and synchronization' : 'المحتوى والمزامنة' }}</h3><p>{{ $en ? 'Read and manage posts, pages and media with site-scoped permissions and synchronization.' : 'إدارة المقالات والصفحات والوسائط مع مزامنة وصلاحيات لكل موقع.' }}</p></article>
                <article><span class="feature-icon">⌕</span><h3>{{ $en ? 'SEO review' : 'فحص وتحسين SEO' }}</h3><p>{{ $en ? 'Track findings and review proposed changes before applying them to WordPress.' : 'اكتشف المشكلات وراجع التعديلات المقترحة قبل تطبيقها على WordPress.' }}</p></article>
                <article><span class="feature-icon">✦</span><h3>{{ $en ? 'AI with governance' : 'ذكاء اصطناعي بضوابط' }}</h3><p>{{ $en ? 'Configure providers, budgets and approvals; AI execution depends on enabled settings.' : 'إعداد المزوّدين والحدود والموافقات؛ تشغيل AI مرتبط بتفعيل الخدمة وإعدادها.' }}</p></article>
                <article><span class="feature-icon">⚡</span><h3>{{ $en ? 'Automation and jobs' : 'الأتمتة والمهام' }}</h3><p>{{ $en ? 'Monitor queued and completed work without treating a request as confirmed execution.' : 'تابع المهام وحالاتها، مع التمييز بين الطلب والتنفيذ الفعلي.' }}</p></article>
                <article><span class="feature-icon">✓</span><h3>{{ $en ? 'Security and evidence' : 'الأمان وسجل التنفيذ' }}</h3><p>{{ $en ? 'Tenant separation, explicit WordPress permissions, and auditable execution receipts.' : 'عزل مساحات العمل وصلاحيات واضحة وإيصالات قابلة للمراجعة.' }}</p></article>
            </div>
        </section>
        <section class="content-section workflow-section" id="workflow">
            <div class="section-heading"><p class="eyebrow">{{ $en ? 'FROM CONNECTION TO CONTROLLED EXECUTION' : 'من الاتصال إلى التنفيذ المحكوم' }}</p><h2>{{ $en ? 'A clear four-step journey.' : 'أربع خطوات واضحة.' }}</h2></div>
            <div class="steps"><article><b>01</b><h3>{{ $en ? 'Connect' : 'اربط' }}</h3><p>{{ $en ? 'Add a site and verify its WordPress credentials.' : 'أضف موقعًا وتأكد من بيانات WordPress.' }}</p></article><article><b>02</b><h3>{{ $en ? 'Sync and inspect' : 'زامن وافحص' }}</h3><p>{{ $en ? 'Read content and identify issues.' : 'اقرأ المحتوى وتعرف على المشكلات.' }}</p></article><article><b>03</b><h3>{{ $en ? 'Review' : 'راجع' }}</h3><p>{{ $en ? 'Approve sensitive changes before running them.' : 'وافق على التعديلات الحساسة قبل التنفيذ.' }}</p></article><article><b>04</b><h3>{{ $en ? 'Execute and verify' : 'نفّذ وتحقق' }}</h3><p>{{ $en ? 'Inspect outcome, errors and durable receipts.' : 'راجع النتيجة والأخطاء وإيصالات التنفيذ.' }}</p></article></div>
        </section>
        <section class="content-section security-section" id="security">
            <div><p class="eyebrow">{{ $en ? 'SECURITY BY DESIGN' : 'الأمان جزء من التصميم' }}</p><h2>{{ $en ? 'No silent publishing or hidden credentials.' : 'لا نشر خفي ولا مفاتيح داخل المتصفح.' }}</h2><p>{{ $en ? 'Site credentials remain on the server. Advanced connector capabilities require owner-enabled scopes and verified requests. Sensitive actions follow explicit permission and approval rules.' : 'بيانات المواقع محفوظة على الخادم. الصلاحيات الحساسة في Connector تحتاج تفعيلًا من مالك الموقع وتحققًا من الطلب، مع موافقات واضحة على الإجراءات.' }}</p></div>
            <a href="{{ url('/login') }}" class="primary-action large">{{ $en ? 'Open your workspace' : 'افتح مساحة العمل' }}</a>
        </section>
    </main>
    <footer><strong>AI WordPress Manager</strong><span>{{ $en ? 'WordPress operations, under your control.' : 'إدارة WordPress تحت سيطرتك.' }}</span><a href="{{ url('/login') }}">{{ $en ? 'Sign in' : 'تسجيل الدخول' }}</a></footer>
</body>
</html>
