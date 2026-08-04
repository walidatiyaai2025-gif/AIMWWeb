using System.Globalization;
using System.Text.RegularExpressions;

namespace AIWordPressManager.Web.Localization;

public sealed class AppLanguageService
{
    private readonly Dictionary<string, (string Ar, string En)> _texts = new(StringComparer.OrdinalIgnoreCase)
    {
        ["AppName"]=("مدير ووردبريس الذكي","AI WordPress Manager"), ["BlazorServer"]=("Blazor Server","Blazor Server"),
        ["Dashboard"]=("لوحة التحكم","Dashboard"), ["Sites"]=("المواقع","Sites"), ["Settings"]=("الإعدادات","Settings"),
        ["LocalSystemRunning"]=("النظام يعمل محليًا","System is running locally"), ["BrowserManagement"]=("إدارة مواقع WordPress من المتصفح","Manage WordPress sites from your browser"),
        ["Administrator"]=("المسؤول","Administrator"), ["LocalAccount"]=("حساب محلي","Local Account"), ["SwitchLanguage"]=("English","العربية"),
        ["DashboardIntro"]=("نظرة سريعة على حالة مواقع WordPress المسجلة.","A quick overview of your registered WordPress sites."), ["ManageSites"]=("إدارة المواقع","Manage Sites"),
        ["TotalSites"]=("إجمالي المواقع","Total Sites"), ["AllRegisteredSites"]=("كل المواقع المسجلة","All registered sites"), ["Connected"]=("متصلة","Connected"), ["HealthyConnection"]=("اتصال سليم","Healthy connection"),
        ["NeedsReview"]=("تحتاج مراجعة","Needs Review"), ["ConnectionOrLoginFailed"]=("فشل اتصال أو دخول","Connection or login failed"), ["LastCheck"]=("آخر فحص","Last Check"), ["LocalDatabase"]=("حسب قاعدة البيانات المحلية","From the local database"),
        ["CurrentPhase"]=("المرحلة الحالية","Current Phase"), ["BlazorCreated"]=("تم فصل واجهة WPF وإنشاء تطبيق Blazor Server حقيقي.","The WPF interface was separated and a real Blazor Server application was created."),
        ["DashboardSitesDone"]=("لوحة التحكم وإدارة المواقع","Dashboard and site management"), ["WpRestTest"]=("اختبار WordPress REST API","WordPress REST API testing"), ["ContentSync"]=("مزامنة المحتوى","Content synchronization"), ["SeoAi"]=("SEO وأتمتة الذكاء الاصطناعي","SEO and AI automation"),
        ["Never"]=("لم يتم","Never"), ["SitesIntro"]=("إضافة وإدارة مواقع WordPress المحفوظة في SQLite.","Add and manage WordPress sites stored in SQLite."), ["AddSite"]=("إضافة موقع","Add Site"),
        ["AddNewSite"]=("إضافة موقع جديد","Add New Site"), ["SiteName"]=("اسم الموقع","Site Name"), ["SiteUrl"]=("رابط الموقع","Site URL"), ["Save"]=("حفظ","Save"), ["Saving"]=("جارٍ الحفظ...","Saving..."), ["Cancel"]=("إلغاء","Cancel"),
        ["LoadingSites"]=("جارٍ تحميل المواقع...","Loading sites..."), ["NoSites"]=("لا توجد مواقع","No sites found"), ["AddFirstSite"]=("أضف أول موقع WordPress لبدء الإدارة.","Add your first WordPress site to get started."),
        ["Site"]=("الموقع","Site"), ["Url"]=("الرابط","URL"), ["Status"]=("الحالة","Status"), ["LastTest"]=("آخر اختبار","Last Test"), ["Actions"]=("الإجراءات","Actions"), ["Open"]=("فتح","Open"), ["Delete"]=("حذف","Delete"),
        ["AddedSuccess"]=("تمت إضافة الموقع بنجاح.","Site added successfully."), ["DeletedSuccess"]=("تم حذف الموقع.","Site deleted."), ["Unreachable"]=("غير متاح","Unreachable"), ["AuthFailed"]=("فشل الدخول","Authentication failed"), ["NotTested"]=("غير مفحوص","Not tested"),
        ["SiteDetails"]=("تفاصيل الموقع","Site Details"), ["LoadingSite"]=("جارٍ تحميل بيانات الموقع...","Loading site details..."), ["SiteNotFound"]=("الموقع غير موجود","Site not found"), ["BackToSites"]=("العودة إلى المواقع","Back to Sites"),
        ["ContentExplorer"]=("مستكشف المحتوى","Content Explorer"), ["Testing"]=("جارٍ الاختبار...","Testing..."), ["Retest"]=("إعادة اختبار الاتصال","Retest Connection"), ["Back"]=("رجوع","Back"),
        ["ConnectionStatus"]=("حالة الاتصال","Connection Status"), ["WordPressVersion"]=("إصدار WordPress","WordPress Version"), ["NotDetected"]=("غير مكتشف","Not detected"), ["FromRestApi"]=("من REST API","From REST API"), ["Language"]=("اللغة","Language"), ["SiteLanguage"]=("لغة الموقع","Site language"),
        ["Credentials"]=("بيانات الدخول","Credentials"), ["Saved"]=("محفوظة","Saved"), ["NotSaved"]=("غير محفوظة","Not saved"), ["WordPressConnection"]=("اتصال WordPress","WordPress Connection"),
        ["WpCredentialHelp"]=("استخدم اسم مستخدم WordPress وApplication Password، وليس كلمة مرور الحساب العادية.","Use a WordPress username and an Application Password, not the normal account password."), ["Username"]=("اسم المستخدم","Username"),
        ["SaveAndTesting"]=("جارٍ الحفظ والاختبار...","Saving and testing..."), ["SaveAndTest"]=("حفظ واختبار الاتصال","Save and Test Connection"), ["HowCreateAppPassword"]=("كيفية إنشاء Application Password","How to Create an Application Password"),
        ["AppPasswordSteps"]=("من لوحة WordPress افتح: المستخدمون ← الملف الشخصي ← Application Passwords، اكتب اسمًا مثل AIWordPressManager ثم أنشئ كلمة المرور وانسخها هنا.","In WordPress, open Users → Profile → Application Passwords, enter a name such as AIWordPressManager, create the password, then copy it here."),
        ["NeverTested"]=("لم يتم الاختبار","Never tested"), ["LoadingLocalContent"]=("جارٍ تحميل المحتوى المحلي...","Loading local content..."), ["WordPressExplorer"]=("مستكشف WordPress","WordPress Explorer"),
        ["ExplorerIntro"]=("البيانات تُعرض من SQLite بعد المزامنة.","Data is displayed from SQLite after synchronization."), ["Syncing"]=("جارٍ المزامنة...","Synchronizing..."), ["SyncNow"]=("مزامنة الآن","Sync Now"), ["SiteDetailsButton"]=("تفاصيل الموقع","Site Details"),
        ["Posts"]=("المقالات","Posts"), ["Pages"]=("الصفحات","Pages"), ["CategoriesTags"]=("التصنيفات والوسوم","Categories and Tags"), ["Category"]=("تصنيف","category"), ["Tag"]=("وسم","tag"), ["Media"]=("الوسائط","Media"), ["LastSync"]=("آخر مزامنة","Last sync"),
        ["Content"]=("المحتوى","Content"), ["Categories"]=("التصنيفات","Categories"), ["Tags"]=("الوسوم","Tags"), ["SearchPlaceholder"]=("ابحث بالعنوان أو الرابط المختصر...","Search by title or slug..."), ["All"]=("الكل","All"), ["Search"]=("بحث","Search"),
        ["NoLocalData"]=("لا توجد بيانات محلية بعد","No local data yet"), ["SyncPrompt"]=("اضغط «مزامنة الآن» لجلب محتوى WordPress وحفظه محليًا.","Click “Sync Now” to fetch WordPress content and store it locally."),
        ["Type"]=("النوع","Type"), ["Title"]=("العنوان","Title"), ["LastModified"]=("آخر تعديل","Last Modified"), ["Post"]=("مقال","Post"), ["Page"]=("صفحة","Page"), ["Name"]=("الاسم","Name"), ["PostCount"]=("عدد المقالات","Post Count"), ["OpenFile"]=("فتح الملف ↗","Open File ↗"), ["File"]=("ملف","FILE"),
        ["SettingsIntro"]=("إعدادات تطبيق الويب المحلي.","Local web application settings."), ["Database"]=("قاعدة البيانات","Database"), ["PortableDb"]=("يعمل التطبيق في Portable Mode، وتُنشأ قاعدة SQLite تلقائيًا داخل مجلد Data بجوار ملفات التشغيل.","The application runs in Portable Mode, and the SQLite database is created automatically in the Data folder beside the application files."),
        ["LanguageSettings"]=("اللغة والاتجاه","Language and Direction"), ["LanguageSettingsText"]=("يمكن التبديل بين العربية والإنجليزية، مع تغيير اتجاه الواجهة تلقائيًا وحفظ الاختيار على هذا المتصفح.","Switch between Arabic and English. The interface direction changes automatically and your choice is saved in this browser."),
        ["TaxonomyManager"]=("إدارة التصنيفات والوسوم","Taxonomy Manager"), ["TaxonomyIntro"]=("إنشاء وتعديل وحذف التصنيفات والوسوم مباشرة في WordPress.","Create, edit, and delete categories and tags directly in WordPress."),
        ["ManageTaxonomies"]=("إدارة التصنيفات والوسوم","Manage Taxonomies"), ["LoadingTaxonomies"]=("جارٍ تحميل التصنيفات والوسوم...","Loading categories and tags..."), ["SearchTaxonomy"]=("ابحث بالاسم أو الرابط المختصر...","Search by name or slug..."),
        ["AddNew"]=("إضافة جديد","Add New"), ["CreateTaxonomy"]=("إنشاء عنصر جديد","Create New Term"), ["EditTaxonomy"]=("تعديل العنصر","Edit Term"), ["Description"]=("الوصف","Description"), ["ParentCategory"]=("التصنيف الأب","Parent Category"), ["NoParent"]=("بدون تصنيف أب","No Parent"), ["NoTaxonomies"]=("لا توجد عناصر مطابقة.","No matching terms found."),
        ["RequiredSiteName"]=("اسم الموقع مطلوب.","Site name is required."), ["RequiredUrl"]=("رابط الموقع مطلوب.","Site URL is required."), ["InvalidUrl"]=("أدخل رابطًا صحيحًا يبدأ بـ http أو https.","Enter a valid URL starting with http or https."),
        ["RequiredUsername"]=("اسم المستخدم مطلوب.","Username is required."), ["RequiredPassword"]=("Application Password مطلوب.","Application Password is required."), ["ShortPassword"]=("كلمة المرور قصيرة جدًا.","The password is too short."),
        ["Edit"]=("تعديل","Edit"), ["EditContent"]=("تعديل المحتوى","Edit Content"), ["LoadingContent"]=("جارٍ تحميل المحتوى من WordPress...","Loading content from WordPress..."),
        ["ContentNotFound"]=("تعذر تحميل المحتوى","Content could not be loaded"), ["BackToExplorer"]=("العودة إلى مستكشف المحتوى","Back to Content Explorer"), ["ViewOnWordPress"]=("عرض في WordPress","View on WordPress"),
        ["Slug"]=("الرابط المختصر","Slug"), ["ContentHtml"]=("المحتوى HTML","HTML Content"), ["Excerpt"]=("المقتطف","Excerpt"), ["Publishing"]=("النشر","Publishing"),
        ["Draft"]=("مسودة","Draft"), ["PendingReview"]=("قيد المراجعة","Pending Review"), ["Published"]=("منشور","Published"), ["Scheduled"]=("مجدول","Scheduled"), ["Private"]=("خاص","Private"),
        ["PublishDateUtc"]=("تاريخ النشر","Publish Date"), ["CommentStatus"]=("حالة التعليقات","Comment Status"), ["PingStatus"]=("حالة التنبيهات","Ping Status"), ["OpenStatus"]=("مفتوح","Open"), ["ClosedStatus"]=("مغلق","Closed"),
        ["StickyPost"]=("تثبيت المقال","Sticky Post"), ["SaveToWordPress"]=("حفظ في WordPress","Save to WordPress"), ["ContentSettings"]=("إعدادات المحتوى","Content Settings"),
        ["FeaturedMediaId"]=("رقم الصورة البارزة","Featured Media ID"), ["Template"]=("القالب","Template"), ["CategoriesIds"]=("معرّفات التصنيفات","Category IDs"), ["TagsIds"]=("معرّفات الوسوم","Tag IDs"),
        ["CommaSeparatedIds"]=("اكتب الأرقام مفصولة بفواصل.","Enter IDs separated by commas."), ["Format"]=("التنسيق","Format"),
        ["MediaManager"]=("إدارة الوسائط","Media Manager"), ["ManageMedia"]=("إدارة الوسائط","Manage Media"), ["MediaManagerIntro"]=("رفع الملفات وإدارة مكتبة وسائط WordPress.","Upload files and manage the WordPress media library."),
        ["LoadingMedia"]=("جارٍ تحميل مكتبة الوسائط...","Loading media library..."), ["Refresh"]=("تحديث","Refresh"), ["UploadMedia"]=("رفع ملف جديد","Upload New File"),
        ["UploadMediaHelp"]=("الحد الأقصى للملف 25 MB. يعتمد القبول النهائي على إعدادات WordPress والاستضافة.","Maximum file size is 25 MB. Final acceptance depends on WordPress and hosting settings."),
        ["ChooseFile"]=("اختيار الملف","Choose File"), ["AltText"]=("النص البديل","Alt Text"), ["Caption"]=("التسمية التوضيحية","Caption"), ["Uploading"]=("جارٍ الرفع...","Uploading..."),
        ["UploadToWordPress"]=("رفع إلى WordPress","Upload to WordPress"), ["MediaLibrary"]=("مكتبة الوسائط","Media Library"), ["MediaCount"]=("{0} ملف","{0} files"),
        ["SearchMedia"]=("ابحث في الوسائط...","Search media..."), ["NoMedia"]=("لا توجد وسائط","No media found"), ["NoMediaHelp"]=("ارفع ملفًا جديدًا أو حدّث المزامنة.","Upload a new file or refresh synchronization."),
        ["Delete"]=("حذف","Delete"), ["UntitledMedia"]=("وسائط بدون عنوان","Untitled media"), ["Unknown"]=("غير معروف","Unknown"), ["FeaturedImage"]=("الصورة البارزة","Featured Image"), ["RemoveSelection"]=("إزالة الاختيار","Remove Selection"),
        ["CommentsManager"]=("إدارة التعليقات","Comments Manager"), ["ManageComments"]=("إدارة التعليقات","Manage Comments"), ["CommentsIntro"]=("مراجعة التعليقات والرد عليها وإدارة حالتها مباشرة في WordPress.","Review, reply to, and moderate comments directly in WordPress."),
        ["LoadingComments"]=("جارٍ تحميل التعليقات...","Loading comments..."), ["Comments"]=("التعليقات","Comments"), ["CurrentFilter"]=("حسب الفلتر الحالي","For the current filter"), ["CurrentPage"]=("الصفحة الحالية","Current Page"), ["OfPages"]=("من","of"),
        ["SearchComments"]=("ابحث في اسم الكاتب أو البريد أو نص التعليق...","Search author, email, or comment text..."), ["Approved"]=("معتمد","Approved"), ["PendingComment"]=("قيد المراجعة","Pending"), ["Spam"]=("مزعج","Spam"), ["Trash"]=("سلة المهملات","Trash"),
        ["CouldNotLoadComments"]=("تعذر تحميل التعليقات","Could not load comments"), ["NoComments"]=("لا توجد تعليقات","No comments found"), ["NoCommentsHelp"]=("غيّر الفلتر أو عبارة البحث ثم أعد المحاولة.","Change the filter or search text and try again."), ["Anonymous"]=("مجهول","Anonymous"),
        ["PostId"]=("رقم المقال","Post ID"), ["CommentId"]=("رقم التعليق","Comment ID"), ["Approve"]=("اعتماد","Approve"), ["Unapprove"]=("إلغاء الاعتماد","Unapprove"), ["MarkSpam"]=("تحديد كمزعج","Mark as Spam"), ["MoveToTrash"]=("نقل للمهملات","Move to Trash"), ["DeletePermanently"]=("حذف نهائي","Delete Permanently"),
        ["Reply"]=("رد","Reply"), ["WriteReply"]=("اكتب الرد...","Write a reply..."), ["SendReply"]=("إرسال الرد","Send Reply"), ["ReplyRequired"]=("نص الرد مطلوب.","Reply text is required."), ["Previous"]=("السابق","Previous"), ["Next"]=("التالي","Next"), ["Page"]=("صفحة","Page"), ["Of"]=("من","of"),
        ["UsersManager"]=("إدارة المستخدمين والأدوار","Users and Roles Manager"), ["ManageUsers"]=("إدارة المستخدمين","Manage Users"), ["UsersIntro"]=("إنشاء المستخدمين وتعديل بياناتهم وأدوارهم مباشرة في WordPress.","Create users and manage their profiles and roles directly in WordPress."),
        ["LoadingUsers"]=("جارٍ تحميل المستخدمين...","Loading users..."), ["Users"]=("المستخدمون","Users"), ["SearchUsers"]=("ابحث بالاسم أو اسم المستخدم أو البريد...","Search name, username, or email..."), ["AllRoles"]=("كل الأدوار","All Roles"), ["AddUser"]=("إضافة مستخدم","Add User"),
        ["CouldNotLoadUsers"]=("تعذر تحميل المستخدمين","Could not load users"), ["NoUsers"]=("لا توجد نتائج","No users found"), ["NoUsersHelp"]=("غيّر البحث أو فلتر الدور ثم أعد المحاولة.","Change the search or role filter and try again."), ["User"]=("المستخدم","User"), ["Email"]=("البريد الإلكتروني","Email"), ["Roles"]=("الأدوار","Roles"), ["Registered"]=("تاريخ التسجيل","Registered"),
        ["Disable"]=("تعطيل","Disable"), ["CreateUser"]=("إنشاء مستخدم","Create User"), ["EditUser"]=("تعديل المستخدم","Edit User"), ["Password"]=("كلمة المرور","Password"), ["NewPasswordOptional"]=("كلمة مرور جديدة (اختياري)","New Password (Optional)"), ["DisplayName"]=("الاسم الظاهر","Display Name"), ["FirstName"]=("الاسم الأول","First Name"), ["LastName"]=("اسم العائلة","Last Name"), ["Website"]=("الموقع الشخصي","Website"), ["Role"]=("الدور","Role"),
        ["RoleAdministrator"]=("مدير","Administrator"), ["RoleEditor"]=("محرر","Editor"), ["RoleAuthor"]=("كاتب","Author"), ["RoleContributor"]=("مساهم","Contributor"), ["RoleSubscriber"]=("مشترك","Subscriber"),
        ["SeoManager"]=("مدير تحسين محركات البحث","SEO Manager"), ["SeoIntro"]=("تحليل محتوى WordPress المحفوظ محليًا واكتشاف مشكلات SEO القابلة للإصلاح.","Analyze locally synchronized WordPress content and detect actionable SEO issues."),
        ["ManageSeo"]=("إدارة SEO","Manage SEO"), ["AnalyzingSeo"]=("جارٍ تحليل المحتوى لمحركات البحث...","Analyzing content for search engines..."), ["AverageSeoScore"]=("متوسط تقييم SEO","Average SEO Score"),
        ["GoodContent"]=("محتوى جيد","Good Content"), ["Score80Plus"]=("تقييم 80 فأعلى","Score 80 or higher"), ["NeedsImprovement"]=("يحتاج تحسين","Needs Improvement"), ["Score50To79"]=("تقييم من 50 إلى 79","Score from 50 to 79"),
        ["SeoIssues"]=("مشكلات SEO","SEO Issues"), ["PoorItems"]=("عناصر ضعيفة","poor items"), ["Analyze"]=("تحليل","Analyze"), ["NoContentToAnalyze"]=("لا يوجد محتوى لتحليله","No content to analyze"),
        ["SyncBeforeSeo"]=("زامن الموقع أولًا من مستكشف المحتوى ثم أعد التحليل.","Synchronize the site from Content Explorer, then run the analysis again."), ["Score"]=("التقييم","Score"), ["SeoMetrics"]=("مؤشرات SEO","SEO Metrics"),
        ["DetectedIssues"]=("المشكلات المكتشفة","Detected Issues"), ["Words"]=("الكلمات","Words"), ["Headings"]=("العناوين الداخلية","Headings"), ["InternalLinks"]=("الروابط الداخلية","Internal Links"), ["Images"]=("الصور","Images"),
        ["NoSeoIssues"]=("لا توجد مشكلات واضحة","No obvious issues"), ["Fix"]=("إصلاح","Fix"), ["Untitled"]=("بدون عنوان","Untitled"),
        ["MissingTitle"]=("العنوان مفقود","Missing title"), ["ShortTitle"]=("العنوان قصير","Title is too short"), ["LongTitle"]=("العنوان طويل","Title is too long"),
        ["MissingDescription"]=("الوصف التعريفي مفقود","Meta description is missing"), ["LongDescription"]=("الوصف طويل","Description is too long"), ["ThinContent"]=("المحتوى قصير","Thin content"),
        ["MissingHeadings"]=("لا توجد عناوين داخلية","No headings found"), ["MissingInternalLinks"]=("لا توجد روابط داخلية","No internal links found"), ["ImagesMissingAlt"]=("صور بدون نص بديل","Images missing alt text"), ["MissingSlug"]=("الرابط المختصر مفقود","Slug is missing"),
    };

