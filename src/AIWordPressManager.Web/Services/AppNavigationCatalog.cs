namespace AIWordPressManager.Web.Services;

public static class AppNavigationCatalog
{
    public static IReadOnlyList<AppNavigationGroup> Groups { get; } =
    [
        new(
            "overview",
            "Overview",
            "الرئيسية",
            "Start here, manage sites, and understand the workspace.",
            "ابدأ من هنا وأدر المواقع وافهم مساحة العمل.",
            [
                Item("✦", "Welcome", "مرحبًا", "Product orientation and first steps.", "التعريف بالمنتج والخطوات الأولى.", "/welcome", "start getting started intro", "بداية تعريف"),
                Item("⌂", "Dashboard", "لوحة التحكم", "Cross-site operational summary.", "ملخص تشغيلي لكل المواقع.", "/", "home overview metrics", "الرئيسية ملخص مؤشرات"),
                Item("◉", "Sites", "المواقع", "Manage connected WordPress sites.", "إدارة مواقع WordPress المتصلة.", "/sites", "websites wordpress connections", "مواقع ووردبريس اتصال"),
                Item("＋", "Connect Site", "إضافة موقع", "Connect another WordPress site.", "ربط موقع WordPress جديد.", "/sites/connect", "add website new site", "اضافة موقع جديد", showInSidebar: false),
                Item("▦", "System Overview", "مركز النظام", "High-level platform status and modules.", "نظرة عامة على حالة المنصة والوحدات.", "/module/overview", "system modules platform", "النظام الوحدات المنصة")
            ]),
        new(
            "content",
            "Content",
            "المحتوى",
            "Create, edit, organize, and moderate WordPress content.",
            "إنشاء وتحرير وتنظيم وإدارة محتوى WordPress.",
            [
                Item("▦", "Content Hub", "مركز المحتوى", "Central content workspace.", "مساحة العمل المركزية للمحتوى.", "/content", "content center hub", "المحتوى المركز"),
                Item("▤", "Posts", "المقالات", "Create and manage posts.", "إنشاء وإدارة المقالات.", "/module/posts", "articles blog posts", "مقالات تدوينات"),
                Item("▧", "Pages", "الصفحات", "Create and manage pages.", "إنشاء وإدارة الصفحات.", "/module/pages", "pages static content", "صفحات محتوى ثابت"),
                Item("▣", "Media", "الوسائط", "Manage the WordPress media library.", "إدارة مكتبة وسائط WordPress.", "/module/media", "images files uploads library", "صور ملفات رفع مكتبة"),
                Item("#", "Categories & Tags", "التصنيفات والوسوم", "Manage content taxonomy.", "إدارة تصنيفات ووسوم المحتوى.", "/module/taxonomy", "taxonomy categories tags", "تصنيفات وسوم"),
                Item("◌", "Comments", "التعليقات", "Review and moderate comments.", "مراجعة وإدارة التعليقات.", "/module/comments", "comments moderation", "تعليقات مراجعة"),
                Item("◎", "WordPress Users", "مستخدمو WordPress", "Manage users on connected WordPress sites.", "إدارة مستخدمي مواقع WordPress المتصلة.", "/module/users", "wordpress users authors editors", "مستخدمي ووردبريس كتاب محررين")
            ]),
        new(
            "seo",
            "SEO & Approvals",
            "SEO والموافقات",
            "Audit search visibility, review suggestions, and approve changes.",
            "تدقيق الظهور ومراجعة الاقتراحات والموافقة على التغييرات.",
            [
                Item("◈", "SEO Audit", "تدقيق SEO", "Find technical and content SEO issues.", "اكتشاف مشكلات SEO التقنية والمحتوى.", "/module/seo-audit", "search audit optimization issues", "سيو تدقيق تحسين مشكلات"),
                Item("✦", "SEO Suggestions", "اقتراحات SEO", "Review prioritized optimization suggestions.", "مراجعة اقتراحات التحسين حسب الأولوية.", "/module/seo-suggestions", "recommendations optimize suggestions", "اقتراحات توصيات تحسين"),
                Item("✓", "Approval Queue", "قائمة الموافقات", "Review governed changes before execution.", "مراجعة التغييرات المحكومة قبل التنفيذ.", "/module/approvals", "approve review pending changes", "موافقة مراجعة تغييرات")
            ]),
        new(
            "ai",
            "AI Workspace",
            "الذكاء الاصطناعي",
            "Generate, plan, configure, and observe AI-assisted work.",
            "إنشاء وتخطيط وضبط ومراقبة العمل المدعوم بالذكاء الاصطناعي.",
            [
                Item("✦", "AI Center", "مركز الذكاء الاصطناعي", "Generate reviewable AI suggestions.", "إنشاء اقتراحات ذكاء اصطناعي قابلة للمراجعة.", "/ai-center", "assistant generate suggestions ai", "ذكاء اقتراحات توليد"),
                Item("◫", "Content Planner", "مخطط المحتوى", "Move ideas through brief, draft, and review.", "تحويل الأفكار إلى ملخصات ومسودات ومراجعة.", "/content-planner", "planner ideas brief draft calendar", "مخطط افكار ملخص مسودة"),
                Item("◈", "AI Providers", "مزودو الذكاء", "Review available AI providers and runtime status.", "مراجعة مزودي الذكاء وحالة التشغيل.", "/module/ai-providers", "providers models openai ai", "مزودين نماذج ذكاء"),
                Item("⌘", "Prompt Templates", "قوالب الأوامر", "Manage reusable prompt templates.", "إدارة قوالب الأوامر القابلة لإعادة الاستخدام.", "/module/prompts", "prompt templates instructions", "برومبت قوالب اوامر تعليمات"),
                Item("▥", "AI Usage & Cost", "استخدام وتكلفة الذكاء", "Inspect tenant-scoped AI usage and estimated cost.", "مراجعة استخدام الذكاء والتكلفة التقديرية للحساب.", "/module/ai-usage", "usage tokens cost telemetry", "استخدام توكن تكلفة")
            ]),
        new(
            "operations",
            "Automation & Operations",
            "الأتمتة والتشغيل",
            "Run automations, jobs, synchronization, schedules, and operational diagnostics.",
            "تشغيل الأتمتة والمهام والمزامنة والجداول والتشخيصات التشغيلية.",
            [
                Item("⚡", "Automation Center", "مركز الأتمتة", "Create and manage controlled automation workflows.", "إنشاء وإدارة تدفقات الأتمتة المحكومة.", "/automation-center", "automation rules workflows recurring", "اتمتة قواعد تدفقات متكرر"),
                Item("▦", "Operations Hub", "مركز العمليات", "Operational overview across connected sites.", "نظرة تشغيلية شاملة على المواقع المتصلة.", "/operations", "operations monitoring hub", "عمليات مراقبة مركز"),
                Item("≣", "Site Operations", "عمليات المواقع", "Inspect site operation history.", "مراجعة سجل عمليات المواقع.", "/site-operations", "site history actions operations", "موقع سجل عمليات"),
                Item("◒", "Site Reliability", "موثوقية المواقع", "Compare connectivity and synchronization reliability.", "مقارنة موثوقية الاتصال والمزامنة.", "/site-reliability", "reliability connection uptime sync", "موثوقية اتصال مزامنة"),
                Item("▶", "Execution Center", "مركز التنفيذ", "Review queued, running, failed, and completed jobs.", "مراجعة المهام المنتظرة والجارية والفاشلة والمكتملة.", "/module/execution", "jobs queue retry execution tasks", "مهام طابور اعادة تنفيذ"),
                Item("↻", "Synchronization", "المزامنة", "Refresh local WordPress data.", "تحديث بيانات WordPress المحلية.", "/module/sync", "sync refresh cache wordpress", "مزامنة تحديث كاش"),
                Item("◷", "Schedules", "الجدولة", "Manage scheduled operations.", "إدارة العمليات المجدولة.", "/module/schedules", "schedule recurring timer cron", "جدولة متكرر وقت"),
                Item("●", "Notification Inbox", "صندوق الإشعارات", "Review operational and workflow notifications.", "مراجعة إشعارات التشغيل وسير العمل.", "/notifications", "notifications inbox alerts messages", "اشعارات تنبيهات رسائل"),
                Item("✉", "Email Delivery History", "سجل إرسال البريد", "Inspect application email delivery history.", "مراجعة سجل إرسال البريد من التطبيق.", "/email/history", "email delivery history messages", "بريد ارسال سجل رسائل")
            ]),
        new(
            "reports",
            "Reports & Insights",
            "التقارير والرؤى",
            "Review trends and export operational evidence.",
            "مراجعة الاتجاهات وتصدير الأدلة التشغيلية.",
            [
                Item("▥", "Reports & Exports", "التقارير والتصدير", "Build and export operational reports.", "إنشاء وتصدير التقارير التشغيلية.", "/module/reports", "reports export csv pdf excel insights", "تقارير تصدير رؤى")
            ]),
        new(
            "system",
            "System & Account",
            "النظام والحساب",
            "Manage health, diagnostics, backups, preferences, and account administration.",
            "إدارة الصحة والتشخيصات والنسخ الاحتياطي والتفضيلات والحساب.",
            [
                Item("♥", "System Health", "صحة النظام", "Check application, database, WordPress, and provider health.", "فحص صحة التطبيق وقاعدة البيانات وWordPress والمزودين.", "/system-health", "health status diagnostics database", "صحة حالة تشخيص قاعدة"),
                Item("≡", "Logs & Errors", "السجلات والأخطاء", "Inspect actionable diagnostics and failures.", "مراجعة التشخيصات والأخطاء القابلة للتنفيذ.", "/module/logs", "logs errors diagnostics failures", "سجلات اخطاء تشخيص"),
                Item("⬡", "Backup & Restore", "النسخ الاحتياطي والاستعادة", "Protect and restore local application data.", "حماية واستعادة بيانات التطبيق المحلية.", "/module/backups", "backup restore recovery retention", "نسخ احتياطي استعادة"),
                Item("⚙", "Settings", "الإعدادات", "Configure language, appearance, accessibility, and preferences.", "ضبط اللغة والمظهر وسهولة الاستخدام والتفضيلات.", "/settings", "preferences theme language accessibility", "اعدادات لغة مظهر سهولة"),
                Item("◎", "My Account", "حسابي", "Review profile and account security.", "مراجعة الملف الشخصي وأمان الحساب.", "/account/profile", "profile password security account", "حساب ملف كلمة مرور امان", showInSidebar: false),
                Item("◇", "Subscription & Billing", "الاشتراك والفوترة", "Review the current plan, subscription state, configured limits, and billing lifecycle.", "مراجعة الخطة الحالية وحالة الاشتراك والحدود المكوّنة ودورة الفوترة.", "/account/billing", "subscription billing plan paypal limits renewal account", "اشتراك فوترة خطة باي بال حدود تجديد حساب"),
                Item("◇", "Subscription Plans", "خطط الاشتراك", "Manage pricing, trial limits, plan entitlements, and payment provider mappings.", "إدارة الأسعار وحدود التجربة وصلاحيات الخطط وربط بوابات الدفع.", "/admin/subscription-plans", "admin subscription plans pricing trial limits entitlements billing", "ادمن اشتراك خطط اسعار تجربة حدود صلاحيات فوترة", administratorOnly: true),
                Item("✉", "Account Email Settings", "إعدادات بريد الحساب", "Configure dashboard email delivery for this account.", "ضبط إرسال بريد لوحة التحكم لهذا الحساب.", "/account/email-settings", "account email smtp dashboard", "حساب بريد ارسال", showInSidebar: false),
                Item("◎", "Application Users", "مستخدمو التطبيق", "Administer local application users and roles.", "إدارة مستخدمي التطبيق المحليين والأدوار.", "/admin/application-users", "admin users roles accounts", "ادمن مستخدمين ادوار حسابات", administratorOnly: true),
                Item("⚿", "Roles & Permissions", "الأدوار والصلاحيات", "Review custom roles, grants, and user assignments.", "مراجعة الأدوار المخصصة والصلاحيات وتعيينات المستخدمين.", "/admin/roles-permissions", "admin roles permissions access authorization grants", "ادمن ادوار صلاحيات وصول تفويض", administratorOnly: true),
                Item("AI", "AI Provider Settings", "إعدادات مزودي الذكاء", "Configure provider keys, models, and priority.", "ضبط مفاتيح ونماذج وأولوية مزودي الذكاء.", "/settings/ai-providers", "admin ai provider keys models", "ادمن ذكاء مفاتيح نماذج", showInSidebar: false, administratorOnly: true),
                Item("✦", "AI Prompt Administration", "إدارة قوالب أوامر الذكاء", "Administer prompt versions and restore points.", "إدارة إصدارات قوالب الأوامر ونقاط الاستعادة.", "/settings/ai-prompts", "admin prompts versions restore", "ادمن برومبت اصدارات استعادة", showInSidebar: false, administratorOnly: true),
                Item("ⓘ", "Build Information", "معلومات الإصدار", "Inspect version, branch, commit, and build identity.", "مراجعة النسخة والفرع والـcommit وهوية البناء.", "/about-build", "version branch commit build", "نسخة فرع بناء")
            ])
    ];

    public static IEnumerable<AppNavigationItem> AllItems => Groups.SelectMany(group => group.Items);

    public static AppNavigationGroup? FindGroup(string path, bool isAdministrator = false) =>
        FindItem(path, isAdministrator) is { } item
            ? Groups.FirstOrDefault(group => string.Equals(group.Key, item.GroupKey, StringComparison.Ordinal))
            : null;

    public static AppNavigationItem? FindItem(string path, bool isAdministrator = false) =>
        VisibleItems(isAdministrator)
            .Where(item => item.MatchesPath(path))
            .OrderByDescending(item => item.Path.Length)
            .FirstOrDefault();

    public static IEnumerable<AppNavigationItem> VisibleItems(bool isAdministrator) =>
        AllItems.Where(item => !item.AdministratorOnly || isAdministrator);

    private static AppNavigationItem Item(
        string icon,
        string englishName,
        string arabicName,
        string englishDescription,
        string arabicDescription,
        string path,
        string englishKeywords,
        string arabicKeywords,
        bool showInSidebar = true,
        bool administratorOnly = false) =>
        new(icon, englishName, arabicName, englishDescription, arabicDescription, path, englishKeywords, arabicKeywords, showInSidebar, administratorOnly);

    static AppNavigationCatalog()
    {
        foreach (var group in Groups)
        {
            foreach (var item in group.Items)
                item.GroupKey = group.Key;
        }
    }
}

