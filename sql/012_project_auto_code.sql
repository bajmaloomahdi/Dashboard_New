/* ==========================================================================
   پچ خودکار شماره: 012 | نام: project_auto_code
   تاریخ: 2026-09-07 19:06:28 | شامل 2 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: ProjectNumberCounters
CREATE TABLE dbo.ProjectNumberCounters (
        Year       SMALLINT     NOT NULL PRIMARY KEY,
        LastNumber INT          NOT NULL CONSTRAINT DF_ProjectNumberCounters_LastNumber DEFAULT (0)
    )
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertProject
ALTER PROCEDURE [dbo].[sp_InsertProject]
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
    @Year               SMALLINT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
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
             ProjectStatusID, ProjectPriorityID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
             @ProjectStatusID, @ProjectPriorityID, @ProgressPercent, @IsActive, SYSDATETIME(), @CreateUser);

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


/* ==========================================================================
   [MANUAL DATA SEED] — این بخش به‌صورت دستی اضافه شده است، نه توسط تریگر DDL.
   تریگر trg_DevTrackSchemaChanges فقط رویدادهای DDL را ثبت می‌کند و
   UPDATE/INSERT های داده‌ای (DML) را ردیابی نمی‌کند؛ بنابراین اصلاح کد
   پروژه‌های موجود و مقداردهی اولیه‌ی شمارنده باید همراه همین پچ اعمال شود.

   پروژه‌های موجود در زمان این پچ (هر دو در سال شمسی ۱۴۰۵ ایجاد شده‌اند):
     ProjectID 1  «ساخت انبار اصفهان»  P001  →  PRJ-1405-0001
     ProjectID 2  (تستی)               1     →  PRJ-1405-0002
   ========================================================================== */

-- [DATA_SEED] مقداردهی اولیه‌ی شمارنده‌ی سال ۱۴۰۵ روی ۲ (دو پروژه‌ی موجود)
MERGE dbo.ProjectNumberCounters WITH (HOLDLOCK) AS T
USING (VALUES (CAST(1405 AS SMALLINT))) AS S (Year) ON T.Year = S.Year
WHEN MATCHED THEN UPDATE SET LastNumber = CASE WHEN T.LastNumber < 2 THEN 2 ELSE T.LastNumber END
WHEN NOT MATCHED THEN INSERT (Year, LastNumber) VALUES (1405, 2);
GO

-- [DATA_SEED] اصلاح کد دو پروژه‌ی قبلی به قالب استاندارد PRJ-{سال}-{شماره}
UPDATE dbo.Projects SET ProjectCode = N'PRJ-1405-0001'
WHERE ProjectID = 1 AND ProjectCode = N'P001';

UPDATE dbo.Projects SET ProjectCode = N'PRJ-1405-0002'
WHERE ProjectID = 2 AND ProjectCode = N'1';
GO

