/* ==========================================================================
   پچ دستی شماره: 079 | نام: fix_workflow_definitions_menu_url
   تاریخ: 2026-10-07 | شامل 1 دستور SQL (فقط اصلاحِ داده — بدونِ تغییرِ Schema)
   ========================================================================== */

/* [MANUAL DATA FIX] — منوی «تعریف فرایندها» (WF_DEFINITIONS) در پچ 014 با Url = '/workflow/definitions'
   ساخته شده بود که یک endpointِ JSON است، نه صفحه؛ Sidebar با router.visit(Url) به آن می‌رفت و خطایِ
   «All Inertia requests must receive a valid Inertia response, however a plain JSON response was received»
   رخ می‌داد. صفحهٔ Inertiaِ درست '/process/definitions' است.
   Guarded و قابلِ اجرایِ مجدد: فقط همین یک رکورد (MenuCode یکتاست) و فقط اگر Url هنوز همان مقدارِ
   اشتباهِ قدیمی باشد؛ اگر قبلاً اصلاح یا دستی تغییر کرده، هیچ تغییری نمی‌دهد. فقط ستونِ Url. */
IF EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WF_DEFINITIONS' AND Url = N'/workflow/definitions')
    UPDATE dbo.Menu
    SET Url = N'/process/definitions'
    WHERE MenuCode = N'WF_DEFINITIONS' AND Url = N'/workflow/definitions';
GO
