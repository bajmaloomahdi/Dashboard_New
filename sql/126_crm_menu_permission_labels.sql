/* ==========================================================================
   پچ دستی شماره: 126 | نام: crm_menu_permission_labels
   تاریخ: 2026-10-27 | [MANUAL DML] — داده، خارج از DDL Trigger (db:make-patch آن را نمی‌سازد)
   هدف: فقط برچسب‌ها —
     ۱) منو: «طرف‌حساب‌ها» → «مخاطبین / طرف‌حساب‌ها»، «مخاطبین» (اشخاص) → «افراد مرتبط»
     ۲) عنوان/توضیحِ دسترسی‌هایِ CRM با همین نام‌گذاری
   ردیف‌ها با Url/PermissionCode و متنِ فعلی پیدا می‌شوند؛ Idempotent: اجرایِ دوباره هیچ تغییری نمی‌دهد.
   هیچ ردیفی حذف یا اضافه نمی‌شود. بازگشت: UPDATEِ معکوس با همین متن‌ها.
   ========================================================================== */
SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
GO

-- [MANUAL DML] ۱) منو
UPDATE dbo.Menu SET MenuTitle = N'مخاطبین / طرف‌حساب‌ها', ModifyDate = GETDATE()
WHERE Url = N'/crm/parties' AND MenuTitle = N'طرف‌حساب‌ها';

UPDATE dbo.Menu SET MenuTitle = N'افراد مرتبط', ModifyDate = GETDATE()
WHERE Url = N'/crm/persons-page' AND MenuTitle = N'مخاطبین';
GO

-- [MANUAL DML] ۲) دسترسی‌ها
UPDATE dbo.Permissions SET Description = N'مشاهدهٔ فهرست و جزئیاتِ مخاطبین / طرف‌حساب‌ها'
WHERE PermissionCode = N'CRM_VIEW' AND Description = N'مشاهدهٔ فهرست و جزئیاتِ طرف‌حساب‌ها';

UPDATE dbo.Permissions
SET PermissionName = N'مدیریتِ مخاطبین / طرف‌حساب‌ها',
    Description = N'ایجاد/ویرایش/فعال‌سازیِ طرف‌حساب، برند، آدرس، اطلاعاتِ تماس و افراد مرتبط'
WHERE PermissionCode = N'CRM_MANAGE_PARTIES' AND PermissionName = N'مدیریتِ طرف‌حساب‌ها';

UPDATE dbo.Permissions
SET Description = N'حذفِ طرف‌حساب، شخص، برند، آدرس، اطلاعاتِ تماس، فرد مرتبط، دسته‌بندی، تعامل، گروهِ کالایی و فهرست‌هایِ پایهٔ CRM (فقط وقتی جایی استفاده نشده باشد)'
WHERE PermissionCode = N'CRM_DELETE'
  AND Description = N'حذفِ طرف‌حساب، شخص، برند، آدرس، اطلاعاتِ تماس، مخاطب، دسته‌بندی، تعامل، گروهِ کالایی و فهرست‌هایِ پایهٔ CRM (فقط وقتی جایی استفاده نشده باشد)';
GO
