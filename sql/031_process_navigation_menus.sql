/* ==========================================================================
   پچ دستی شماره: 031 | نام: process_navigation_menus
   این پچ فقط DML است (dbo.Menu/dbo.RoleMenus) — DDL-Trigger چیزی برایِ آن
   ثبت نمی‌کند، بنابراین دستی نوشته شده، هم‌الگو با بخشِ DATA SEED پچ‌هایِ
   قبلی (مثلِ 030). هدف: دسترسیِ صفحاتِ Process از منویِ واقعیِ سیستم، بدونِ
   Permissionِ جدید (همان WORKFLOW_VIEW موجود در Process*Controller کافی است).
   ========================================================================== */

-- زیرمنوهایِ جدید برایِ صفحاتِ Process (زیرِ پوشهٔ WORKFLOW موجود، MenuCode='WORKFLOW')
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WF_ENTITY_TYPES')
    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateUser)
    SELECT MenuID, N'WF_ENTITY_TYPES', N'انواعِ موجودیت‌هایِ فرایند', N'PAGE', N'/process/entity-types', N'bi bi-diagram-3', 2, 3, 0, 1, 1, 0, 2
    FROM dbo.Menu WHERE MenuCode = N'WORKFLOW';

IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WF_TEMPLATE_PARAMETERS')
    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateUser)
    SELECT MenuID, N'WF_TEMPLATE_PARAMETERS', N'پارامترهایِ قالب', N'PAGE', N'/process/template-parameters', N'bi bi-diagram-3', 2, 4, 0, 1, 1, 0, 2
    FROM dbo.Menu WHERE MenuCode = N'WORKFLOW';

IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WF_LETTER_TEMPLATES')
    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateUser)
    SELECT MenuID, N'WF_LETTER_TEMPLATES', N'قالب‌هایِ نامه', N'PAGE', N'/process/templates', N'bi bi-diagram-3', 2, 5, 0, 1, 1, 0, 2
    FROM dbo.Menu WHERE MenuCode = N'WORKFLOW';
GO

-- اعطایِ دسترسیِ نمایش — دقیقاً هم‌الگو با خواهر-و-برادرهایِ هر منو (RoleID=1 برایِ صفحاتِ Process)
INSERT INTO dbo.RoleMenus (RoleID, MenuID, CanView, IsActive)
SELECT 1, m.MenuID, 1, 1
FROM dbo.Menu m
WHERE m.MenuCode IN (N'WF_ENTITY_TYPES', N'WF_TEMPLATE_PARAMETERS', N'WF_LETTER_TEMPLATES')
  AND NOT EXISTS (SELECT 1 FROM dbo.RoleMenus rm WHERE rm.RoleID = 1 AND rm.MenuID = m.MenuID);
GO
