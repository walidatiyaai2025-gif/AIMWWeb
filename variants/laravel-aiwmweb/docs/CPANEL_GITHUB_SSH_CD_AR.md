# Laravel AIWMWeb — نشر تلقائي آمن من GitHub إلى cPanel عبر SSH

نطاق العمل: `LARAVEL_AIWMWEB` فقط، وليس إصدار ASP.NET.

الموقع الحالي: `https://aiwm.walidatiya.com`

- `SITE_ROOT=/home/walidatiya/public_html/aiwm.walidatiya.com`
- `APP_ROOT=$SITE_ROOT/app`
- `PUBLIC_ROOT=$SITE_ROOT/public`

## النتيجة بعد الإعداد مرة واحدة

كل تغيير يصل إلى `main` يقوم ببناء Laravel/Vite وSetup ZIP كامل في GitHub Actions، ثم ينتظر نجاح **الاختبارين المطلوبين على آخر SHA من main**: Laravel AIWMWeb Acceptance وLaravel AIWMWeb cPanel Setup ZIP. بعد النجاح، يُنتج Update ZIP إنتاجية بتوقيع Ed25519 موثوق من نفس SHA، وينقلها عبر SSH بمفتاح مستقل وHost Key مثبت مسبقًا إلى مجلد خاص داخل `app/storage`.

يستدعي GitHub Actions برنامج Laravel للترقية الآمنة: تحقق توقيع الحزمة ومحتوياتها، منع تحديث غير متوافق، نسخة DB مشفرة مثبتة، نسخة ملفات، Maintenance، ترحيلات، فحص صحة، إيصال إصدار. لا ينفّذ `git pull` أو `composer install` أو `npm` على سيرفر الإنتاج. لا يعيد التثبيت ولا يكتب فوق `.env` أو `storage`. يرفض تحديثًا غير موثوق أو غير أحدث. بعد نجاح الصحة ينشر GitHub Release تشمل Setup ZIP + Signed Update ZIP وملفات الـManifest والتوقيع والهاش.

**مهم:** أول ربط يحتاج صلاحية SSH ومفتاح نشر ومفتاح توقيع جديد، مرة واحدة. لا يصح إعفاء هذه الخطوة دون صلاحية فعلية على الخادم. لا تسجل الأسرار في Issue أو Commit أو محادثة.

## 1 — إنشاء SSH key مخصص للنشر مرة واحدة

**على الكمبيوتر الموثوق** (macOS Terminal أو Windows PowerShell مع OpenSSH):

```sh
ssh-keygen -t ed25519 -f ~/.ssh/aiwm_github_actions -C "aiwm-actions-deploy"
cat ~/.ssh/aiwm_github_actions.pub
```

على Windows PowerShell استخدم:
```powershell
ssh-keygen -t ed25519 -f "$HOME\.ssh\aiwm_github_actions" -C "aiwm-actions-deploy"
Get-Content "$HOME\.ssh\aiwm_github_actions.pub"
```

