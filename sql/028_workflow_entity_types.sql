/* ==========================================================================
   پچ خودکار شماره: 028 | نام: workflow_entity_types
   تاریخ: 2026-09-17 06:24:45 | شامل 4 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: WorkflowEntityTypes
CREATE TABLE dbo.WorkflowEntityTypes
(
    EntityTypeID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WorkflowEntityTypes PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    ResolverClass NVARCHAR(200) NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_WfEntityTypes_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_WfEntityTypes_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_WfEntityTypes_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_WfEntityTypes_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_WfEntityTypes_Code UNIQUE (Code)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetEntityTypes
CREATE PROCEDURE dbo.sp_Wf_GetEntityTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT EntityTypeID, Code, DisplayName, ResolverClass, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate,
           (SELECT COUNT(*) FROM dbo.WorkflowDefinitions d WHERE d.EntityType = e.Code) AS DefinitionCount
    FROM dbo.WorkflowEntityTypes e
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR e.DisplayName LIKE N'%' + @SearchText + N'%'
           OR e.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR e.IsActive = @IsActive)
    ORDER BY e.SortOrder, e.EntityTypeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_SaveEntityType
CREATE PROCEDURE dbo.sp_Wf_SaveEntityType
    @EntityTypeID  INT           = NULL,   -- NULL = ایجاد
    @Code          NVARCHAR(64),
    @DisplayName   NVARCHAR(200),
    @ResolverClass NVARCHAR(200) = NULL,
    @SortOrder     INT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowEntityTypes
               WHERE Code = @Code AND (@EntityTypeID IS NULL OR EntityTypeID <> @EntityTypeID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @EntityTypeID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowEntityTypes (Code, DisplayName, ResolverClass, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, @ResolverClass, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'موجودیت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS EntityTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowEntityTypes WHERE EntityTypeID = @EntityTypeID)
        BEGIN SELECT 0 AS Success, N'موجودیت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.WorkflowEntityTypes
        SET Code = @Code, DisplayName = @DisplayName, ResolverClass = @ResolverClass, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE EntityTypeID = @EntityTypeID;

        SELECT 1 AS Success, N'موجودیت ویرایش شد.' AS Message, @EntityTypeID AS EntityTypeID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleEntityTypeActive
CREATE PROCEDURE dbo.sp_Wf_ToggleEntityTypeActive
    @EntityTypeID INT,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @Current BIT;

    BEGIN TRY
        SELECT @Current = IsActive FROM dbo.WorkflowEntityTypes WHERE EntityTypeID = @EntityTypeID;

        IF @Current IS NULL
        BEGIN
            SELECT 0 AS Success, N'موجودیت یافت نشد.' AS Message;
            RETURN;
        END

        -- غیرفعال‌کردنِ یک EntityType که Definitionِ فعال دارد، مسدود می‌شود — هم‌الگو با WorkflowCategories
        IF @Current = 1
           AND EXISTS (
               SELECT 1 FROM dbo.WorkflowDefinitions d
               JOIN dbo.WorkflowEntityTypes e ON e.EntityTypeID = @EntityTypeID
               WHERE d.EntityType = e.Code AND d.IsActive = 1
           )
        BEGIN
            SELECT 0 AS Success, N'این نوعِ موجودیت توسطِ یک یا چند فرایندِ فعال استفاده می‌شود؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message;
            RETURN;
        END

        UPDATE dbo.WorkflowEntityTypes
        SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
            Date_LastUpdate = SYSDATETIME(),
            UserID_LastUpdate = @UserID
        WHERE EntityTypeID = @EntityTypeID;

        SET @Success = 1;
        SET @Message = CASE WHEN @Current = 1 THEN N'موجودیت غیرفعال شد.' ELSE N'موجودیت فعال شد.' END;
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در تغییرِ وضعیت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

/* [DATA SEED] — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود (DML است،
   نه تغییرِ Schema)، پس دستی به پچ اضافه شد تا اجرایِ این پچ روی هر محیطِ دیگری
   کاملاً خودکفا باشد (هم‌الگو با patch 025/026). */

-- Seedِ اولیهٔ Registry — دقیقاً همان دو EntityTypeِ فعلاً واقعیِ config/workflow.php
IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowEntityTypes WHERE Code = N'MESSAGE')
    INSERT INTO dbo.WorkflowEntityTypes (Code, DisplayName, ResolverClass, SortOrder, IsActive, Date_InsertFirst)
    VALUES (N'MESSAGE', N'نامه / وظیفه', N'App\Services\Workflow\Entity\MessageEntityResolver', 1, 1, SYSDATETIME());

IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowEntityTypes WHERE Code = N'PROJECT')
    INSERT INTO dbo.WorkflowEntityTypes (Code, DisplayName, ResolverClass, SortOrder, IsActive, Date_InsertFirst)
    VALUES (N'PROJECT', N'پروژه', N'App\Services\Workflow\Entity\NullEntityResolver', 2, 1, SYSDATETIME());
GO

-- Permissionِ جدید — هم‌الگو با patch 025/026
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_MANAGE_ENTITY_TYPES')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_MANAGE_ENTITY_TYPES', N'مدیریتِ ثبتِ موجودیت‌ها', N'Workflow', N'ایجاد/ویرایش/فعال‌سازیِ Registryِ موجودیت‌هایِ قابلِ‌استفاده در Workflow', 12, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1) — هم‌الگو با patch 025/026
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'WORKFLOW_MANAGE_ENTITY_TYPES'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO
