/* ==========================================================================
   پچ خودکار شماره: 005 | نام: docker_compose_exec_app_sh_c_ls_lah_sql_echo_patches_ls_lah_sqlpatches_2devnull
   تاریخ: 2026-08-30 12:53:18 | شامل 77 دستور SQL
   ========================================================================== */


-- [CREATE_TABLE] روی TABLE: Projects
CREATE TABLE [dbo].[Projects]
(
    ProjectID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_Projects PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_Projects_RowGuid
        DEFAULT NEWSEQUENTIALID(),

    ProjectCode NVARCHAR(50) NOT NULL,
    ProjectTitle NVARCHAR(250) NOT NULL,
    Description NVARCHAR(MAX) NULL,

    StartDate DATE NULL,
    PlannedEndDate DATE NULL,
    ActualEndDate DATE NULL,

    ProjectStatusID INT NOT NULL,

    ProgressPercent DECIMAL(5,2) NOT NULL
        CONSTRAINT DF_Projects_ProgressPercent DEFAULT (0),

    IsActive BIT NOT NULL
        CONSTRAINT DF_Projects_IsActive DEFAULT (1),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_Projects_Date_InsertFirst
        DEFAULT SYSDATETIME(),

    UserID_InsertFirst INT NULL,

    Date_LastUpdate DATETIME2(3) NULL,
    UserID_LastUpdate INT NULL,

    CONSTRAINT UQ_Projects_ProjectCode
        UNIQUE (ProjectCode)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_Projects_ProjectStatusID
CREATE INDEX IX_Projects_ProjectStatusID
ON [dbo].[Projects] (ProjectStatusID)
GO

-- [CREATE_INDEX] روی INDEX: IX_Projects_StartDate
CREATE INDEX IX_Projects_StartDate
ON [dbo].[Projects] (StartDate)
GO

-- [CREATE_INDEX] روی INDEX: IX_Projects_PlannedEndDate
CREATE INDEX IX_Projects_PlannedEndDate
ON [dbo].[Projects] (PlannedEndDate)
GO

-- [CREATE_INDEX] روی INDEX: IX_Projects_IsActive
CREATE INDEX IX_Projects_IsActive
ON [dbo].[Projects] (IsActive)
GO

-- [CREATE_TABLE] روی TABLE: ProjectMembers
CREATE TABLE [dbo].[ProjectMembers]
(
    ProjectMemberID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_ProjectMembers PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_ProjectMembers_RowGuid
        DEFAULT NEWSEQUENTIALID(),

    ProjectID BIGINT NOT NULL,
    UserID INT NOT NULL,

    IsResponsible BIT NOT NULL
        CONSTRAINT DF_ProjectMembers_IsResponsible DEFAULT (0),

    StartDate DATE NULL,
    EndDate DATE NULL,

    IsActive BIT NOT NULL
        CONSTRAINT DF_ProjectMembers_IsActive DEFAULT (1),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_ProjectMembers_Date_InsertFirst
        DEFAULT SYSDATETIME(),

    UserID_InsertFirst INT NULL,

    Date_LastUpdate DATETIME2(3) NULL,
    UserID_LastUpdate INT NULL,

    CONSTRAINT UQ_ProjectMembers_Project_User
        UNIQUE (ProjectID, UserID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectMembers_UserID
CREATE INDEX IX_ProjectMembers_UserID
ON [dbo].[ProjectMembers] (UserID)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectMembers_ProjectID_IsActive
CREATE INDEX IX_ProjectMembers_ProjectID_IsActive
ON [dbo].[ProjectMembers] (ProjectID, IsActive)
GO

-- [CREATE_INDEX] روی INDEX: UX_ProjectMembers_OneResponsible
CREATE UNIQUE INDEX UX_ProjectMembers_OneResponsible
ON [dbo].[ProjectMembers] (ProjectID)
WHERE IsResponsible = 1
GO

-- [CREATE_TABLE] روی TABLE: ProjectMessages
CREATE TABLE [dbo].[ProjectMessages]
(
    ProjectMessageID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_ProjectMessages PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_ProjectMessages_RowGuid
        DEFAULT NEWSEQUENTIALID(),

    ProjectID BIGINT NOT NULL,

    MessageID BIGINT NOT NULL,

    SortOrder INT NOT NULL
        CONSTRAINT DF_ProjectMessages_SortOrder DEFAULT (0),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_ProjectMessages_Date_InsertFirst
        DEFAULT SYSDATETIME(),

    UserID_InsertFirst INT NULL,

    Date_LastUpdate DATETIME2(3) NULL,

    UserID_LastUpdate INT NULL,

    CONSTRAINT UQ_ProjectMessages_Project_Message
        UNIQUE (ProjectID, MessageID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectMessages_MessageID
CREATE INDEX IX_ProjectMessages_MessageID
ON [dbo].[ProjectMessages] (MessageID)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectMessages_ProjectID
CREATE INDEX IX_ProjectMessages_ProjectID
ON [dbo].[ProjectMessages] (ProjectID)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectMessages_ProjectID_SortOrder
CREATE INDEX IX_ProjectMessages_ProjectID_SortOrder
ON [dbo].[ProjectMessages] (ProjectID, SortOrder)
GO

-- [ALTER_TABLE] روی TABLE: ProjectMessages
ALTER TABLE [dbo].[ProjectMessages]
ADD CONSTRAINT FK_ProjectMessages_Projects
    FOREIGN KEY (ProjectID)
    REFERENCES [dbo].[Projects](ProjectID)
GO

-- [ALTER_TABLE] روی TABLE: ProjectMessages
ALTER TABLE [dbo].[ProjectMessages]
ALTER COLUMN [MessageID] BIGINT NULL
GO

-- [ALTER_TABLE] روی TABLE: ProjectMessages
ALTER TABLE [dbo].[ProjectMessages]
DROP CONSTRAINT [UQ_ProjectMessages_Project_Message]
GO

-- [DROP_INDEX] روی INDEX: IX_ProjectMessages_MessageID
DROP INDEX [IX_ProjectMessages_MessageID]
ON [dbo].[ProjectMessages]
GO

-- [ALTER_TABLE] روی TABLE: ProjectMessages
ALTER TABLE [dbo].[ProjectMessages]
ALTER COLUMN [MessageID] INT NULL
GO

-- [CREATE_TABLE] روی TABLE: ProjectStatuses
CREATE TABLE [dbo].[ProjectStatuses] (
        ProjectStatusID INT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_ProjectStatuses PRIMARY KEY,
        Title NVARCHAR(100) NOT NULL,
        Description NVARCHAR(500) NULL,
        SortOrder INT NOT NULL DEFAULT 0,
        IsActive BIT NOT NULL DEFAULT 1,
        RowGuid UNIQUEIDENTIFIER NOT NULL DEFAULT NEWSEQUENTIALID(),
        Date_InsertFirst DATETIME2(3) NOT NULL DEFAULT SYSDATETIME(),
        UserID_InsertFirst INT NULL
    )
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectStatuses
CREATE PROCEDURE [dbo].[sp_GetProjectStatuses]
    @IsActive INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ProjectStatusID, Title, Description, SortOrder
    FROM dbo.ProjectStatuses
    WHERE (@IsActive IS NULL OR IsActive = @IsActive)
    ORDER BY SortOrder;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetUsers
DROP PROCEDURE dbo.sp_GetUsers
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUsers
CREATE PROCEDURE [dbo].[sp_GetUsers]
    @SearchText NVARCHAR(100) = NULL,
    @IsActive INT = 1
AS
BEGIN
    SET NOCOUNT ON;
    /* نمایش نام از ستون UserName استفاده می‌شود.
       اگر dbo.Users ستون‌های FirstName / LastName (یا Name) دارد،
       خط زیر را به شکل زیر اصلاح کنید:
       UserName AS FullName
       -->
       ISNULL(FirstName + ' ' + LastName, UserName) AS FullName */
    SELECT
        UserID,
        UserName AS FullName
    FROM dbo.Users
    WHERE (@IsActive IS NULL OR IsActive = @IsActive)
      AND (@SearchText IS NULL OR @SearchText = '' OR UserName LIKE N'%' + @SearchText + N'%')
    ORDER BY UserName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText    NVARCHAR(250) = NULL,
    @IsActive      INT = NULL,   -- NULL = همه، 1 = فعال، 0 = غیرفعال
    @ProjectStatusID INT = NULL,
    @UserID        INT = NULL    -- NULL = همه؛ وإلا فقط پروژه‌هایی که کاربر عضو/مسئول آن است
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        ISNULL(u.UserName, N'')  AS CreatorName,
        ISNULL(resp.UserName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 r.UserName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProject
CREATE PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT = 2,            -- پیش‌فرض: در حال اجرا
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ResponsibleUserID INT = NULL,        -- مسئول پروژه (اجباری از سمت فرانت‌اند)
    @MemberUserIDs    NVARCHAR(MAX) = NULL, -- لیست اعضا (جدا شده با ویرگول، به جز مسئول)
    @CreateUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

        SET @NewProjectID = SCOPE_IDENTITY();

        /* مسئول پروژه */
        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        /* اعضای پروژه (به جز مسئول) */
        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message, @NewProjectID AS NewProjectID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateProject
CREATE PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID        BIGINT,
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT,
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ModifyUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            ProjectCode      = @ProjectCode,
            ProjectTitle     = @ProjectTitle,
            Description      = @Description,
            StartDate        = @StartDate,
            PlannedEndDate   = @PlannedEndDate,
            ActualEndDate    = @ActualEndDate,
            ProjectStatusID  = @ProjectStatusID,
            ProgressPercent  = @ProgressPercent,
            IsActive         = @IsActive,
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
CREATE PROCEDURE [dbo].[sp_ToggleProjectActive]
    @ProjectID  BIGINT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @NewActive BIT;
        SELECT @NewActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
        FROM dbo.Projects WHERE ProjectID = @ProjectID;

        UPDATE dbo.Projects
        SET IsActive = @NewActive, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success,
               CASE WHEN @NewActive = 1 THEN N'پروژه فعال شد.' ELSE N'پروژه غیرفعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjects
DROP PROCEDURE dbo.sp_GetProjects
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText    NVARCHAR(250) = NULL,
    @IsActive      INT = NULL,   -- NULL = همه، 1 = فعال، 0 = غیرفعال
    @ProjectStatusID INT = NULL,
    @UserID        INT = NULL    -- NULL = همه؛ وإلا فقط پروژه‌هایی که کاربر عضو/مسئول آن است
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        ISNULL(u.UserName, N'')  AS CreatorName,
        ISNULL(resp.UserName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 r.UserName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProject
DROP PROCEDURE dbo.sp_InsertProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProject
CREATE PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT = 2,            -- پیش‌فرض: در حال اجرا
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ResponsibleUserID INT = NULL,        -- مسئول پروژه (اجباری از سمت فرانت‌اند)
    @MemberUserIDs    NVARCHAR(MAX) = NULL, -- لیست اعضا (جدا شده با ویرگول، به جز مسئول)
    @CreateUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

        SET @NewProjectID = SCOPE_IDENTITY();

        /* مسئول پروژه */
        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        /* اعضای پروژه (به جز مسئول) */
        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message, @NewProjectID AS NewProjectID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_UpdateProject
DROP PROCEDURE dbo.sp_UpdateProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateProject
CREATE PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID        BIGINT,
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT,
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ModifyUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            ProjectCode      = @ProjectCode,
            ProjectTitle     = @ProjectTitle,
            Description      = @Description,
            StartDate        = @StartDate,
            PlannedEndDate   = @PlannedEndDate,
            ActualEndDate    = @ActualEndDate,
            ProjectStatusID  = @ProjectStatusID,
            ProgressPercent  = @ProgressPercent,
            IsActive         = @IsActive,
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
DROP PROCEDURE dbo.sp_ToggleProjectActive
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
CREATE PROCEDURE [dbo].[sp_ToggleProjectActive]
    @ProjectID  BIGINT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @NewActive BIT;
        SELECT @NewActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
        FROM dbo.Projects WHERE ProjectID = @ProjectID;

        UPDATE dbo.Projects
        SET IsActive = @NewActive, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success,
               CASE WHEN @NewActive = 1 THEN N'پروژه فعال شد.' ELSE N'پروژه غیرفعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjects
DROP PROCEDURE dbo.sp_GetProjects
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText    NVARCHAR(250) = NULL,
    @IsActive      INT = NULL,   -- NULL = همه، 1 = فعال، 0 = غیرفعال
    @ProjectStatusID INT = NULL,
    @UserID        INT = NULL    -- NULL = همه؛ وإلا فقط پروژه‌هایی که کاربر عضو/مسئول آن است
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        ISNULL(u.UserName, N'')  AS CreatorName,
        ISNULL(resp.UserName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 r.UserName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProject
DROP PROCEDURE dbo.sp_InsertProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProject
CREATE PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT = 2,            -- پیش‌فرض: در حال اجرا
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ResponsibleUserID INT = NULL,        -- مسئول پروژه (اجباری از سمت فرانت‌اند)
    @MemberUserIDs    NVARCHAR(MAX) = NULL, -- لیست اعضا (جدا شده با ویرگول، به جز مسئول)
    @CreateUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

        SET @NewProjectID = SCOPE_IDENTITY();

        /* مسئول پروژه */
        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        /* اعضای پروژه (به جز مسئول) */
        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message, @NewProjectID AS NewProjectID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_UpdateProject
DROP PROCEDURE dbo.sp_UpdateProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateProject
CREATE PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID        BIGINT,
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT,
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ModifyUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            ProjectCode      = @ProjectCode,
            ProjectTitle     = @ProjectTitle,
            Description      = @Description,
            StartDate        = @StartDate,
            PlannedEndDate   = @PlannedEndDate,
            ActualEndDate    = @ActualEndDate,
            ProjectStatusID  = @ProjectStatusID,
            ProgressPercent  = @ProgressPercent,
            IsActive         = @IsActive,
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
DROP PROCEDURE dbo.sp_ToggleProjectActive
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
CREATE PROCEDURE [dbo].[sp_ToggleProjectActive]
    @ProjectID  BIGINT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @NewActive BIT;
        SELECT @NewActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
        FROM dbo.Projects WHERE ProjectID = @ProjectID;

        UPDATE dbo.Projects
        SET IsActive = @NewActive, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success,
               CASE WHEN @NewActive = 1 THEN N'پروژه فعال شد.' ELSE N'پروژه غیرفعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
CREATE PROCEDURE [dbo].[sp_GetProjectMembers]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        pm.ProjectMemberID,
        pm.ProjectID,
        pm.UserID,
        u.UserName   AS FullName,
        pm.IsResponsible,
        pm.StartDate,
        pm.EndDate,
        pm.IsActive
    FROM dbo.ProjectMembers pm
    LEFT JOIN dbo.Users u ON u.UserID = pm.UserID
    WHERE pm.ProjectID = @ProjectID
    ORDER BY pm.IsResponsible DESC, u.UserName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_AddProjectMember
CREATE PROCEDURE [dbo].[sp_AddProjectMember]
    @ProjectID      BIGINT,
    @UserID         INT,
    @IsResponsible  BIT = 0,
    @StartDate      DATE = NULL,
    @CreateUser     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژه یافت نشد.' AS Message;
            RETURN;
        END

        /* اگر قرار است مسئول شود، سایر مسئول‌ها را صفر می‌کنیم (یک مسئول در هر پروژه) */
        IF @IsResponsible = 1
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsResponsible = 0
            WHERE ProjectID = @ProjectID AND IsActive = 1;
        END

        /* اگر قبلاً حذف (IsActive=0) شده بود، دوباره فعالش می‌کنیم */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID = @ProjectID AND UserID = @UserID)
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsActive      = 1,
                IsResponsible = @IsResponsible,
                EndDate       = NULL,
                StartDate     = ISNULL(@StartDate, StartDate),
                Date_LastUpdate  = SYSDATETIME(),
                UserID_LastUpdate = @CreateUser
            WHERE ProjectID = @ProjectID AND UserID = @UserID;

            SELECT CAST(1 AS BIT) AS Success, N'عضو دوباره به پروژه اضافه شد.' AS Message;
            RETURN;
        END

        INSERT INTO dbo.ProjectMembers
            (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectID, @UserID, @IsResponsible, @StartDate, SYSDATETIME(), @CreateUser);

        SELECT CAST(1 AS BIT) AS Success, N'عضو با موفقیت اضافه شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در افزودن عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_RemoveProjectMember
CREATE PROCEDURE [dbo].[sp_RemoveProjectMember]
    @ProjectID  BIGINT,
    @UserID     INT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        /* مسئول پروژه قابل حذف نیست؛ ابتدا مسئول را تغییر دهید */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers
                   WHERE ProjectID = @ProjectID AND UserID = @UserID AND IsResponsible = 1 AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'مسئول پروژه قابل حذف نیست. ابتدا مسئول را تغییر دهید.' AS Message;
            RETURN;
        END

        UPDATE dbo.ProjectMembers
        SET IsActive         = 0,
            EndDate          = CAST(SYSDATETIME() AS DATE),
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID AND UserID = @UserID;

        SELECT CAST(1 AS BIT) AS Success, N'عضو از پروژه حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
DROP PROCEDURE dbo.sp_GetProjectMembers
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
CREATE PROCEDURE [dbo].[sp_GetProjectMembers]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        pm.ProjectMemberID,
        pm.ProjectID,
        pm.UserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS FullName,
        pm.IsResponsible,
        pm.StartDate,
        pm.EndDate,
        pm.IsActive
    FROM dbo.ProjectMembers pm
    LEFT JOIN dbo.Users u ON u.UserID = pm.UserID
    WHERE pm.ProjectID = @ProjectID
    ORDER BY pm.IsResponsible DESC, dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName);
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_AddProjectMember
DROP PROCEDURE dbo.sp_AddProjectMember
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_AddProjectMember
CREATE PROCEDURE [dbo].[sp_AddProjectMember]
    @ProjectID      BIGINT,
    @UserID         INT,
    @IsResponsible  BIT = 0,
    @StartDate      DATE = NULL,
    @CreateUser     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژه یافت نشد.' AS Message;
            RETURN;
        END

        /* اگر قرار است مسئول شود، سایر مسئول‌ها را صفر می‌کنیم (یک مسئول در هر پروژه) */
        IF @IsResponsible = 1
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsResponsible = 0
            WHERE ProjectID = @ProjectID AND IsActive = 1;
        END

        /* اگر قبلاً حذف (IsActive=0) شده بود، دوباره فعالش می‌کنیم */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID = @ProjectID AND UserID = @UserID)
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsActive      = 1,
                IsResponsible = @IsResponsible,
                EndDate       = NULL,
                StartDate     = ISNULL(@StartDate, StartDate),
                Date_LastUpdate  = SYSDATETIME(),
                UserID_LastUpdate = @CreateUser
            WHERE ProjectID = @ProjectID AND UserID = @UserID;

            SELECT CAST(1 AS BIT) AS Success, N'عضو دوباره به پروژه اضافه شد.' AS Message;
            RETURN;
        END

        INSERT INTO dbo.ProjectMembers
            (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectID, @UserID, @IsResponsible, @StartDate, SYSDATETIME(), @CreateUser);

        SELECT CAST(1 AS BIT) AS Success, N'عضو با موفقیت اضافه شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در افزودن عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_RemoveProjectMember
DROP PROCEDURE dbo.sp_RemoveProjectMember
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_RemoveProjectMember
CREATE PROCEDURE [dbo].[sp_RemoveProjectMember]
    @ProjectID  BIGINT,
    @UserID     INT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        /* مسئول پروژه قابل حذف نیست؛ ابتدا مسئول را تغییر دهید */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers
                   WHERE ProjectID = @ProjectID AND UserID = @UserID AND IsResponsible = 1 AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'مسئول پروژه قابل حذف نیست. ابتدا مسئول را تغییر دهید.' AS Message;
            RETURN;
        END

        UPDATE dbo.ProjectMembers
        SET IsActive         = 0,
            EndDate          = CAST(SYSDATETIME() AS DATE),
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID AND UserID = @UserID;

        SELECT CAST(1 AS BIT) AS Success, N'عضو از پروژه حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjects
DROP PROCEDURE dbo.sp_GetProjects
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText    NVARCHAR(250) = NULL,
    @IsActive      INT = NULL,   -- NULL = همه، 1 = فعال، 0 = غیرفعال
    @ProjectStatusID INT = NULL,
    @UserID        INT = NULL    -- NULL = همه؛ وإلا فقط پروژه‌هایی که کاربر عضو/مسئول آن است
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS CreatorName,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 dbo.fn_GetUserFullName(r.FirstName, r.LastName, r.UserName) AS FullName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProject
DROP PROCEDURE dbo.sp_InsertProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProject
CREATE PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT = 2,            -- پیش‌فرض: در حال اجرا
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ResponsibleUserID INT = NULL,        -- مسئول پروژه (اجباری از سمت فرانت‌اند)
    @MemberUserIDs    NVARCHAR(MAX) = NULL, -- لیست اعضا (جدا شده با ویرگول، به جز مسئول)
    @CreateUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

        SET @NewProjectID = SCOPE_IDENTITY();

        /* مسئول پروژه */
        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        /* اعضای پروژه (به جز مسئول) */
        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message, @NewProjectID AS NewProjectID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_UpdateProject
DROP PROCEDURE dbo.sp_UpdateProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateProject
CREATE PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID        BIGINT,
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT,
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ModifyUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            ProjectCode      = @ProjectCode,
            ProjectTitle     = @ProjectTitle,
            Description      = @Description,
            StartDate        = @StartDate,
            PlannedEndDate   = @PlannedEndDate,
            ActualEndDate    = @ActualEndDate,
            ProjectStatusID  = @ProjectStatusID,
            ProgressPercent  = @ProgressPercent,
            IsActive         = @IsActive,
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
DROP PROCEDURE dbo.sp_ToggleProjectActive
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
CREATE PROCEDURE [dbo].[sp_ToggleProjectActive]
    @ProjectID  BIGINT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @NewActive BIT;
        SELECT @NewActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
        FROM dbo.Projects WHERE ProjectID = @ProjectID;

        UPDATE dbo.Projects
        SET IsActive = @NewActive, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success,
               CASE WHEN @NewActive = 1 THEN N'پروژه فعال شد.' ELSE N'پروژه غیرفعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_FUNCTION] روی FUNCTION: fn_GetUserFullName
CREATE FUNCTION dbo.fn_GetUserFullName
(
    @FirstName NVARCHAR(100),
    @LastName  NVARCHAR(100),
    @UserName  NVARCHAR(100)
)
RETURNS NVARCHAR(210)
AS
BEGIN
    DECLARE @Combined NVARCHAR(210) =
        LTRIM(RTRIM(ISNULL(@FirstName, N'') + N' ' + ISNULL(@LastName, N'')));

    RETURN CASE WHEN @Combined = N'' THEN ISNULL(@UserName, N'') ELSE @Combined END;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjects
DROP PROCEDURE dbo.sp_GetProjects
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText    NVARCHAR(250) = NULL,
    @IsActive      INT = NULL,   -- NULL = همه، 1 = فعال، 0 = غیرفعال
    @ProjectStatusID INT = NULL,
    @UserID        INT = NULL    -- NULL = همه؛ وإلا فقط پروژه‌هایی که کاربر عضو/مسئول آن است
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        ISNULL(u.FullName, N'') AS CreatorName,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 r.FullName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProject
DROP PROCEDURE dbo.sp_InsertProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProject
CREATE PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT = 2,            -- پیش‌فرض: در حال اجرا
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ResponsibleUserID INT = NULL,        -- مسئول پروژه (اجباری از سمت فرانت‌اند)
    @MemberUserIDs    NVARCHAR(MAX) = NULL, -- لیست اعضا (جدا شده با ویرگول، به جز مسئول)
    @CreateUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

        SET @NewProjectID = SCOPE_IDENTITY();

        /* مسئول پروژه */
        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        /* اعضای پروژه (به جز مسئول) */
        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message, @NewProjectID AS NewProjectID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_UpdateProject
DROP PROCEDURE dbo.sp_UpdateProject
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateProject
CREATE PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID        BIGINT,
    @ProjectCode      NVARCHAR(50),
    @ProjectTitle     NVARCHAR(250),
    @Description      NVARCHAR(MAX) = NULL,
    @StartDate        DATE = NULL,
    @PlannedEndDate   DATE = NULL,
    @ActualEndDate    DATE = NULL,
    @ProjectStatusID  INT,
    @ProgressPercent  DECIMAL(5,2) = 0,
    @IsActive         BIT = 1,
    @ModifyUser       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            ProjectCode      = @ProjectCode,
            ProjectTitle     = @ProjectTitle,
            Description      = @Description,
            StartDate        = @StartDate,
            PlannedEndDate   = @PlannedEndDate,
            ActualEndDate    = @ActualEndDate,
            ProjectStatusID  = @ProjectStatusID,
            ProgressPercent  = @ProgressPercent,
            IsActive         = @IsActive,
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
DROP PROCEDURE dbo.sp_ToggleProjectActive
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_ToggleProjectActive
CREATE PROCEDURE [dbo].[sp_ToggleProjectActive]
    @ProjectID  BIGINT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @NewActive BIT;
        SELECT @NewActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
        FROM dbo.Projects WHERE ProjectID = @ProjectID;

        UPDATE dbo.Projects
        SET IsActive = @NewActive, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success,
               CASE WHEN @NewActive = 1 THEN N'پروژه فعال شد.' ELSE N'پروژه غیرفعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
DROP PROCEDURE dbo.sp_GetProjectMembers
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
CREATE PROCEDURE [dbo].[sp_GetProjectMembers]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        pm.ProjectMemberID,
        pm.ProjectID,
        pm.UserID,
        u.FullName,
        pm.IsResponsible,
        pm.StartDate,
        pm.EndDate,
        pm.IsActive
    FROM dbo.ProjectMembers pm
    LEFT JOIN dbo.Users u ON u.UserID = pm.UserID
    WHERE pm.ProjectID = @ProjectID
    ORDER BY pm.IsResponsible DESC, u.FullName;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_AddProjectMember
DROP PROCEDURE dbo.sp_AddProjectMember
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_AddProjectMember
CREATE PROCEDURE [dbo].[sp_AddProjectMember]
    @ProjectID      BIGINT,
    @UserID         INT,
    @IsResponsible  BIT = 0,
    @StartDate      DATE = NULL,
    @CreateUser     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژه یافت نشد.' AS Message;
            RETURN;
        END

        /* اگر قرار است مسئول شود، سایر مسئول‌ها را صفر می‌کنیم (یک مسئول در هر پروژه) */
        IF @IsResponsible = 1
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsResponsible = 0
            WHERE ProjectID = @ProjectID AND IsActive = 1;
        END

        /* اگر قبلاً حذف (IsActive=0) شده بود، دوباره فعالش می‌کنیم */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID = @ProjectID AND UserID = @UserID)
        BEGIN
            UPDATE dbo.ProjectMembers
            SET IsActive      = 1,
                IsResponsible = @IsResponsible,
                EndDate       = NULL,
                StartDate     = ISNULL(@StartDate, StartDate),
                Date_LastUpdate  = SYSDATETIME(),
                UserID_LastUpdate = @CreateUser
            WHERE ProjectID = @ProjectID AND UserID = @UserID;

            SELECT CAST(1 AS BIT) AS Success, N'عضو دوباره به پروژه اضافه شد.' AS Message;
            RETURN;
        END

        INSERT INTO dbo.ProjectMembers
            (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectID, @UserID, @IsResponsible, @StartDate, SYSDATETIME(), @CreateUser);

        SELECT CAST(1 AS BIT) AS Success, N'عضو با موفقیت اضافه شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در افزودن عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_RemoveProjectMember
DROP PROCEDURE dbo.sp_RemoveProjectMember
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_RemoveProjectMember
CREATE PROCEDURE [dbo].[sp_RemoveProjectMember]
    @ProjectID  BIGINT,
    @UserID     INT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        /* مسئول پروژه قابل حذف نیست؛ ابتدا مسئول را تغییر دهید */
        IF EXISTS (SELECT 1 FROM dbo.ProjectMembers
                   WHERE ProjectID = @ProjectID AND UserID = @UserID AND IsResponsible = 1 AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'مسئول پروژه قابل حذف نیست. ابتدا مسئول را تغییر دهید.' AS Message;
            RETURN;
        END

        UPDATE dbo.ProjectMembers
        SET IsActive         = 0,
            EndDate          = CAST(SYSDATETIME() AS DATE),
            Date_LastUpdate  = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID AND UserID = @UserID;

        SELECT CAST(1 AS BIT) AS Success, N'عضو از پروژه حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف عضو: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUsers
/* ===================================================== */
/* PROCEDURE: [dbo].[sp_GetUsers]                        */
/* ===================================================== */
ALTER  PROCEDURE [dbo].[sp_GetUsers]
    @SearchText NVARCHAR(100) = NULL,
    @IsActive INT = 1
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        UserID,
        ISNULL(NULLIF(LTRIM(RTRIM(ISNULL(FirstName, N'') + N' ' + ISNULL(LastName, N''))), N''), UserName) AS FullName
    FROM dbo.Users
    WHERE (@IsActive IS NULL OR IsActive = @IsActive)
      AND (@SearchText IS NULL OR @SearchText = ''
           OR UserName LIKE N'%' + @SearchText + N'%'
           OR FirstName LIKE N'%' + @SearchText + N'%'
           OR LastName LIKE N'%' + @SearchText + N'%')
    ORDER BY FullName;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetProjectMembers
/* ===================================================== */
/* PROCEDURE: [dbo].[sp_GetProjectMembers]                */
/* اضافه شد: Date_InsertFirst (تاریخ ایجاد رکورد عضویت)    */
/* ===================================================== */
CREATE OR ALTER PROCEDURE [dbo].[sp_GetProjectMembers]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        pm.ProjectMemberID,
        pm.ProjectID,
        pm.UserID,
        u.FullName,
        pm.IsResponsible,
        pm.StartDate,
        pm.EndDate,
        pm.IsActive,
        pm.Date_InsertFirst
    FROM dbo.ProjectMembers pm
    LEFT JOIN dbo.Users u ON u.UserID = pm.UserID
    WHERE pm.ProjectID = @ProjectID
    ORDER BY pm.IsResponsible DESC, u.FullName;
END
GO