أضف **السطر العام فقط** إلى `~/.ssh/authorized_keys` لحساب cPanel `walidatiya` باستخدام cPanel Terminal:
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
# انسخ السطر الذي يبدأ ssh-ed25519 ... من جهازك داخل authorized_keys
nano ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```
اختبر من جهازك `ssh -i ~/.ssh/aiwm_github_actions -p PORT walidatiya@SSH_HOST`. لا تستخدم حساب root؛ يجب أن يكون مفتاح النشر محدودًا بحساب الاستضافة والملفات المملوكة له.

تحقق من **بصمة SSH الحقيقية** من صفحة الاستضافة/معلومات السيرفر أو قناة موثوقة قبل تسجيلها. خذ السطر `ssh-ed25519` من `ssh-keyscan -p PORT SSH_HOST` بعد التحقق المستقل من البصمة، ثم ضعه في `AIWM_DEPLOY_KNOWN_HOSTS`. لا تعطل `StrictHostKeyChecking`.

## 2 — مفاتيح Ed25519 الخاصة بتحديثات Laravel

مفتاح SSH شيء منفصل عن توقيع الحزم. أنشئ مفتاحًا **جديدًا** لتوقيع ZIP؛ لا تستخدم مفتاح #1404 السابق الذي سبق كشفه. على جهاز آمن عليه PHP مع sodium:

```bash
php -r '$k=sodium_crypto_sign_keypair(); file_put_contents("aiwm-signing-secret.txt",base64_encode(sodium_crypto_sign_secretkey($k))); file_put_contents("aiwm-signing-public.txt",base64_encode(sodium_crypto_sign_publickey($k)));'
```

احتفظ بقيمة `aiwm-signing-secret.txt` **داخل GitHub Actions Secret فقط**، ولا ترفع الملف للمستودع ولا إلى مجلد الموقع. القيمة الموجودة في `aiwm-signing-public.txt` هي مفتاح التحقق الذي يحفظ على cPanel في `app/.env`.

خذ نسخة احتياطية من `app/.env` قبل تغيير هذه السطور يدويًا من cPanel File Manager:

```dotenv
APP_DEBUG=false
AIWM_UPDATES_ENABLED=true
AIWM_UPDATES_PUBLIC_KEY="BASE64_PUBLIC_KEY_FROM_NEW_PAIR"
AIWM_UPDATES_PUBLIC_ROOT="/home/walidatiya/public_html/aiwm.walidatiya.com/public"
```

لا تغيّر `APP_KEY` أو إعدادات DB/Storage ولا تعيد تشغيل Installer. إن كانت إعدادات Laravel مخزّنة في config cache، امسح cache **مرة واحدة عند ربط البيئة** من cPanel Terminal:
```bash
cd /home/walidatiya/public_html/aiwm.walidatiya.com/app
php artisan optimize:clear
```
هذا تجهيز مبدئي مطلوب مرة واحدة، وليس أمرًا يدويًا مطلوبًا لكل إصدار. تأكد أن CLI PHP بالإصدار المناسب ومفعّل فيه `sodium` و`pdo_mysql` و`zip`.

## 3 — إعداد GitHub Actions Environment

من GitHub Repository → Settings → Environments أنشئ `aiwm-cpanel-production`. للمراقبة وحدها يمكن إضافة متطلبات موافقة؛ لكن **للنشر غير التفاعلي تمامًا** لا تضبط Required Reviewers، مع حماية `main` من الـpush المباشر دون CI ومراجعة.

ثم Settings → Secrets and variables → Actions أضف الأسرار التالية (Repository secrets أو Environment secrets):

| Secret | الوصف |
|---|---|
| `AIWM_DEPLOY_HOST` | اسم سيرفر SSH الحقيقي، وليس افتراضًا أن اسم الدومين هو Host SSH |
| `AIWM_DEPLOY_SSH_PRIVATE_KEY` | محتوى المفتاح الخاص `aiwm_github_actions` بكامل الأسطر |
| `AIWM_DEPLOY_KNOWN_HOSTS` | SSH Host Key الصحيح بعد التحقق من بصمته |
| `AIWM_UPDATES_SECRET_KEY` | المفتاح الخاص Ed25519 لتوقيع تحديثات Laravel بصيغة Base64 |
| `AIWM_UPDATES_TRUSTED_PUBLIC_KEY` | المفتاح العام المقابل تمامًا للقيمة المثبتة في `.env` |
| `AIWM_UPDATES_SIGNER_ROTATED` | القيمة `yes` فقط بعد تدوير المفتاح المعرض سابقًا |
| `AIWM_CPANEL_ACCEPTANCE_VERIFIED` | القيمة `yes` فقط بعد اختبار ترقية/Backup/Recovery الحقيقي على cPanel |

وأضف **Actions variables**:

| Variable | القيمة الابتدائية المتوقعة |
|---|---|
| `AIWM_DEPLOY_USER` | `walidatiya` |
| `AIWM_DEPLOY_PORT` | منفذ SSH الفعلي (مثلاً `22`) |
| `AIWM_DEPLOY_SITE_ROOT` | `/home/walidatiya/public_html/aiwm.walidatiya.com` |
| `AIWM_DEPLOY_PHP_BIN` | `php` أو المسار المطلق لـPHP 8.4 المستخدم في cPanel |
| `AIWM_MIN_SUPPORTED_VERSION` | `0.0.0` فقط إذا تحققت الترقية من الإصدار المثبت؛ حد أدنى أعلى عند تغيير الاعتمادات أو العقد |

## 4 — أول تشغيل ومراقبة لاحقة

بعد تجهيز الأسرار والبيئة، يمكن تشغيل `Laravel AIWMWeb cPanel SSH CD` مرة يدويًا من Actions للتأكد من صحة الاتصال (وسينفذ نشرًا حقيقيًا إذا اجتازت الحزمة فحصها). بعدها كل دمج إلى `main` يؤدي إلى الفحص والنشر تلقائيًا؛ لا يلزم مستخدم يضغط Deploy.

مع كل تشغيل: تحقق من SHA في `main`، CI المطلوب، فحص التوقيع، نتيجة SSH، الإيصال المثبت والإصدار، والرابط `/health/live`. إن كان السيرفر بلا وصول SSH من GitHub-hosted runners، سيفشل الربط بأمان؛ لا يمكن تجاوز ذلك باستخدام إعداد Laravel أو تعديل `.htaccess`. تحتاج فتح اتصال SSH الوارد أو Runner موثوق لديه وصول إلى الخادم.

**لا تعتمد مجرد وجود Workflow كدليل نجاح نشر.** الحالة `DEPLOYED` يجب أن تأتي من SSH وتطابق إيصال Laravel ونجاح Health Check.

## طوارئ/استرجاع

إذا فشل تحديث أثناء migration قد تكون تغييرات DB غير قابلة للعكس؛ نظام التحديث يترك الموقع في Maintenance ويدوّن Recovery عند عدم إمكان استرجاعه بأمان. لا تنسخ قاعدة بيانات قديمة فوق الحية تلقائيًا ولا تنفذ `migrate:rollback` آليًا. راجع `app/storage/app/system-updates` وإيصال DB المشفّر قبل أي تصرف.

## حدود التفعيل

حتى يتم إعداد هذه القيم، مسار النشر **غير متصل فعليًا** بالسيرفر؛ لا يمكن التظاهر بأن GitHub لديه SSH credentials. بعد نجاح أول تشغيل حقيقي فقط يُعد الربط مُفعّلًا.
