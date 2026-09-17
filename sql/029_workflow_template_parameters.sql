/* ==========================================================================
   پچ خودکار شماره: 029 | نام: workflow_template_parameters
   تاریخ: 2026-09-17 06:44:29 | شامل 5 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: TemplateParameters
CREATE TABLE dbo.TemplateParameters
(
    TemplateParameterID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TemplateParameters PRIMARY KEY,
    Code                NVARCHAR(64)  NOT NULL,
    Caption             NVARCHAR(200) NOT NULL,
    GroupCode           NVARCHAR(20)  NOT NULL,
    EntityType          NVARCHAR(64)  NULL,
    DataType            NVARCHAR(20)  NOT NULL,
    SourceType          NVARCHAR(20)  NOT NULL,
    SourceKey           NVARCHAR(100) NOT NULL,
    AllowedValuesJson   NVARCHAR(MAX) NULL,
    IsActive            BIT NOT NULL CONSTRAINT DF_TemplateParameters_IsActive DEFAULT (1),
    SortOrder           INT NOT NULL CONSTRAINT DF_TemplateParameters_SortOrder DEFAULT (0),
    RowGuid             UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_TemplateParameters_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst    DATETIME2 NOT NULL CONSTRAINT DF_TemplateParameters_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_TemplateParameters_Code UNIQUE (Code),
    CONSTRAINT CK_TemplateParameters_GroupCode CHECK (GroupCode IN (N'USER', N'SYSTEM', N'FORM')),
    CONSTRAINT CK_TemplateParameters_SourceType CHECK (SourceType IN (N'USER', N'SYSTEM', N'FORM')),
    CONSTRAINT CK_TemplateParameters_DataType CHECK (DataType IN (N'STRING', N'DATE', N'INTEGER', N'DECIMAL')),
    CONSTRAINT CK_TemplateParameters_AllowedValuesJson CHECK (AllowedValuesJson IS NULL OR ISJSON(AllowedValuesJson) = 1)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetTemplateParameters
CREATE PROCEDURE dbo.sp_Wf_GetTemplateParameters
    @SearchText NVARCHAR(200) = NULL,
    @GroupCode  NVARCHAR(20)  = NULL,
    @EntityType NVARCHAR(64)  = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TemplateParameterID, Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey,
           AllowedValuesJson, IsActive, SortOrder,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.TemplateParameters
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR Caption LIKE N'%' + @SearchText + N'%'
           OR Code LIKE N'%' + @SearchText + N'%')
      AND (@GroupCode IS NULL OR @GroupCode = N'' OR GroupCode = @GroupCode)
      AND (@EntityType IS NULL OR @EntityType = N'' OR EntityType = @EntityType)
      AND (@IsActive IS NULL OR IsActive = @IsActive)
    ORDER BY SortOrder, TemplateParameterID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetTemplateParameterByCode
CREATE PROCEDURE dbo.sp_Wf_GetTemplateParameterByCode
    @Code NVARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 TemplateParameterID, Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey,
           AllowedValuesJson, IsActive, SortOrder
    FROM dbo.TemplateParameters
    WHERE Code = @Code;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_SaveTemplateParameter
CREATE PROCEDURE dbo.sp_Wf_SaveTemplateParameter
    @TemplateParameterID INT           = NULL,   -- NULL = ایجاد
    @Code                NVARCHAR(64),
    @Caption             NVARCHAR(200),
    @GroupCode           NVARCHAR(20),
    @EntityType          NVARCHAR(64)  = NULL,
    @DataType            NVARCHAR(20),
    @SourceType          NVARCHAR(20),
    @SourceKey           NVARCHAR(100),
    @AllowedValuesJson   NVARCHAR(MAX) = NULL,
    @SortOrder           INT           = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Caption)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'عنوانِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.TemplateParameters
               WHERE Code = @Code AND (@TemplateParameterID IS NULL OR TemplateParameterID <> @TemplateParameterID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @TemplateParameterID IS NULL
    BEGIN
        INSERT INTO dbo.TemplateParameters
            (Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey, AllowedValuesJson, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @Caption, @GroupCode, @EntityType, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'پارامتر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS TemplateParameterID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE TemplateParameterID = @TemplateParameterID)
        BEGIN SELECT 0 AS Success, N'پارامتر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.TemplateParameters
        SET Code = @Code, Caption = @Caption, GroupCode = @GroupCode, EntityType = @EntityType,
            DataType = @DataType, SourceType = @SourceType, SourceKey = @SourceKey,
            AllowedValuesJson = @AllowedValuesJson, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE TemplateParameterID = @TemplateParameterID;

        SELECT 1 AS Success, N'پارامتر ویرایش شد.' AS Message, @TemplateParameterID AS TemplateParameterID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleTemplateParameterActive
CREATE PROCEDURE dbo.sp_Wf_ToggleTemplateParameterActive
    @TemplateParameterID INT,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @Current BIT;

    BEGIN TRY
        SELECT @Current = IsActive FROM dbo.TemplateParameters WHERE TemplateParameterID = @TemplateParameterID;

        IF @Current IS NULL
        BEGIN
            SELECT 0 AS Success, N'پارامتر یافت نشد.' AS Message;
            RETURN;
        END

        UPDATE dbo.TemplateParameters
        SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
            Date_LastUpdate = SYSDATETIME(),
            UserID_LastUpdate = @UserID
        WHERE TemplateParameterID = @TemplateParameterID;

        SET @Success = 1;
        SET @Message = CASE WHEN @Current = 1 THEN N'پارامتر غیرفعال شد.' ELSE N'پارامتر فعال شد.' END;
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در تغییرِ وضعیت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

/* [DATA SEED] — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود (DML است،
   نه تغییرِ Schema)، پس دستی به پچ اضافه شد تا اجرایِ این پچ روی هر محیطِ دیگری
   کاملاً خودکفا باشد (هم‌الگو با patch 025/026/028). */

