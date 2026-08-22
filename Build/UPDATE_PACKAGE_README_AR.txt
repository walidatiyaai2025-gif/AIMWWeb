AIMWWeb Update Package
======================

هذه الباكيدج مبنية لتحديث نسخة AIMWWeb المنشورة على Windows/IIS بدون حذف بيانات التشغيل المحمولة.

المحتويات:
- app\                  ملفات التطبيق المنشورة.
- Install-Update.ps1    سكربت التحديث الآمن.
- VERSION.txt           رقم النسخة والـcommit المصدر.

طريقة الاستخدام:
1) فك ضغط الباكيدج بالكامل في فولدر مؤقت.
2) افتح PowerShell كمسؤول Administrator.
3) شغل:

   powershell -ExecutionPolicy Bypass -File .\Install-Update.ps1 -TargetPath "C:\inetpub\wwwroot\AIMWWeb"

لو اسم موقع IIS مختلف عن المسار أو تريد تحديده صراحة:

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

السكربت يعمل Backup كامل للنسخة الحالية قبل الاستبدال ويحاول Rollback تلقائيًا إذا فشل التحديث.

مهم:
- لا تشغل السكربت من داخل فولدر الموقع نفسه؛ فك الباكيدج في فولدر مؤقت مستقل.
- لو التطبيق ليس على IIS، أوقف الـprocess يدويًا قبل التحديث أو استخدم -SkipIisControl بعد التأكد أنه متوقف.
- إعداد قاعدة البيانات setup.database.json خارج فولدر التطبيق لا يتم حذفه بواسطة هذه العملية.
- تحتاج .NET 8 Hosting Bundle على خادم IIS لتشغيل التطبيق المنشور framework-dependent.
