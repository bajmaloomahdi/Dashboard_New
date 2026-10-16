/* ==========================================================================
   پچ دستی شماره: 087 | نام: crm_display_and_sortorder_alignment
   تاریخ: 2026-10-16 | [MANUAL DML] — داده، خارج از DDL Trigger (db:make-patch آن را نمی‌سازد)
   هدف: یکسان‌سازیِ دادهٔ نمایشیِ سرورِ مشتری با سیستمِ مرجع:
     ۱) عنوانِ منویِ بخشِ CRM → «مخاطبین و ارتباطات» و «فهرست‌های CRM» → «فهرست‌های مخاطبین و ارتباطات»
     ۲) SortOrder = 0 برایِ فهرست‌هایِ پایهٔ CRM (قاعدهٔ 081: SortOrder > 0 وگرنه Code)
   Idempotent: اجرایِ دوباره هیچ تغییری نمی‌دهد. هیچ ردیفی حذف یا اضافه نمی‌شود.
   بازگشت: عنوان‌ها با UPDATEِ معکوس؛ SortOrderهایِ قبلی فقط از بکاپِ پیش از انتشار.
   ========================================================================== */
SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
GO

-- [MANUAL DML] ۱) عنوانِ منو — پوشه با فرزندِ /crm/parties پیدا می‌شود (نه با عنوان)، فقط اگر هنوز «CRM» است.
UPDATE m SET m.MenuTitle = N'مخاطبین و ارتباطات'
FROM dbo.Menu m
WHERE m.ParentID IS NULL
  AND m.MenuTitle = N'CRM'
  AND EXISTS (SELECT 1 FROM dbo.Menu c WHERE c.ParentID = m.MenuID AND c.Url = N'/crm/parties');

UPDATE dbo.Menu SET MenuTitle = N'فهرست‌های مخاطبین و ارتباطات'
WHERE Url = N'/crm/directory' AND MenuTitle = N'فهرست‌های CRM';
GO

-- [MANUAL DML] ۲) SortOrder = 0 در فهرست‌هایِ پایهٔ CRM (CrmPartyImages عمداً شامل نیست — ترتیبِ تصاویر است).
IF OBJECT_ID(N'dbo.CrmActivities') IS NOT NULL        UPDATE dbo.CrmActivities        SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmAddressTitles') IS NOT NULL     UPDATE dbo.CrmAddressTitles     SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmCities') IS NOT NULL            UPDATE dbo.CrmCities            SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmContactRoles') IS NOT NULL      UPDATE dbo.CrmContactRoles      SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmContactTypes') IS NOT NULL      UPDATE dbo.CrmContactTypes      SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmCounties') IS NOT NULL          UPDATE dbo.CrmCounties          SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmDepartments') IS NOT NULL       UPDATE dbo.CrmDepartments       SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmInteractionTypes') IS NOT NULL  UPDATE dbo.CrmInteractionTypes  SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmMunicipalZones') IS NOT NULL    UPDATE dbo.CrmMunicipalZones    SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmNeighborhoods') IS NOT NULL     UPDATE dbo.CrmNeighborhoods     SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmOwnershipTypes') IS NOT NULL    UPDATE dbo.CrmOwnershipTypes    SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmPartyTypes') IS NOT NULL        UPDATE dbo.CrmPartyTypes        SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmPositions') IS NOT NULL         UPDATE dbo.CrmPositions         SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmProductCategories') IS NOT NULL UPDATE dbo.CrmProductCategories SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmProvinces') IS NOT NULL         UPDATE dbo.CrmProvinces         SET SortOrder = 0 WHERE SortOrder <> 0;
IF OBJECT_ID(N'dbo.CrmTitles') IS NOT NULL            UPDATE dbo.CrmTitles            SET SortOrder = 0 WHERE SortOrder <> 0;
GO
