/* ==========================================================================
   پچ خودکار شماره: 025 | نام: workflow_categories
   تاریخ: 2026-09-14 13:24:01 | شامل 11 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: WorkflowCategories
CREATE TABLE dbo.WorkflowCategories (
    CategoryID          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WorkflowCategories PRIMARY KEY,
    Code                NVARCHAR(30)  NOT NULL,
    Name                NVARCHAR(100) NOT NULL,
    Description         NVARCHAR(500) NULL,
    SortOrder           INT NOT NULL CONSTRAINT DF_WorkflowCategories_SortOrder DEFAULT (0),
    IsActive            BIT NOT NULL CONSTRAINT DF_WorkflowCategories_IsActive DEFAULT (1),
    RowGuid             UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_WorkflowCategories_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst    DATETIME2 NOT NULL CONSTRAINT DF_WorkflowCategories_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_WorkflowCategories_Code UNIQUE (Code)
)
GO

-- [ALTER_TABLE] روی TABLE: WorkflowDefinitions
ALTER TABLE dbo.WorkflowDefinitions ADD CategoryID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: WorkflowDefinitions
ALTER TABLE dbo.WorkflowDefinitions
    ADD CONSTRAINT FK_WorkflowDefinitions_Category FOREIGN KEY (CategoryID)
        REFERENCES dbo.WorkflowCategories (CategoryID)
GO

-- [CREATE_INDEX] روی INDEX: IX_WorkflowDefinitions_CategoryID
CREATE INDEX IX_WorkflowDefinitions_CategoryID ON dbo.WorkflowDefinitions (CategoryID)
GO

/* [DATA SEED] دسته‌هایِ اولیه — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود
   (چون INSERT است نه تغییرِ Schema)، پس دستی به پچ اضافه شد تا اجرایِ این پچ روی هر
   محیطِ دیگری کاملاً خودکفا باشد و نیازی به قدمِ دستیِ جداگانه نداشته باشد. */
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'ADMIN')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'ADMIN', N'اداری', 1, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'PROCUREMENT')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'PROCUREMENT', N'خرید', 2, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'FINANCE')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'FINANCE', N'مالی', 3, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'HR')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'HR', N'منابعِ انسانی', 4, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'SALES')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'SALES', N'فروش', 5, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'CONTRACTS')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'CONTRACTS', N'قراردادها', 6, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'SUPPORT')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'SUPPORT', N'پشتیبانی', 7, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'IT')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'IT', N'فناوریِ اطلاعات', 8, 2);
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE Code = N'OTHER')
    INSERT INTO dbo.WorkflowCategories (Code, Name, SortOrder, UserID_InsertFirst) VALUES (N'OTHER', N'سایر', 9, 2);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_MANAGE_CATEGORIES')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_MANAGE_CATEGORIES', N'مدیریتِ دسته‌بندیِ فرایندها', N'Workflow', N'ایجاد/ویرایش/فعال‌سازیِ دسته‌بندی‌هایِ Workflow', 10, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1) — دقیقاً همان
