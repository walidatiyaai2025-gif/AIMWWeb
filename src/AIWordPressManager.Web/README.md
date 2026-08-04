# AIWordPressManager 145 — Blazor Server Bilingual

نسخة Web فقط مبنية على .NET 8 Blazor Server وEF Core وSQLite.

## الجديد
- واجهة عربية وإنجليزية كاملة.
- تبديل فوري من الشريط العلوي أو صفحة الإعدادات.
- RTL للعربية وLTR للإنجليزية.
- حفظ اللغة داخل Local Storage في المتصفح.
- ترجمة الصفحات، الأزرار، الجداول، الحالات، رسائل الاتصال والمزامنة الشائعة.
- تنسيق التاريخ حسب اللغة.

## التشغيل
```powershell
powershell -ExecutionPolicy Bypass -File .\Build\Repair-And-Build.ps1
```
ثم افتح `AIWordPressManager.Web.sln` وشغّل مشروع `AIWordPressManager.Web`.
