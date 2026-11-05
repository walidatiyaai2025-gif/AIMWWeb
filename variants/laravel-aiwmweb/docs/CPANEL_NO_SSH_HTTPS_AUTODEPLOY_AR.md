# AIWMWeb Laravel — GitHub → cPanel نشر تلقائي عبر HTTPS بدون SSH

**الخيار الموصى به على Shared Hosting:** السيرفر يسحب الإصدار الموقّع بدل فتح SSH من الخارج.

## المعمارية

1. آخر GitHub \`main\` يجتاز **Laravel AIWMWeb Acceptance** و**Laravel AIWMWeb cPanel Setup ZIP** من نفس Git SHA.
2. Workflow \`Laravel AIWMWeb cPanel HTTPS Pull Release\` ينتج Setup ZIP كاملًا وUpdate ZIP موقّعة بمفتاح Ed25519 إنتاجي جديد، وينشرهما على GitHub Releases.
3. cPanel Cron (مرة كل 15 دقيقة) ينفّذ **PHP CLI فقط** من المسار الخاص \`app/aiwm-cpanel-https-pull.php\`، والذي يقرأ أحدث GitHub Release عبر HTTPS.
4. Laravel \`SystemUpdateService\` يتحقق من التوقيع، الإصدار المسموح، hashes، ويعمل Backup MySQL مشفرًا ومتحققًا ونسخة ملفات، ثم يحدّث عبر Maintenance → Migrations → Caches → Health → Version Receipt.
5. عند الفشل يوقف التحديث ويسجل Recovery وفق السياسة المعتمدة، ولا يضمن عكس Migrations غير القابلة للرجوع. لا تستخدم Cron لتحميل ZIP عشوائية أو \`git pull\` إلى الموقع.

**توافق النسخة المثبّتة 0.0.0:** أول Update ZIP موقّعة عند دعم `0.0.0` تستثني عمداً ملف Cron الجديد و`public/downloads/aimw-connector.zip` لأن مُحدِّث 0.0.0 لا يسمح بهذين المسارين. لذلك يجب نسخ ملف Cron مرة واحدة عبر File Manager كما سبق. بعد نجاح الترقية إلى `1.0.x` وتحقق النسخ الاحتياطي، اضبط `AIWM_MIN_SUPPORTED_VERSION=1.0.0` أو أدنى إصدار **تم إثبات توافقه فعليًا** من عائلة 1.0؛ التحديثات اللاحقة عندها تضم الملفين وتحدّثهما تلقائيًا. تأكد من عدم إسقاط دعم نسخة أقدم ما زالت مستخدمة. رابط تحميل Connector قد يحتاج إضافته يدويًا أول مرة أو انتظار إصدار تالٍ متوافق.

**لا SSH ولا SFTP ولا FTP مطلوب** لكل عملية نشر. يلزم خروج HTTPS من cPanel إلى GitHub ووجود cPanel Cron وPHP CLI بالامتدادات اللازمة.

## الإعداد الأول — مرة واحدة فقط

الدومين الحالي: \`https://aiwm.walidatiya.com\`

المسارات:
- Laravel: \`/home/walidatiya/public_html/aiwm.walidatiya.com/app\`
- Document Root: \`/home/walidatiya/public_html/aiwm.walidatiya.com/public\`

### 1) تثبيت ملف Cron الخاص داخل Laravel

النسخة المُثبَّتة قديمة؛ لذلك **مرة واحدة** استخدم cPanel → File Manager وارفع ملف:

\`variants/laravel-aiwmweb/backend/aiwm-cpanel-https-pull.php\`

من **Commit/Release موثوق** إلى:

\`/home/walidatiya/public_html/aiwm.walidatiya.com/app/aiwm-cpanel-https-pull.php\`

**ليس** إلى \`public/\`. يفضّل الصلاحية \`600\` أو \`640\`، وملكية حساب cPanel. لا تعِد تثبيت المشروع ولا تستبدل \`.env\` أو \`storage\`. الإصدارات التالية تُحدث هذا الملف أيضًا من Update ZIP الموقّعة.

### 2) مفاتيح توقيع الإنتاج

قبل أي تفعيل:
- **جدّد** مفتاح Ed25519؛ لا تستخدم المفتاح المسرب ضمن #1404.
- المفتاح الخاص (Base64 secret key، 64 bytes بعد فك Base64) في GitHub Actions Secret \`AIWM_UPDATES_SECRET_KEY\` فقط؛ لا يُحفظ ضمن Git أو ZIP أو مُجلد الويب.
- المفتاح العام المقابل (Base64 32 bytes) يُحفظ في GitHub Secret \`AIWM_UPDATES_TRUSTED_PUBLIC_KEY\`، وفي \`.env\` على cPanel. يجب التطابق التام.
- احتفظ بنسخة Backup آمنة من \`.env\` قبل إضافة الإعدادات، ولا تغيّر \`APP_KEY\` أو كلمة مرور قاعدة البيانات.

إعدادات \`app/.env\` المطلوبة:

\`\`\`dotenv
APP_URL=https://aiwm.walidatiya.com
APP_ENV=production
APP_DEBUG=false
AIWM_UPDATES_ENABLED=true
AIWM_AUTO_UPDATES_ENABLED=true
AIWM_UPDATES_PUBLIC_ROOT=/home/walidatiya/public_html/aiwm.walidatiya.com/public
AIWM_UPDATES_PUBLIC_KEY="BASE64_OF_NEW_ED25519_PUBLIC_KEY"
\`\`\`

بعد تغيير \`.env\` إن كان Laravel يستخدم Config Cache يجب مسحه **مرة واحدة**، مثلًا من cPanel Terminal:

\`\`\`bash
cd /home/walidatiya/public_html/aiwm.walidatiya.com/app
php artisan optimize:clear
\`\`\`

ملف \`aiwm-cpanel-https-pull.php\` يرفض التنفيذ من المتصفح، وإعادة التثبيت غير مطلوبة. التنفيذ لا يعمل دون \`installed.lock\` الموجود بعد التثبيت الصحيح.

### 3) GitHub Actions

افتح GitHub Repository → Settings → Secrets and variables → Actions. في بيئة \`aiwm-cpanel-production\`، أو Repository Secrets، اضبط:

| Secrets | القيمة |
|---|---|
| \`AIWM_UPDATES_SECRET_KEY\` | مفتاح Ed25519 الجديد الخاص بصيغة Base64 |
| \`AIWM_UPDATES_TRUSTED_PUBLIC_KEY\` | المفتاح العام المقابل، ومطابق لما ثُبّت في cPanel |
| \`AIWM_UPDATES_SIGNER_ROTATED\` | \`yes\` **فقط** بعد تدوير المفتاح السابق |
| \`AIWM_CPANEL_ACCEPTANCE_VERIFIED\` | \`yes\` **فقط** بعد اختبار Backup/Recovery وترقية ZIP المتوافقة على بيئة cPanel حقيقية |

**Actions Variables**:
- \`AIWM_MIN_SUPPORTED_VERSION\`: مثل \`0.0.0\` فقط إذا كان الإصدار الحالي متوافقًا مع الترقية التي اختُبرت؛ لا تتجاوز بوابة التوافق.
- \`AIWM_HTTPS_CD_ENABLED\`: \`true\` عند اكتمال المتطلبات وتأكيد منفذ HTTPS للخارج.
- اترك \`AIWM_SSH_CD_ENABLED\` غير مفعّل.

لا تضبط Required Reviewers على البيئة إذا أردت نشرًا تلقائيًا كليًا، ولكن احمِ \`main\` من الدمج دون CI معتمدة. تفعيل المتغير وحده لا يكفي: مفاتيح التوقيع، key rotation والـcPanel acceptance مطلوبة.

### 4) cPanel → Cron Jobs

يُفضل كل 15 دقيقة (Minute = \`*/15\`, Hour/Day/Month/Week = \`*\`):

\`\`\`cron
*/15 * * * * /usr/local/bin/php /home/walidatiya/public_html/aiwm.walidatiya.com/app/aiwm-cpanel-https-pull.php >> /home/walidatiya/public_html/aiwm.walidatiya.com/app/storage/logs/aiwm-release-cron.log 2>&1
\`\`\`

**عدّل \`/usr/local/bin/php\` حسب المسار الفعلي من cPanel Terminal باستخدام \`command -v php\`**؛ لا تفترض أن المسار صحيح. تحقق أن PHP CLI متوافق مع Laravel وفيه \`sodium\` و\`zip\` و\`pdo_mysql\`. ملف السجل داخل \`app/storage/logs\` وليس داخل Document Root، لكن يلزم تدوير سجلات Cron دوريًا.

اختبار أولي مرة واحدة من cPanel Terminal (اختياري، لا يلزم في الإصدارات التالية):

\`\`\`bash
php /home/walidatiya/public_html/aiwm.walidatiya.com/app/aiwm-cpanel-https-pull.php
\`\`\`

إذا لم يُنشر بعد Release موقّع، قد يظهر \`BLOCKED\` بسبب عدم وجود \`releases/latest\`؛ هذا لا يعني تثبيت نسخة أو تغيير بيانات. بعد نشر أول Release ستظهر \`UP_TO_DATE\` أو \`DEPLOYED VERSION SHA\`. استجابة Cron لا تعرض أسرارًا مطلقًا؛ راجع history/backup الخاص عند الخطأ.

## شروط قبول النشر

- لا تعتبر Build ناجحًا أو Release منشورًا = موقع تم تحديثه.
- يجب أن يقرأ GitHub آخر SHA ويجتاز CI required، مع تطابق Manifest/Signature وhash.
- يجب أن يكون installed version أحدث فعلًا، والـBackup متحققًا؛ إذا تعذر Backup فلن يبدأ التحديث.
- يجب أن ينتهي Cron بـ \`DEPLOYED\` مع SHA الإصدار، ثم يمر Health readiness. سجّل دليل الإصدار في history.
- ممنوع استبدال ZIP كاملة فوق Laravel الحي أو تغيير \`.env\` أو \`APP_KEY\` أو حذف \`storage\`.
- أي تغيير كبير في Composer/runtime غير متوافق يجب أن يمنع ZIP upgrade ويستخدم مسار نشر واضح مع اختبار مستقل.
- لو GitHub أو HTTPS أو Cron غير متاح، لن يحدث نشر تلقائي؛ لا يوجد اختراق آمن لتلك الصلاحيات.

## بدائل إذا Cron Jobs غير متاحة

- cPanel File Manager لرفع Update ZIP الموقّعة من لوحة الإدارة: دون SSH لكن بتدخل يدوي.
- cPanel UAPI/File Manager API عبر HTTPS (منفذ 2083) إن كانت الاستضافة تُتيح API Token بصلاحيات محدودة؛ يجب إضافة مصادقة، قيود نطاق وحماية من الكتابة المباشرة قبل اعتباره آمنًا، ولا يُعد بديلًا جاهزًا حاليًا.
- FTP/FTPS قد يكون متاحًا لكنه لا يلغي شرط التوقيع والـBackup/Recovery، ولا ننصح باستخدامه لنشر كود Laravel حيًا مباشرة.