-- Seedِ Phase B — ۶ پارامترِ سراسری (EntityType=NULL) — همگی از طریقِ خودِ SP (نه INSERTِ خام)
-- تا منطقِ Uniqueness/Trim همان مسیرِ واقعیِ Save طی شود.
IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'USER_FULL_NAME')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'USER_FULL_NAME', @Caption=N'نام و نام‌خانوادگی کاربر', @GroupCode=N'USER', @EntityType=NULL, @DataType=N'STRING', @SourceType=N'USER', @SourceKey=N'FullName', @AllowedValuesJson=NULL, @SortOrder=1, @UserID=NULL;

IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'USER_PERSONNEL_CODE')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'USER_PERSONNEL_CODE', @Caption=N'کد پرسنلی', @GroupCode=N'USER', @EntityType=NULL, @DataType=N'STRING', @SourceType=N'USER', @SourceKey=N'UserCode', @AllowedValuesJson=NULL, @SortOrder=2, @UserID=NULL;

IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'USER_POSITION_TITLE')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'USER_POSITION_TITLE', @Caption=N'سمت', @GroupCode=N'USER', @EntityType=NULL, @DataType=N'STRING', @SourceType=N'USER', @SourceKey=N'PositionName', @AllowedValuesJson=NULL, @SortOrder=3, @UserID=NULL;

IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'USER_UNIT_NAME')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'USER_UNIT_NAME', @Caption=N'واحد سازمانی', @GroupCode=N'USER', @EntityType=NULL, @DataType=N'STRING', @SourceType=N'USER', @SourceKey=N'UnitName', @AllowedValuesJson=NULL, @SortOrder=4, @UserID=NULL;

IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'TODAY')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'TODAY', @Caption=N'تاریخ جاری', @GroupCode=N'SYSTEM', @EntityType=NULL, @DataType=N'DATE', @SourceType=N'SYSTEM', @SourceKey=N'Today', @AllowedValuesJson=NULL, @SortOrder=5, @UserID=NULL;

IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE Code = N'GENERIC_DESCRIPTION')
    EXEC dbo.sp_Wf_SaveTemplateParameter @TemplateParameterID=NULL, @Code=N'GENERIC_DESCRIPTION', @Caption=N'توضیحات آزاد درخواست', @GroupCode=N'FORM', @EntityType=NULL, @DataType=N'STRING', @SourceType=N'FORM', @SourceKey=N'description', @AllowedValuesJson=NULL, @SortOrder=6, @UserID=NULL;
GO

-- Permissionِ جدید — هم‌الگو با patch 025/026/028
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_MANAGE_TEMPLATE_PARAMETERS')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_MANAGE_TEMPLATE_PARAMETERS', N'مدیریتِ Registryِ پارامترهایِ Template', N'Workflow', N'ایجاد/ویرایش/فعال‌سازیِ پارامترهایِ مجاز برایِ استفاده در Letter Template', 13, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1) — هم‌الگو با patch 025/026/028
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'WORKFLOW_MANAGE_TEMPLATE_PARAMETERS'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

