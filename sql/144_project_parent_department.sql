-- Draft: validate application behavior and test deployment against a pre-144 schema before publishing.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF COL_LENGTH(N'dbo.Projects', N'ParentProjectID') IS NULL
    ALTER TABLE dbo.Projects ADD ParentProjectID bigint NULL;
IF COL_LENGTH(N'dbo.Projects', N'DepartmentID') IS NULL
    ALTER TABLE dbo.Projects ADD DepartmentID int NULL;
GO
IF OBJECT_ID(N'dbo.FK_Projects_ParentProject', N'F') IS NULL
    ALTER TABLE dbo.Projects WITH CHECK ADD CONSTRAINT FK_Projects_ParentProject
        FOREIGN KEY (ParentProjectID) REFERENCES dbo.Projects (ProjectID);
IF OBJECT_ID(N'dbo.FK_Projects_Department', N'F') IS NULL
    ALTER TABLE dbo.Projects WITH CHECK ADD CONSTRAINT FK_Projects_Department
        FOREIGN KEY (DepartmentID) REFERENCES dbo.CrmDepartments (DepartmentID);
IF OBJECT_ID(N'dbo.CK_Projects_NotOwnParent', N'C') IS NULL
    ALTER TABLE dbo.Projects WITH CHECK ADD CONSTRAINT CK_Projects_NotOwnParent
        CHECK (ParentProjectID <> ProjectID);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.Projects') AND name = N'IX_Projects_ParentProjectID')
    CREATE NONCLUSTERED INDEX IX_Projects_ParentProjectID ON dbo.Projects (ParentProjectID) WHERE ParentProjectID IS NOT NULL;
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_GetProjects]
    @SearchText      NVARCHAR(250) = NULL,
    @IsActive        INT = NULL,
    @ProjectStatusID INT = NULL,
    @UserID          INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.DepartmentID,
        dept.DisplayName AS DepartmentName,
        CASE WHEN @UserID IS NULL OR (parent.UserID_InsertFirst = @UserID OR EXISTS (SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID = parent.ProjectID AND m.UserID = @UserID AND m.IsActive = 1)) THEN p.ParentProjectID END AS ParentProjectID,
        CAST(CASE WHEN p.ParentProjectID IS NULL THEN 0 ELSE 1 END AS bit) AS HasParentProject,
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
        p.ProjectPriorityID,
        pp.Name       AS PriorityName,
        pp.ColorHex   AS PriorityColor,
        pp.SortOrder  AS PrioritySortOrder,
        ISNULL(u.FullName, N'') AS CreatorName,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.CrmDepartments dept ON dept.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.Projects parent ON parent.ProjectID = p.ParentProjectID
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.ProjectPriorities pp ON pp.ProjectPriorityID = p.ProjectPriorityID
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
        AND (@UserID IS NULL
             OR p.UserID_InsertFirst = @UserID          -- سازنده همیشه پروژه‌ی خودش را می‌بیند
             OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_InsertProject]
    @ProjectCode        NVARCHAR(50) = NULL,
    @ProjectTitle       NVARCHAR(250),
    @Description        NVARCHAR(MAX) = NULL,
    @StartDate          DATE = NULL,
    @PlannedEndDate     DATE = NULL,
    @ActualEndDate      DATE = NULL,
    @ProjectStatusID    INT = 2,
    @ProjectPriorityID  INT = NULL,
    @ProgressPercent    DECIMAL(5,2) = 0,
    @IsActive           BIT = 1,
    @ResponsibleUserID  INT = NULL,
    @MemberUserIDs      NVARCHAR(MAX) = NULL,
    @CreateUser         INT = NULL,
    @Year               SMALLINT = NULL,
    @ParentProjectID    BIGINT = NULL,
    @DepartmentID       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF 1=1 AND @DepartmentID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmDepartments WHERE DepartmentID=@DepartmentID AND IsActive=1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'دپارتمان انتخاب‌شده فعال یا معتبر نیست.' AS Message, CAST(1 AS BIT) AS DepartmentError;
            RETURN;
        END;
        IF @ParentProjectID IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM dbo.Projects parent WHERE parent.ProjectID = @ParentProjectID AND (parent.UserID_InsertFirst = @CreateUser OR EXISTS (SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID = parent.ProjectID AND m.UserID = @CreateUser AND m.IsActive = 1))
        )
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژهٔ والد یافت نشد یا قابل دسترسی نیست.' AS Message, CAST(1 AS BIT) AS ParentError;
            RETURN;
        END;
        DECLARE @Code NVARCHAR(100) = NULLIF(LTRIM(RTRIM(@ProjectCode)), N'');

        IF @Code IS NULL
        BEGIN
            -- توليد خودكارِ كد پروژه: PRJ-{سال شمسي}-{شماره ۴ رقمي}
            IF @Year IS NULL OR @Year < 1300 OR @Year > 1600
                SET @Year = YEAR(SYSDATETIME()) - 621;   -- fallback؛ كنترلر هميشه سال شمسي را مي‌فرستد

            MERGE dbo.ProjectNumberCounters WITH (HOLDLOCK) AS T
            USING (VALUES (@Year)) AS S (Year) ON T.Year = S.Year
            WHEN MATCHED THEN UPDATE SET LastNumber = T.LastNumber + 1
            WHEN NOT MATCHED THEN INSERT (Year, LastNumber) VALUES (@Year, 1);

            DECLARE @Seq INT = (SELECT LastNumber FROM dbo.ProjectNumberCounters WHERE Year = @Year);
            SET @Code = N'PRJ-' + CAST(@Year AS NVARCHAR(4)) + N'-' +
                        RIGHT(N'0000' + CAST(@Seq AS NVARCHAR(10)), 4);
        END
        ELSE IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @Code)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        DECLARE @NewProjectID BIGINT;

        INSERT INTO dbo.Projects
            (ProjectCode, ProjectTitle, Description, StartDate, PlannedEndDate, ActualEndDate,
             ProjectStatusID, ProjectPriorityID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst, ParentProjectID, DepartmentID)
        VALUES
            (@Code, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProjectPriorityID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser, @ParentProjectID, @DepartmentID);

        SET @NewProjectID = SCOPE_IDENTITY();

        IF @ResponsibleUserID IS NOT NULL
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES
                (@NewProjectID, @ResponsibleUserID, 1, @StartDate, SYSDATETIME(), @CreateUser);
        END

        IF @MemberUserIDs IS NOT NULL AND LTRIM(RTRIM(@MemberUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.ProjectMembers
                (ProjectID, UserID, IsResponsible, StartDate, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewProjectID, CAST(LTRIM(RTRIM(value)) AS INT), 0, @StartDate, SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@MemberUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) <> ISNULL(@ResponsibleUserID, -1);
        END

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ایجاد شد.' AS Message,
               @NewProjectID AS NewProjectID, @Code AS ProjectCode;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_UpdateProject]
    @ProjectID          BIGINT,
    @ProjectCode        NVARCHAR(50),
    @ProjectTitle       NVARCHAR(250),
    @Description        NVARCHAR(MAX) = NULL,
    @StartDate          DATE = NULL,
    @PlannedEndDate     DATE = NULL,
    @ActualEndDate      DATE = NULL,
    @ProjectStatusID    INT,
    @ProjectPriorityID  INT = NULL,
    @ProgressPercent    DECIMAL(5,2) = 0,
    @IsActive           BIT = 1,
    @ModifyUser         INT = NULL,
    @ParentProjectID    BIGINT = NULL,
    @SetParentProject   BIT = 0,
    @DepartmentID       INT = NULL,
    @SetDepartment      BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @SetDepartment = 1 AND @DepartmentID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmDepartments WHERE DepartmentID=@DepartmentID AND IsActive=1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'دپارتمان انتخاب‌شده فعال یا معتبر نیست.' AS Message, CAST(1 AS BIT) AS DepartmentError;
            RETURN;
        END;
        IF @SetParentProject = 1
        BEGIN
            IF @@TRANCOUNT = 0 THROW 51000, 'Parent updates require a transaction.', 1;
            DECLARE @LockResult int;
            EXEC @LockResult = sys.sp_getapplock @Resource = N'Raga360:project-parent',
                @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 5000;
            IF @LockResult < 0 THROW 51000, 'Could not lock the project hierarchy.', 1;
            IF @ParentProjectID IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM dbo.Projects parent WHERE parent.ProjectID = @ParentProjectID AND (parent.UserID_InsertFirst = @ModifyUser OR EXISTS (SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID = parent.ProjectID AND m.UserID = @ModifyUser AND m.IsActive = 1))
        )
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژهٔ والد یافت نشد یا قابل دسترسی نیست.' AS Message, CAST(1 AS BIT) AS ParentError;
            RETURN;
        END;
            DECLARE @Ancestor bigint = @ParentProjectID;
            WHILE @Ancestor IS NOT NULL
            BEGIN
                IF @Ancestor = @ProjectID
                BEGIN
                    SELECT CAST(0 AS BIT) AS Success, N'این انتخاب در سلسله‌مراتب پروژه‌ها چرخه ایجاد می‌کند.' AS Message, CAST(1 AS BIT) AS ParentError;
                    RETURN;
                END;
                SELECT @Ancestor = ParentProjectID FROM dbo.Projects WHERE ProjectID = @Ancestor;
            END;
        END;
        IF EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectCode = @ProjectCode AND ProjectID <> @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کد پروژه تکراری است.' AS Message;
            RETURN;
        END

        UPDATE dbo.Projects SET
            DepartmentID       = CASE WHEN @SetDepartment = 1 THEN @DepartmentID ELSE DepartmentID END,
            ParentProjectID    = CASE WHEN @SetParentProject = 1 THEN @ParentProjectID ELSE ParentProjectID END,
            ProjectCode        = @ProjectCode,
            ProjectTitle       = @ProjectTitle,
            Description        = @Description,
            StartDate          = @StartDate,
            PlannedEndDate     = @PlannedEndDate,
            ActualEndDate      = @ActualEndDate,
            ProjectStatusID    = @ProjectStatusID,
            ProjectPriorityID  = @ProjectPriorityID,
            ProgressPercent    = @ProgressPercent,
            IsActive           = @IsActive,
            Date_LastUpdate    = SYSDATETIME(),
            UserID_LastUpdate  = @ModifyUser
        WHERE ProjectID = @ProjectID;

        SELECT CAST(1 AS BIT) AS Success, N'پروژه با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش پروژه: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO
