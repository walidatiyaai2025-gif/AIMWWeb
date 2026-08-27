AIMWWeb Update Package
======================

هذه الباكيدج هي صيغة التسليم القياسية لأي طلب "نسخة" أو "آخر نسخة" من AIMWWeb.
يتم بناؤها على GitHub Actions من commit محدد، وليست Source Code ZIP.

المحتويات:
- app\                  ملفات التطبيق المنشورة الجاهزة للتشغيل.
- recovery\             أداة الاستعادة Offline من نفس Source SHA.
- Install-Update.ps1    سكربت التحديث الآمن.
- VERSION.txt           رقم النسخة والـcommit المصدر ومعلومات البناء.

تحديث التطبيق
-------------
1) فك ضغط الباكيدج بالكامل في فولدر مؤقت مستقل.
2) افتح PowerShell كمسؤول Administrator.
3) شغل:

   powershell -ExecutionPolicy Bypass -File .\Install-Update.ps1 -TargetPath "C:\inetpub\wwwroot\AIMWWeb"

لو تريد تحديد اسم موقع IIS صراحة:

   powershell -ExecutionPolicy Bypass -File .\Install-Update.ps1 -TargetPath "C:\inetpub\wwwroot\AIMWWeb" -IisSiteName "AIMWWeb"

ما الذي يحافظ عليه التحديث؟
- Data
- Logs
- Screenshots
- Backups
- Exports
- Temp
- appsettings.Production.json
- appsettings.Local.json

الحماية أثناء التحديث:
- Backup كامل للنسخة الحالية قبل الاستبدال.
- Rollback تلقائي عند فشل عملية التحديث قدر الإمكان.
- إيقاف وتشغيل IIS Site/App Pool المطابق عند توفر IIS.
- التحقق من وجود ملفات التطبيق الأساسية بعد النسخ.

الاستعادة الكاملة Offline — SQLite Manifest v5
----------------------------------------------
الاستعادة الكاملة تستعيد Data + Config + قاعدة SQLite + مفتاح حماية الأسرار من wrapped recovery material.
المفتاح الخام .secret-key لا يتم وضعه داخل ملف النسخة الاحتياطية.

قبل الاستعادة:
1) أوقف IIS Site / App Pool وكل Workers الخاصة بـ AIMWWeb. الأداة سترفض العمل إذا كان أي Web Worker ما زال ممسكاً Runtime Lease.
2) استخدم Backup Manifest v5 تم إنشاؤه مع Recovery Secret صحيح.
3) لا تضع Recovery Secret داخل command line أو ملف batch. الأداة تطلبه بشكل مخفي من الـConsole.

مثال:

   .\recovery\AIWordPressManager.RecoveryTool.exe restore --backup "C:\Backups\AIWM-Backup-YYYYMMDD-HHMMSS-....zip"

إذا كان هناك مفتاح Secret Protection مختلف موجود حالياً وتأكدت أن النسخة الاحتياطية هي المرجع الصحيح:

   .\recovery\AIWordPressManager.RecoveryTool.exe restore --backup "C:\Backups\AIWM-Backup-YYYYMMDD-HHMMSS-....zip" --replace-existing-key

مهم جداً بخصوص LocalApplicationData:
AIMWWeb يحفظ Data / Config / Security افتراضياً تحت LocalApplicationData لهوية الـWindows التي تشغل التطبيق.
إذا شغلت RecoveryTool كـAdministrator بهوية مختلفة عن IIS App Pool، مرر LocalApplicationData الصحيح الخاص بهوية تشغيل التطبيق:

   .\recovery\AIWordPressManager.RecoveryTool.exe restore --backup "C:\Backups\AIWM-Backup-....zip" --local-app-data "C:\Users\SERVICE_ACCOUNT\AppData\Local" --replace-existing-key

يمكن استخدام --application-root فقط عند وجود Application Root مخصص، ومعه --local-app-data لتحديد مكان Security Key بشكل صريح.

ماذا تتحقق منه الاستعادة؟
- Manifest v5 واحد فقط وبحدود حجم آمنة.
- كل Managed file موجود ومعلن ولا يوجد payload غير معلن.
- Size + SHA-256 قبل أي استبدال.
- منع Path Traversal وأي مسار خارج Data / Config.
- Recovery Secret يفك wrapped key بنجاح قبل mutation.
- SQLite PRAGMA quick_check قبل وبعد الاستبدال.
- تعديل ConnectionString داخل setup.database.json ليشير لقاعدة البيانات المستعادة فعلياً.
- Atomic-style Data / Config swap مع Rollback للحالة السابقة عند فشل متأخر.
- استعادة/التحقق من Secret Protection Key تحت Exclusive Recovery Lease.
- كتابة AIMW-LAST-OFFLINE-RESTORE.txt بعد النجاح فقط.

الأمان والفشل المغلق:
- Full restore الحالي يدعم SQLite فقط.
- SQL Server / PostgreSQL / MySQL / MariaDB تحتاج Provider-native backup/restore؛ النظام يرفض إنشاء نسخة غير مكتملة بدلاً من الادعاء أنها Recoverable.
- Recovery Secret لا يُقبل كـ --recovery-secret ولا يُحفظ في backup أو settings أو provenance.
- لا تشغل RecoveryTool بينما التطبيق يعمل.

مهم للتحديث العادي:
- لا تشغل Install-Update.ps1 من داخل فولدر الموقع نفسه؛ فك الباكيدج في فولدر مؤقت مستقل.
- لو التطبيق ليس على IIS، أوقف الـprocess يدوياً قبل التحديث أو استخدم -SkipIisControl بعد التأكد أنه متوقف.
- إعداد قاعدة البيانات setup.database.json الموجود خارج فولدر نشر IIS لا يتم حذفه بواسطة عملية Update العادية.
- النسخة الحالية framework-dependent وتحتاج .NET 8 Hosting Bundle على خادم IIS.
