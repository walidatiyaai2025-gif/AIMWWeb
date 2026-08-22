AIMWWeb Update Package
======================

هذه الباكيدج هي صيغة التسليم القياسية لأي طلب "نسخة" أو "آخر نسخة" من AIMWWeb.
يتم بناؤها على GitHub Actions من commit محدد، وليست Source Code ZIP.

المحتويات:
- app\                  ملفات التطبيق المنشورة الجاهزة للتشغيل.
- Install-Update.ps1    سكربت التحديث الآمن.
- VERSION.txt           رقم النسخة والـcommit المصدر ومعلومات البناء.

طريقة الاستخدام:
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

الحماية:
- Backup كامل للنسخة الحالية قبل الاستبدال.
- Rollback تلقائي عند فشل عملية التحديث قدر الإمكان.
- إيقاف وتشغيل IIS Site/App Pool المطابق عند توفر IIS.
- التحقق من وجود ملفات التطبيق الأساسية بعد النسخ.

مهم:
- لا تشغل السكربت من داخل فولدر الموقع نفسه؛ فك الباكيدج في فولدر مؤقت مستقل.
- لو التطبيق ليس على IIS، أوقف الـprocess يدويًا قبل التحديث أو استخدم -SkipIisControl بعد التأكد أنه متوقف.
- إعداد قاعدة البيانات setup.database.json الموجود خارج فولدر التطبيق لا يتم حذفه بواسطة هذه العملية.
- النسخة الحالية framework-dependent وتحتاج .NET 8 Hosting Bundle على خادم IIS.
