/* ==========================================================================
   پچ خودکار شماره: 030 | نام: letter_templates
   تاریخ: 2026-09-17 07:24:16 | شامل 6 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: LetterTemplates
CREATE TABLE dbo.LetterTemplates
(
    LetterTemplateID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_LetterTemplates PRIMARY KEY,
    Code              NVARCHAR(64)  NOT NULL,
    Name              NVARCHAR(200) NOT NULL,
    EntityType        NVARCHAR(64)  NOT NULL,
    DefinitionID      INT NULL,
    SubjectTemplate   NVARCHAR(500) NOT NULL,
    BodyTemplate      NVARCHAR(MAX) NOT NULL,
    IsActive          BIT NOT NULL CONSTRAINT DF_LetterTemplates_IsActive DEFAULT (1),
    RowGuid           UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_LetterTemplates_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst  DATETIME2 NOT NULL CONSTRAINT DF_LetterTemplates_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate   DATETIME2 NULL,
    UserID_LastUpdate INT NULL,
    CONSTRAINT UQ_LetterTemplates_Code UNIQUE (Code),
    CONSTRAINT FK_LetterTemplates_Definition FOREIGN KEY (DefinitionID) REFERENCES dbo.WorkflowDefinitions (DefinitionID)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetLetterTemplates
CREATE PROCEDURE dbo.sp_Wf_GetLetterTemplates
    @SearchText   NVARCHAR(200) = NULL,
    @EntityType   NVARCHAR(64)  = NULL,
    @DefinitionID INT           = NULL,
    @IsActive     BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT t.LetterTemplateID, t.Code, t.Name, t.EntityType, t.DefinitionID,
           d.Name AS DefinitionName, d.Code AS DefinitionCode,
           t.SubjectTemplate, t.BodyTemplate, t.IsActive,
           t.Date_InsertFirst, t.UserID_InsertFirst, t.Date_LastUpdate, t.UserID_LastUpdate
    FROM dbo.LetterTemplates t
    LEFT JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = t.DefinitionID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.Name LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@EntityType IS NULL OR @EntityType = N'' OR t.EntityType = @EntityType)
      AND (@DefinitionID IS NULL OR t.DefinitionID = @DefinitionID)
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY t.LetterTemplateID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetLetterTemplateByID
CREATE PROCEDURE dbo.sp_Wf_GetLetterTemplateByID
    @LetterTemplateID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 LetterTemplateID, Code, Name, EntityType, DefinitionID, SubjectTemplate, BodyTemplate, IsActive
    FROM dbo.LetterTemplates
    WHERE LetterTemplateID = @LetterTemplateID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetLetterTemplatesForDefinition
-- Templateهایِ قابلِ‌استفاده برایِ یک Definitionِ مشخص: اختصاصیِ همان Definition + عمومیِ همان EntityType
CREATE PROCEDURE dbo.sp_Wf_GetLetterTemplatesForDefinition
    @DefinitionID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @EntityType NVARCHAR(64);
    SELECT @EntityType = EntityType FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID;

    IF @EntityType IS NULL
    BEGIN
        SELECT TOP 0 LetterTemplateID, Code, Name, EntityType, DefinitionID, SubjectTemplate, BodyTemplate, IsActive FROM dbo.LetterTemplates;
        RETURN;
    END

    SELECT LetterTemplateID, Code, Name, EntityType, DefinitionID, SubjectTemplate, BodyTemplate, IsActive
    FROM dbo.LetterTemplates
    WHERE IsActive = 1
      AND (DefinitionID = @DefinitionID OR (DefinitionID IS NULL AND EntityType = @EntityType))
    ORDER BY CASE WHEN DefinitionID = @DefinitionID THEN 0 ELSE 1 END, LetterTemplateID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_SaveLetterTemplate
CREATE PROCEDURE dbo.sp_Wf_SaveLetterTemplate
    @LetterTemplateID INT           = NULL,   -- NULL = ایجاد
    @Code             NVARCHAR(64),
    @Name             NVARCHAR(200),
    @EntityType       NVARCHAR(64),
    @DefinitionID     INT           = NULL,
    @SubjectTemplate  NVARCHAR(500),
    @BodyTemplate     NVARCHAR(MAX),
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@EntityType)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ موجودیت الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.LetterTemplates
               WHERE Code = @Code AND (@LetterTemplateID IS NULL OR LetterTemplateID <> @LetterTemplateID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @DefinitionID IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
        BEGIN SELECT 0 AS Success, N'فرایندِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

        IF EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID AND EntityType <> @EntityType)
        BEGIN SELECT 0 AS Success, N'نوعِ موجودیتِ قالب با نوعِ موجودیتِ فرایندِ انتخاب‌شده یکسان نیست.' AS Message; RETURN; END
    END

    IF @LetterTemplateID IS NULL
    BEGIN
        INSERT INTO dbo.LetterTemplates
            (Code, Name, EntityType, DefinitionID, SubjectTemplate, BodyTemplate, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @Name, @EntityType, @DefinitionID, @SubjectTemplate, @BodyTemplate, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'قالب ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS LetterTemplateID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.LetterTemplates WHERE LetterTemplateID = @LetterTemplateID)
        BEGIN SELECT 0 AS Success, N'قالب یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.LetterTemplates
        SET Code = @Code, Name = @Name, EntityType = @EntityType, DefinitionID = @DefinitionID,
            SubjectTemplate = @SubjectTemplate, BodyTemplate = @BodyTemplate,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE LetterTemplateID = @LetterTemplateID;

        SELECT 1 AS Success, N'قالب ویرایش شد.' AS Message, @LetterTemplateID AS LetterTemplateID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleLetterTemplateActive
CREATE PROCEDURE dbo.sp_Wf_ToggleLetterTemplateActive
    @LetterTemplateID INT,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @Current BIT;

    BEGIN TRY
        SELECT @Current = IsActive FROM dbo.LetterTemplates WHERE LetterTemplateID = @LetterTemplateID;

        IF @Current IS NULL
        BEGIN
            SELECT 0 AS Success, N'قالب یافت نشد.' AS Message;
            RETURN;
        END

        UPDATE dbo.LetterTemplates
        SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
            Date_LastUpdate = SYSDATETIME(),
            UserID_LastUpdate = @UserID
        WHERE LetterTemplateID = @LetterTemplateID;

        SET @Success = 1;
        SET @Message = CASE WHEN @Current = 1 THEN N'قالب غیرفعال شد.' ELSE N'قالب فعال شد.' END;
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در تغییرِ وضعیت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

/* ==================== DATA SEED — Phase C ====================
   DML توسطِ DDL-Triggerِ DevChangeLog ردیابی نمی‌شود؛ این بخش دستی اضافه شده تا
   پچ کاملاً خودکفا باشد (هم‌الگو با patch 025/026/028/029). */

-- Permissionِ جدید — هم‌الگو با patch 029
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_MANAGE_TEMPLATES')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_MANAGE_TEMPLATES', N'مدیریتِ Registryِ قالب‌هایِ نامه', N'Workflow', N'ایجاد/ویرایش/فعال‌سازیِ قالب‌هایِ نامه (Letter Templates)', 14, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1) — هم‌الگو با patch 029
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'WORKFLOW_MANAGE_TEMPLATES'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO
