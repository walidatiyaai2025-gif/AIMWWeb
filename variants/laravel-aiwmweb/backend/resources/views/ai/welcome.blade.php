<!doctype html>
<html lang="{{ $welcomeLocale }}" dir="{{ $welcomeLocale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>AI WordPress Manager</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at top, #16233d 0, #08111f 48%, #050a12 100%); color: #eef4ff; }
        .shell { width: min(1120px, calc(100% - 40px)); margin: 0 auto; }
        .header { min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 24px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; font-weight: 800; letter-spacing: -.02em; }
        .brand-mark { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, #5eead4, #60a5fa); color: #06111e; box-shadow: 0 12px 40px rgba(96,165,250,.25); }
        .brand-copy { display: grid; gap: 2px; }
        .brand-copy small { color: #91a4bf; font-size: 11px; letter-spacing: .12em; font-weight: 700; }
        .signin { color: #d9e7fb; text-decoration: none; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 10px 15px; font-weight: 700; }
        .hero { min-height: calc(100vh - 77px); display: grid; grid-template-columns: 1.15fr .85fr; align-items: center; gap: 56px; padding: 72px 0; }
        .eyebrow { color: #72e2d1; font-size: 12px; font-weight: 800; letter-spacing: .16em; }
        h1 { margin: 16px 0 20px; max-width: 760px; font-size: clamp(42px, 6vw, 78px); line-height: .98; letter-spacing: -.055em; }
        .lead { margin: 0; max-width: 700px; color: #aabbd1; font-size: clamp(17px, 2vw, 21px); line-height: 1.7; }
        .hero-actions { margin-top: 28px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .hero-secondary { display: inline-flex; align-items: center; min-height: 44px; padding: 10px 14px; color: #d9e7fb; text-decoration: none; font-weight: 800; border: 1px solid rgba(255,255,255,.14); border-radius: 11px; background: rgba(255,255,255,.035); }
        .hero-secondary:hover, .hero-secondary:focus-visible { border-color: rgba(114,226,209,.55); outline: none; }
        .proof { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 12px; color: #c9d8ec; }
        .proof span { padding: 10px 13px; border: 1px solid rgba(255,255,255,.1); border-radius: 999px; background: rgba(255,255,255,.035); }
        .panel { padding: 28px; border: 1px solid rgba(255,255,255,.1); border-radius: 24px; background: linear-gradient(180deg, rgba(20,35,59,.92), rgba(8,18,32,.92)); box-shadow: 0 30px 80px rgba(0,0,0,.32); }
        .panel-kicker { color: #91a4bf; font-size: 11px; font-weight: 800; letter-spacing: .14em; }
        .panel h2 { margin: 12px 0 18px; font-size: 24px; }
        .rows { display: grid; gap: 10px; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 14px 15px; border-radius: 14px; background: rgba(255,255,255,.045); }
        .row b { color: #72e2d1; }
        .workflow { padding: 12px 0 72px; scroll-margin-top: 24px; }
        .workflow-head { display: flex; justify-content: space-between; align-items: end; gap: 24px; margin-bottom: 20px; }
        .workflow-head h2 { margin: 8px 0 0; font-size: clamp(28px, 4vw, 42px); letter-spacing: -.03em; }
        .workflow-head p { margin: 0; max-width: 600px; color: #91a4bf; line-height: 1.65; }
        .workflow-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .workflow-card { min-height: 150px; padding: 20px; border: 1px solid rgba(255,255,255,.1); border-radius: 18px; background: rgba(255,255,255,.035); }
        .workflow-card b { display: block; color: #72e2d1; font-size: 12px; letter-spacing: .12em; }
        .workflow-card h3 { margin: 16px 0 8px; font-size: 18px; }
        .workflow-card p { margin: 0; color: #9fb0c6; line-height: 1.55; }
        .pricing { padding: 12px 0 80px; }
        .pricing-head { display: flex; justify-content: space-between; align-items: end; gap: 24px; margin-bottom: 24px; }
        .pricing-head h2 { margin: 8px 0 0; font-size: clamp(28px, 4vw, 42px); letter-spacing: -.03em; }
        .pricing-head p { margin: 0; max-width: 620px; color: #91a4bf; line-height: 1.65; }
        .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; }
        .price-card { display: grid; gap: 14px; align-content: start; padding: 22px; border: 1px solid rgba(255,255,255,.1); border-radius: 20px; background: rgba(255,255,255,.035); }
        .price-card h3 { margin: 0; font-size: 22px; }
        .price-card p { margin: 0; color: #9fb0c6; line-height: 1.55; }
        .price { font-size: 24px; font-weight: 850; }
        .price small { font-size: 13px; color: #91a4bf; font-weight: 650; }
        .price-cta { display: inline-flex; justify-content: center; align-items: center; min-height: 44px; padding: 10px 14px; border-radius: 11px; background: #72e2d1; color: #06111e; text-decoration: none; font-weight: 850; }
        .pricing-empty { padding: 18px; border: 1px solid rgba(255,255,255,.1); border-radius: 14px; color: #aabbd1; background: rgba(255,255,255,.03); }
        @media (max-width: 820px) { .hero { grid-template-columns: 1fr; padding: 48px 0; } .panel { order: -1; } .workflow-head, .pricing-head { align-items: start; flex-direction: column; } .workflow-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 560px) { .workflow-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="shell">
        <header class="header">
            <a
                class="brand"
                href="{{ route('public.welcome', [], false) }}"
                data-canonical-operation="AIMW-AI-4C07560F0B"
                aria-label="AI WordPress Manager"
            >
                <span class="brand-mark" aria-hidden="true">AI</span>
                <span class="brand-copy">
                    <strong>AI WordPress Manager</strong>
                    <small>{{ $welcomeLocale === 'ar' ? 'نظام إدارة عمليات ووردبريس' : 'WORDPRESS OPERATIONS OS' }}</small>
                </span>
            </a>
            <a
                class="signin"
                href="#product"
                data-canonical-operation="AIMW-CONT-B1E9E1D6B0"
            >{{ $welcomeLocale === 'ar' ? 'المنتج' : 'Product' }}</a>
            <a
                class="signin"
                href="#workflow"
                data-canonical-operation="AIMW-CONT-EE531AE1FC"
            >{{ $welcomeLocale === 'ar' ? 'طريقة العمل' : 'Workflow' }}</a>
            <a class="signin" data-canonical-operation="AIMW-BILL-3EAD5A351E"
                href="{{ route('public.welcome', ['lang' => $welcomeLocale === 'ar' ? 'en' : 'ar'], false) }}"
                lang="{{ $welcomeLocale === 'ar' ? 'en' : 'ar' }}"
                aria-label="{{ $welcomeLocale === 'ar' ? 'Switch to English' : 'التبديل إلى العربية' }}">{{ $welcomeLocale === 'ar' ? 'EN' : 'AR' }}</a>
            <a class="signin" href="/login">{{ $welcomeLocale === 'ar' ? 'تسجيل الدخول' : 'Sign in' }}</a>
        </header>

        <main
            class="hero"
            id="product"
            data-product-anchor-target="AIMW-CONT-B1E9E1D6B0"
        >
            <section>
                <div class="eyebrow">{{ $welcomeLocale === 'ar' ? 'إدارة ووردبريس بمساعدة الذكاء الاصطناعي' : 'AI-ASSISTED WORDPRESS OPERATIONS' }}</div>
                <h1>{{ $welcomeLocale === 'ar' ? 'أدر ووردبريس كنظام واحد.' : 'Operate WordPress as one system.' }}</h1>
                <p class="lead">{{ $welcomeLocale === 'ar' ? 'بوابة عامة لإدارة ووردبريس بالذكاء الاصطناعي. سجل الدخول للوصول إلى مواقع حسابك والعمليات والمحتوى وتحسين محركات البحث والأتمتة عبر مسارات التطبيق المحمية.' : 'A public, identity-neutral entry point for AI WordPress Manager. Sign in to reach tenant-owned sites, operations, content, SEO and automation through their protected application routes.' }}</p>
                <div class="hero-actions">
                    <a
                        class="hero-secondary"
                        href="#workspace-preview"
                        data-canonical-operation="AIMW-CONT-D4607F943C"
                    >{{ $welcomeLocale === 'ar' ? 'شاهد مساحة العمل' : 'Explore the workspace' }}</a>
                </div>
                <div class="proof" aria-label="{{ $welcomeLocale === 'ar' ? 'حدود المنصة' : 'Platform boundaries' }}">
                    <span>{{ $welcomeLocale === 'ar' ? 'صفحة ترحيب عامة' : 'Public welcome' }}</span>
                    <span>{{ $welcomeLocale === 'ar' ? 'مساحة عمل محمية للحساب' : 'Protected tenant workspace' }}</span>
                    <span>{{ $welcomeLocale === 'ar' ? 'عمليات قابلة للتدقيق' : 'Auditable operations' }}</span>
                </div>
            </section>

            <aside
                class="panel"
                id="workspace-preview"
                data-workspace-preview-anchor-target="AIMW-CONT-D4607F943C"
                aria-label="{{ $welcomeLocale === 'ar' ? 'معاينة مساحة العمل' : 'Workspace preview' }}"
            >
                <div class="panel-kicker">{{ $welcomeLocale === 'ar' ? 'نظرة عامة على العمليات' : 'OPERATIONS OVERVIEW' }}</div>
                <h2>{{ $welcomeLocale === 'ar' ? 'طبقة إدارة واحدة' : 'One operating layer' }}</h2>
                <div class="rows">
                    <div class="row"><span>{{ $welcomeLocale === 'ar' ? 'المواقع' : 'Sites' }}</span><b>{{ $welcomeLocale === 'ar' ? 'متصلة' : 'Connected' }}</b></div>
                    <div class="row"><span>{{ $welcomeLocale === 'ar' ? 'المحتوى' : 'Content' }}</span><b>{{ $welcomeLocale === 'ar' ? 'مُدار' : 'Managed' }}</b></div>
                    <div class="row"><span>{{ $welcomeLocale === 'ar' ? 'تحسين محركات البحث' : 'SEO' }}</span><b>{{ $welcomeLocale === 'ar' ? 'مراقب' : 'Observed' }}</b></div>
                    <div class="row"><span>{{ $welcomeLocale === 'ar' ? 'إجراءات الذكاء الاصطناعي' : 'AI actions' }}</span><b>{{ $welcomeLocale === 'ar' ? 'خاضعة للضوابط' : 'Governed' }}</b></div>
                </div>
            </aside>
        </main>

        <section
            class="workflow"
            id="workflow"
            data-workflow-anchor-target="AIMW-CONT-EE531AE1FC"
            aria-labelledby="workflow-title"
        >
            <div class="workflow-head">
                <div>
                    <div class="eyebrow">{{ $welcomeLocale === 'ar' ? 'من الإشارة إلى التنفيذ' : 'SIGNAL → DECISION → EXECUTION' }}</div>
                    <h2 id="workflow-title">{{ $welcomeLocale === 'ar' ? 'تحرك بسرعة بدون فقدان السيطرة.' : 'Move faster without losing control.' }}</h2>
                </div>
                <p>{{ $welcomeLocale === 'ar'
                    ? 'راقب الحالة، افهم التأثير، راجع التغيير ثم نفّذ مع سجل واضح للنتيجة.'
                    : 'Observe state, understand impact, review the change, then execute with a traceable result.' }}</p>
            </div>
            <div class="workflow-grid">
                <article class="workflow-card">
                    <b>01</b>
                    <h3>{{ $welcomeLocale === 'ar' ? 'راقب' : 'Observe' }}</h3>
                    <p>{{ $welcomeLocale === 'ar' ? 'زامن حالة المواقع والمحتوى والصحة التشغيلية.' : 'Synchronize site, content and operational state.' }}</p>
                </article>
                <article class="workflow-card">
                    <b>02</b>
                    <h3>{{ $welcomeLocale === 'ar' ? 'افهم' : 'Understand' }}</h3>
                    <p>{{ $welcomeLocale === 'ar' ? 'رتب الإشارات حسب التأثير قبل اتخاذ القرار.' : 'Prioritize signals by impact before deciding.' }}</p>
                </article>
                <article class="workflow-card">
                    <b>03</b>
                    <h3>{{ $welcomeLocale === 'ar' ? 'راجع' : 'Review' }}</h3>
                    <p>{{ $welcomeLocale === 'ar' ? 'اعرف ما سيتغير قبل اعتماد الإجراء.' : 'Know what changes before approving the action.' }}</p>
                </article>
                <article class="workflow-card">
                    <b>04</b>
                    <h3>{{ $welcomeLocale === 'ar' ? 'نفّذ' : 'Execute' }}</h3>
                    <p>{{ $welcomeLocale === 'ar' ? 'نفّذ مع حالة وسجل وتشخيص واضح.' : 'Run with status, history and clear diagnostics.' }}</p>
                </article>
            </div>
        </section>

        <section class="pricing" id="pricing" aria-label="{{ $welcomeLocale === 'ar' ? 'خطط الاشتراك' : 'Subscription plans' }}">
            <div class="pricing-head">
                <div>
                    <div class="eyebrow">{{ $welcomeLocale === 'ar' ? 'خطط من كتالوج المنتج' : 'LIVE PRODUCT CATALOG' }}</div>
                    <h2>{{ $welcomeLocale === 'ar' ? 'ابدأ مجانًا ووسّع عند الحاجة.' : 'Start free. Expand when needed.' }}</h2>
                </div>
                <p>{{ $welcomeLocale === 'ar'
                    ? 'الخطة المعروضة تأتي من كتالوج الاشتراكات الفعلي. اختيار الخطة لا يعتبر دليل دفع ولا يغير حالة الاشتراك.'
                    : 'Displayed plans come from the authoritative subscription catalog. Choosing a plan is navigation only and is never treated as payment evidence.' }}</p>
            </div>

            @if ($plans->isEmpty())
                <div class="pricing-empty" role="status">{{ $welcomeLocale === 'ar' ? 'كتالوج الخطط غير متاح مؤقتًا.' : 'The plan catalog is temporarily unavailable.' }}</div>
            @else
                <div class="pricing-grid">
                    @foreach ($plans as $plan)
                        @php
                            $isFreeTrial = strcasecmp((string) $plan->code, 'free-trial') === 0 || $plan->price_minor === 0;
                            $ctaHref = ! $isAuthenticated
                                ? route('register', [], false)
                                : ($isFreeTrial ? '/' : route('public.account-billing.bridge', [], false));
                            $planName = $plan->localized_name[$welcomeLocale] ?? $plan->name;
                            $planDescription = $plan->localized_description[$welcomeLocale] ?? $plan->description ?? '';
                        @endphp
                        <article class="price-card" data-plan-code="{{ $plan->code }}">
                            <h3>{{ $planName }}</h3>
                            @if (filled($planDescription))
                                <p>{{ $planDescription }}</p>
                            @endif
                            <div class="price">
                                @if ($plan->price_minor === 0)
                                    {{ $welcomeLocale === 'ar' ? 'مجاني' : 'Free' }}
                                    @if ($plan->trial_period_days > 0)
                                        <small>{{ $plan->trial_period_days }} {{ $welcomeLocale === 'ar' ? 'يومًا' : 'days' }}</small>
                                    @endif
                                @elseif ($plan->price_minor !== null)
                                    {{ number_format($plan->price_minor / 100, 2) }} {{ $plan->currency }}
                                    <small>/ {{ $plan->billing_interval === 'year' ? ($welcomeLocale === 'ar' ? 'سنة' : 'year') : ($welcomeLocale === 'ar' ? 'شهر' : 'month') }}</small>
                                @else
                                    {{ $welcomeLocale === 'ar' ? 'تواصل معنا' : 'Contact us' }}
                                @endif
                            </div>
                            <a
                                class="price-cta"
                                href="{{ $ctaHref }}"
                                data-canonical-operation="AIMW-BILL-616576D8F7"
                            >{{ $isFreeTrial
                                ? ($welcomeLocale === 'ar' ? 'ابدأ التجربة المجانية' : 'Start free trial')
                                : ($welcomeLocale === 'ar' ? 'اختر الخطة' : 'Choose plan') }}</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</body>
</html>