public sealed record AppNavigationGroup(
    string Key,
    string EnglishName,
    string ArabicName,
    string EnglishDescription,
    string ArabicDescription,
    IReadOnlyList<AppNavigationItem> Items);

public sealed record AppNavigationItem(
    string Icon,
    string EnglishName,
    string ArabicName,
    string EnglishDescription,
    string ArabicDescription,
    string Path,
    string EnglishKeywords,
    string ArabicKeywords,
    bool ShowInSidebar,
    bool AdministratorOnly)
{
    public string GroupKey { get; internal set; } = string.Empty;

    public bool MatchesPath(string path)
    {
        if (Path == "/")
            return string.Equals(path, "/", StringComparison.OrdinalIgnoreCase);

        return string.Equals(path, Path, StringComparison.OrdinalIgnoreCase) ||
               path.StartsWith(Path + "/", StringComparison.OrdinalIgnoreCase);
    }

    public bool MatchesSearch(string query)
    {
        if (string.IsNullOrWhiteSpace(query))
            return true;

        var term = query.Trim();
        return EnglishName.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               ArabicName.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               EnglishDescription.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               ArabicDescription.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               EnglishKeywords.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               ArabicKeywords.Contains(term, StringComparison.OrdinalIgnoreCase) ||
               Path.Contains(term, StringComparison.OrdinalIgnoreCase);
    }
}