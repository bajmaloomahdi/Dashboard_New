/* ==========================================================================
   پچ دستی شماره: 045 | نام: crm_persons_page_menu
   تاریخ: 2026-09-24
   ========================================================================== */

/* ثبتِ منویِ صفحهٔ مستقلِ «مخاطبین» (مدیریتِ CrmPersons) زیرِ پوشهٔ CRM — DML
   است (نه تغییرِ Schema)، پس دستی به پچ اضافه شد؛ Idempotent با چکِ Url. */
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/persons-page')
BEGIN
    DECLARE @CrmFolderID3 INT;
    SELECT @CrmFolderID3 = MenuID FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL;
    IF @CrmFolderID3 IS NOT NULL
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderID3, @MenuTitle = N'مخاطبین', @MenuKind = N'PAGE', @Url = N'/crm/persons-page', @SortOrder = 4;

    EXEC dbo.sp_GrantAllMenusToRole @RoleID = 1;
END
GO