-- الگویِ patch 014 برایِ ۹ Permissionِ اولیهٔ Workflow (PermissionGroup = 'Workflow').
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionGroup = N'Workflow'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetCategories
/* 5) SPهایِ CRUDِ دسته‌بندی — هم‌الگو با sp_TogglePositionActive */
CREATE PROCEDURE dbo.sp_Wf_GetCategories
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CategoryID, Code, Name, Description, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate,
           (SELECT COUNT(*) FROM dbo.WorkflowDefinitions d WHERE d.CategoryID = c.CategoryID) AS DefinitionCount
    FROM dbo.WorkflowCategories c
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR c.Name LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY c.SortOrder, c.CategoryID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_SaveCategory
CREATE PROCEDURE dbo.sp_Wf_SaveCategory
    @CategoryID   INT           = NULL,   -- NULL = ایجاد
    @Code         NVARCHAR(30),
    @Name         NVARCHAR(100),
    @Description  NVARCHAR(500) = NULL,
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کدِ دسته الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ دسته الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowCategories
               WHERE Code = @Code AND (@CategoryID IS NULL OR CategoryID <> @CategoryID))
    BEGIN SELECT 0 AS Success, N'کدِ دسته تکراری است.' AS Message; RETURN; END

    IF @CategoryID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowCategories (Code, Name, Description, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @Name, @Description, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'دسته ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CategoryID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID)
        BEGIN SELECT 0 AS Success, N'دسته یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.WorkflowCategories
        SET Code = @Code, Name = @Name, Description = @Description, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CategoryID = @CategoryID;

        SELECT 1 AS Success, N'دسته به‌روزرسانی شد.' AS Message, @CategoryID AS CategoryID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleCategoryActive
CREATE PROCEDURE dbo.sp_Wf_ToggleCategoryActive
    @CategoryID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @Current BIT;

    BEGIN TRY
        SELECT @Current = IsActive FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID;

        IF @Current IS NULL
        BEGIN
            SELECT 0 AS Success, N'دسته یافت نشد.' AS Message;
            RETURN;
        END

        -- اگر فعال است و فرایندِ فعالی به آن وابسته است → غیرفعال نکن
        IF @Current = 1
           AND EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions
                       WHERE CategoryID = @CategoryID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'این دسته به فرایندِ فعالی وابسته است؛ ابتدا فرایندها را از این دسته خارج یا غیرفعال کنید.' AS Message;
            RETURN;
        END

        UPDATE dbo.WorkflowCategories
        SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
            Date_LastUpdate = SYSDATETIME(),
            UserID_LastUpdate = @UserID
        WHERE CategoryID = @CategoryID;

        SET @Success = 1;
        SET @Message = CASE WHEN @Current = 1 THEN N'دسته غیرفعال شد.' ELSE N'دسته فعال شد.' END;
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در تغییرِ وضعیتِ دسته: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetDefinitions
/* 6) افزودنِ فیلتر/نمایشِ Category به SPهایِ موجودِ Definitions — فقط افزوده،
   منطقِ قبلی دست‌نخورده. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetDefinitions
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL,
    @CategoryID INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        d.DefinitionID, d.Code, d.Name, d.Description, d.EntityType, d.IsActive,
        d.CategoryID, cat.Name AS CategoryName,
        d.Date_InsertFirst, d.UserID_InsertFirst,
        cu.FullName AS CreatedByName,
        (SELECT COUNT(*) FROM dbo.WorkflowVersions v WHERE v.DefinitionID = d.DefinitionID)                          AS VersionCount,
        (SELECT MAX(v.VersionNo) FROM dbo.WorkflowVersions v WHERE v.DefinitionID = d.DefinitionID AND v.Status = N'ACTIVE') AS ActiveVersionNo,
        (SELECT COUNT(*) FROM dbo.WorkflowInstances i WHERE i.DefinitionID = d.DefinitionID)                         AS InstanceCount
    FROM dbo.WorkflowDefinitions d
    LEFT JOIN dbo.Users cu ON cu.UserID = d.UserID_InsertFirst
    LEFT JOIN dbo.WorkflowCategories cat ON cat.CategoryID = d.CategoryID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR d.Name LIKE N'%' + @SearchText + N'%'
           OR d.Code LIKE N'%' + @SearchText + N'%'
           OR d.EntityType LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR d.IsActive = @IsActive)
      AND (@CategoryID IS NULL OR d.CategoryID = @CategoryID)
    ORDER BY d.DefinitionID DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetDefinition
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetDefinition
    @DefinitionID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT d.DefinitionID, d.Code, d.Name, d.Description, d.EntityType, d.IsActive,
           d.CategoryID, cat.Name AS CategoryName,
           d.Date_InsertFirst, d.UserID_InsertFirst, d.Date_LastUpdate, d.UserID_LastUpdate
    FROM dbo.WorkflowDefinitions d
    LEFT JOIN dbo.WorkflowCategories cat ON cat.CategoryID = d.CategoryID
    WHERE d.DefinitionID = @DefinitionID;

    -- نسخه‌ها
    SELECT v.VersionID, v.VersionNo, v.Status, v.PublishedAt, v.ArchivedAt, v.ClonedFromVersionID,
           pu.FullName AS PublishedByName, v.ValidationResultJson,
           v.Date_InsertFirst,
           (SELECT COUNT(*) FROM dbo.WorkflowSteps s WHERE s.VersionID = v.VersionID)       AS StepCount,
           (SELECT COUNT(*) FROM dbo.WorkflowInstances i WHERE i.VersionID = v.VersionID)    AS InstanceCount
    FROM dbo.WorkflowVersions v
    LEFT JOIN dbo.Users pu ON pu.UserID = v.PublishedByUserID
    WHERE v.DefinitionID = @DefinitionID
    ORDER BY v.VersionNo DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveDefinition
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveDefinition
    @DefinitionID INT           = NULL,   -- NULL = ایجاد
    @Code         NVARCHAR(64),
    @Name         NVARCHAR(200),
    @Description  NVARCHAR(1000) = NULL,
    @EntityType   NVARCHAR(64),
    @IsActive     BIT           = 1,
    @CategoryID   INT           = NULL,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد فرایند الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام فرایند الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@EntityType)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نوع موجودیت (EntityType) الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions
               WHERE Code = @Code AND (@DefinitionID IS NULL OR DefinitionID <> @DefinitionID))
    BEGIN SELECT 0 AS Success, N'کد فرایند تکراری است.' AS Message; RETURN; END

    IF @CategoryID IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID)
        BEGIN SELECT 0 AS Success, N'دستهٔ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

        DECLARE @CatActive BIT, @PrevCategoryID INT = NULL;
        SELECT @CatActive = IsActive FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID;
        IF @DefinitionID IS NOT NULL
            SELECT @PrevCategoryID = CategoryID FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID;

        IF @CatActive = 0 AND (@PrevCategoryID IS NULL OR @PrevCategoryID <> @CategoryID)
        BEGIN SELECT 0 AS Success, N'این دسته غیرفعال است و برایِ انتخابِ جدید قابلِ استفاده نیست.' AS Message; RETURN; END
    END

    IF @DefinitionID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowDefinitions (Code, Name, Description, EntityType, IsActive, CategoryID, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @Name, @Description, @EntityType, @IsActive, @CategoryID, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فرایند ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS DefinitionID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
        BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.WorkflowDefinitions
        SET Code = @Code, Name = @Name, Description = @Description, EntityType = @EntityType, IsActive = @IsActive,
            CategoryID = @CategoryID, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE DefinitionID = @DefinitionID;

        SELECT 1 AS Success, N'فرایند به‌روزرسانی شد.' AS Message, @DefinitionID AS DefinitionID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionMeta
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionMeta
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT v.VersionID, v.DefinitionID, v.VersionNo, v.Status, v.ValidationResultJson,
           v.PublishedAt, v.PublishedByUserID, v.ArchivedAt, v.ClonedFromVersionID,
           d.Code AS DefinitionCode, d.Name AS DefinitionName, d.EntityType,
           d.CategoryID, cat.Name AS CategoryName
    FROM dbo.WorkflowVersions v
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = v.DefinitionID
    LEFT JOIN dbo.WorkflowCategories cat ON cat.CategoryID = d.CategoryID
    WHERE v.VersionID = @VersionID;
END
GO