    public string Culture { get; private set; } = "ar";
    public bool IsArabic => Culture == "ar";
    public string Direction => IsArabic ? "rtl" : "ltr";
    public string this[string key] => _texts.TryGetValue(key, out var value) ? (IsArabic ? value.Ar : value.En) : key;
    public event Action? Changed;

    public void SetCulture(string? culture)
    {
        var next = string.Equals(culture, "en", StringComparison.OrdinalIgnoreCase) ? "en" : "ar";
        if (next == Culture) return;
        Culture = next;
        CultureInfo.CurrentCulture = new CultureInfo(next == "ar" ? "ar-KW" : "en-US");
        CultureInfo.CurrentUICulture = CultureInfo.CurrentCulture;
        Changed?.Invoke();
    }

    public string TranslateMessage(string? message)
    {
        if (string.IsNullOrWhiteSpace(message) || IsArabic) return message ?? string.Empty;
        var exact = new Dictionary<string,string>
        {
            ["رابط الموقع غير صحيح."]="The site URL is invalid.", ["تعذر الوصول إلى WordPress REST API."]="Could not reach the WordPress REST API.",
            ["تم الوصول إلى WordPress REST API. لم يتم اختبار تسجيل الدخول لأن بيانات الاعتماد غير مكتملة."]="The WordPress REST API was reached. Login was not tested because credentials are incomplete.",
            ["تم الوصول إلى الموقع لكن اسم المستخدم أو Application Password غير صحيح، أو أن REST API محظور."]="The site was reached, but the username or Application Password is incorrect, or the REST API is blocked.",
            ["وصل التطبيق إلى الموقع ولكن فشل اختبار المستخدم الحالي."]="The application reached the site, but the current-user test failed.", ["تم الاتصال وتسجيل الدخول إلى WordPress بنجاح."]="Connected and signed in to WordPress successfully.",
            ["انتهت مهلة الاتصال بالموقع. تأكد من الرابط والجدار الناري."]="The connection timed out. Check the URL and firewall.", ["تعذر الاتصال بالموقع عبر الشبكة."]="Could not connect to the site over the network.",
            ["استجابة WordPress REST API غير صالحة."]="The WordPress REST API response is invalid.", ["حدث خطأ غير متوقع أثناء اختبار الاتصال."]="An unexpected error occurred while testing the connection.",
            ["الموقع غير موجود."]="Site not found.", ["الموقع مضاف بالفعل."]="The site has already been added.", ["أدخل بيانات WordPress واحفظها أولًا."]="Enter and save the WordPress credentials first.",
            ["تعذر قراءة كلمة المرور المشفّرة. أعد إدخال بيانات الاعتماد."]="Could not read the encrypted password. Re-enter the credentials.", ["احفظ بيانات اتصال WordPress واختبرها أولًا."]="Save and test the WordPress connection details first.",
            ["تعذر قراءة كلمة المرور المشفرة. أعد حفظ بيانات الاتصال."]="Could not read the encrypted password. Save the connection details again.", ["فشل تسجيل الدخول أو لا يملك المستخدم صلاحية قراءة المحتوى."]="Login failed or the user does not have permission to read content.",
            ["فشل تسجيل الدخول أو لا يملك المستخدم صلاحية تعديل المحتوى."]="Login failed or the user does not have permission to edit content.", ["تعذر تحميل المحتوى من WordPress."]="Could not load the content from WordPress.",
            ["تعذر حفظ التعديلات في WordPress."]="Could not save changes to WordPress.", ["عنوان المحتوى مطلوب."]="Content title is required.", ["تم حفظ التعديلات في WordPress بنجاح."]="Changes were saved to WordPress successfully.",
            ["الملف فارغ."]="The file is empty.", ["حجم الملف أكبر من الحد المسموح وهو 25 MB."]="The file exceeds the 25 MB limit.", ["تعذر رفع الملف إلى WordPress."]="Could not upload the file to WordPress.",
            ["تم رفع الملف إلى WordPress بنجاح."]="The file was uploaded to WordPress successfully.", ["تعذر حذف ملف الوسائط من WordPress."]="Could not delete the media file from WordPress.",
            ["تم حذف ملف الوسائط من WordPress بنجاح."]="The media file was deleted from WordPress successfully.", ["فشل تسجيل الدخول أو لا يملك المستخدم صلاحية إدارة الوسائط."]="Login failed or the user does not have permission to manage media.",
            ["تعذر تحميل تعليقات WordPress."]="Could not load WordPress comments.", ["بيانات التعليق غير صحيحة."]="The comment data is invalid.", ["نص الرد مطلوب."]="Reply text is required.", ["تعذر إرسال الرد إلى WordPress."]="Could not send the reply to WordPress.", ["تم إرسال الرد إلى WordPress بنجاح."]="The reply was sent to WordPress successfully.",
            ["رقم التعليق غير صحيح."]="The comment ID is invalid.", ["تعذر حذف التعليق من WordPress."]="Could not delete the comment from WordPress.", ["تم حذف التعليق نهائيًا."]="The comment was permanently deleted.", ["تم نقل التعليق إلى سلة المهملات."]="The comment was moved to Trash.", ["تعذر تحديث حالة التعليق."]="Could not update the comment status.", ["تم تحديث حالة التعليق بنجاح."]="The comment status was updated successfully.",
            ["تعذر تحميل مستخدمي WordPress."]="Could not load WordPress users.", ["اسم المستخدم مطلوب."]="Username is required.", ["البريد الإلكتروني مطلوب."]="Email is required.", ["كلمة المرور يجب ألا تقل عن 6 أحرف."]="The password must be at least 6 characters.",
            ["تعذر إنشاء مستخدم WordPress."]="Could not create the WordPress user.", ["تم إنشاء المستخدم بنجاح."]="The user was created successfully.", ["رقم المستخدم غير صحيح."]="The user ID is invalid.", ["تعذر تحديث المستخدم."]="Could not update the user.", ["تم تحديث المستخدم بنجاح."]="The user was updated successfully.",
            ["لا يمكن تعطيل حساب المستخدم الحالي."]="The current user account cannot be disabled.", ["تعذر تعطيل المستخدم."]="Could not disable the user.", ["تم تعطيل المستخدم بإزالة جميع أدواره."]="The user was disabled by removing all roles.", ["لا يمكن حذف حساب المستخدم الحالي."]="The current user account cannot be deleted.", ["تعذر حذف المستخدم."]="Could not delete the user.", ["تم حذف المستخدم وإعادة إسناد محتواه إلى الحساب الحالي."]="The user was deleted and their content was reassigned to the current account.", ["تعذر تحديد المستخدم الحالي."]="Could not identify the current user."
        };
        if (exact.TryGetValue(message, out var translated)) return translated;
        var sync = Regex.Match(message, @"اكتملت المزامنة: (\d+) مقال، (\d+) صفحة، (\d+) تصنيف، (\d+) وسم، (\d+) ملف وسائط\.");
        if (sync.Success) return $"Synchronization completed: {sync.Groups[1].Value} posts, {sync.Groups[2].Value} pages, {sync.Groups[3].Value} categories, {sync.Groups[4].Value} tags, and {sync.Groups[5].Value} media files.";
        return message;
    }
}
