/* ==========================================================================
   پچ خودکار شماره: 006 | نام: update_database
   تاریخ: 2026-09-02 18:03:24 | شامل 37 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
CREATE PROCEDURE [dbo].[sp_InsertProjectTask]
    @ProjectID     BIGINT,
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX) = NULL,
    @ToUserID      INT,
    @SenderUserID  INT,
    @Year          SMALLINT,
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success        BIT           = 0;
    DECLARE @Message        NVARCHAR(500) = N'';
    DECLARE @NewMessageID   INT           = NULL;
    DECLARE @MessageNumber  NVARCHAR(100) = N'';
    DECLARE @MessageTypeID  INT;
    DECLARE @msgPriorityID  INT;
    DECLARE @DefaultStatusID INT;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- فقط مسئول فعال پروژه اجازه‌ی ایجاد تسک دارد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @SenderUserID AND IsResponsible = 1 AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط مسئول پروژه می‌تواند تسک ایجاد کند.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- گیرنده باید عضو فعال همین پروژه باشد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @ToUserID AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'گیرنده باید عضو فعال این پروژه باشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @ToUserID = @SenderUserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید برای خودتان تسک ایجاد کنید.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @MessageTypeID = MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1;
        IF @MessageTypeID IS NULL
        BEGIN
            SELECT 0 AS Success, N'نوع پیام «وظیفه» یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- اولویت پیش‌فرض (پایین‌ترین SortOrder فعال)
        SELECT TOP 1 @msgPriorityID = msgPriorityID
        FROM dbo.msgPriorities
        WHERE IsActive = 1
        ORDER BY SortOrder ASC;

        SELECT @DefaultStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1;
        IF @DefaultStatusID IS NULL
            SELECT @DefaultStatusID = MIN(MessageStatusID) FROM dbo.MessageStatuses;

        BEGIN TRAN;

        INSERT INTO dbo.Messages
            (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser)
        VALUES
            (NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser);

        SET @NewMessageID = SCOPE_IDENTITY();

        -- ---------- شماره‌گذاری خودکار (همانند sp_InsertMessage) ----------
        DECLARE @NumberFormat NVARCHAR(100) = N'MSG-{YEAR}-{SEQ:6}';
        SELECT @NumberFormat = NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;
        IF NULLIF(@NumberFormat, N'') IS NULL SET @NumberFormat = N'MSG-{YEAR}-{SEQ:6}';

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@MessageTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN
            UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN
            INSERT (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 1);

        DECLARE @Serial INT;
        SELECT @Serial = LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year;

        DECLARE @Num NVARCHAR(100) = @NumberFormat;
        SET @Num = REPLACE(@Num, N'{YEAR}', CAST(@Year AS NVARCHAR(4)));

        DECLARE @Padding INT = 0;
        DECLARE @SPos INT = CHARINDEX(N'{SEQ:', @NumberFormat);
        IF @SPos > 0
        BEGIN
            DECLARE @EPos INT = CHARINDEX(N'}', @NumberFormat, @SPos);
            IF @EPos > 0
            BEGIN
                DECLARE @Pad NVARCHAR(10) = SUBSTRING(@NumberFormat, @SPos + 5, @EPos - @SPos - 5);
                IF TRY_CAST(@Pad AS INT) IS NOT NULL SET @Padding = CAST(@Pad AS INT);
            END
        END

        IF @Padding > 0
            SET @Num = REPLACE(@Num, N'{SEQ:' + CAST(@Padding AS NVARCHAR(3)) + N'}',
                RIGHT(REPLICATE(N'0', @Padding) + CAST(@Serial AS NVARCHAR(20)), @Padding));
        SET @Num = REPLACE(@Num, N'{SEQ}', CAST(@Serial AS NVARCHAR(20)));

        SET @MessageNumber = @Num;
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @NewMessageID;

        -- ---------- گیرنده ----------
        INSERT INTO dbo.MessageDetails
            (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        VALUES
            (NEWID(), @NewMessageID, @SenderUserID, @ToUserID, @DefaultStatusID, GETDATE(), @CreateUser);

        -- ---------- اعلان ----------
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@ToUserID, @NewMessageID, GETDATE(), 0);

        -- ---------- اتصال به پروژه از طریق جدول واسط ----------
        INSERT INTO dbo.ProjectMessages
            (RowGuid, ProjectID, MessageID, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        SELECT
            NEWID(), @ProjectID, @NewMessageID,
            ISNULL((SELECT MAX(SortOrder) FROM dbo.ProjectMessages WHERE ProjectID = @ProjectID), 0) + 1,
            SYSDATETIME(), @CreateUser;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'تسک با موفقیت ایجاد شد. شماره: ' + @MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ایجاد تسک: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewMessageID AS NewMessageID, @MessageNumber AS MessageNumber;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
CREATE PROCEDURE [dbo].[sp_GetProjectTasks]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        pms.ProjectMessageID,
        pms.ProjectID,
        m.MessageID,
        m.MessageNumber,
        m.Subject,
        m.CreateDate,
        m.SenderUserID,
        u.FullName AS SenderName,
        LastD.ToUserID,
        ru.FullName AS RecipientName,
        LastD.MessageStatusID,
        ms.MessageStatusName
    FROM dbo.ProjectMessages pms
    JOIN dbo.Messages m ON m.MessageID = pms.MessageID
    LEFT JOIN dbo.Users u ON u.UserID = m.SenderUserID
    OUTER APPLY (
        SELECT TOP 1 MD.ToUserID, MD.MessageStatusID
        FROM dbo.MessageDetails MD
        WHERE MD.MessageID = m.MessageID
        ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
    ) LastD
    LEFT JOIN dbo.Users ru ON ru.UserID = LastD.ToUserID
    LEFT JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = LastD.MessageStatusID
    WHERE pms.ProjectID = @ProjectID
    ORDER BY pms.SortOrder DESC, m.CreateDate DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
DROP PROCEDURE dbo.sp_InsertProjectTask
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
CREATE PROCEDURE [dbo].[sp_InsertProjectTask]
    @ProjectID     BIGINT,
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX) = NULL,
    @ToUserID      INT,
    @SenderUserID  INT,
    @Year          SMALLINT,
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success        BIT           = 0;
    DECLARE @Message        NVARCHAR(500) = N'';
    DECLARE @NewMessageID   INT           = NULL;
    DECLARE @MessageNumber  NVARCHAR(100) = N'';
    DECLARE @MessageTypeID  INT;
    DECLARE @msgPriorityID  INT;
    DECLARE @DefaultStatusID INT;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- فقط مسئول فعال پروژه اجازه‌ی ایجاد تسک دارد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @SenderUserID AND IsResponsible = 1 AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط مسئول پروژه می‌تواند تسک ایجاد کند.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- گیرنده باید عضو فعال همین پروژه باشد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @ToUserID AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'گیرنده باید عضو فعال این پروژه باشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @ToUserID = @SenderUserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید برای خودتان تسک ایجاد کنید.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @MessageTypeID = MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1;
        IF @MessageTypeID IS NULL
        BEGIN
            SELECT 0 AS Success, N'نوع پیام «وظیفه» یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- اولویت پیش‌فرض (پایین‌ترین SortOrder فعال)
        SELECT TOP 1 @msgPriorityID = msgPriorityID
        FROM dbo.msgPriorities
        WHERE IsActive = 1
        ORDER BY SortOrder ASC;

        SELECT @DefaultStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1;
        IF @DefaultStatusID IS NULL
            SELECT @DefaultStatusID = MIN(MessageStatusID) FROM dbo.MessageStatuses;

        BEGIN TRAN;

        INSERT INTO dbo.Messages
            (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser)
        VALUES
            (NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser);

        SET @NewMessageID = SCOPE_IDENTITY();

        -- ---------- شماره‌گذاری خودکار (همانند sp_InsertMessage) ----------
        DECLARE @NumberFormat NVARCHAR(100) = N'MSG-{YEAR}-{SEQ:6}';
        SELECT @NumberFormat = NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;
        IF NULLIF(@NumberFormat, N'') IS NULL SET @NumberFormat = N'MSG-{YEAR}-{SEQ:6}';

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@MessageTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN
            UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN
            INSERT (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 1);

        DECLARE @Serial INT;
        SELECT @Serial = LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year;

        DECLARE @Num NVARCHAR(100) = @NumberFormat;
        SET @Num = REPLACE(@Num, N'{YEAR}', CAST(@Year AS NVARCHAR(4)));

        DECLARE @Padding INT = 0;
        DECLARE @SPos INT = CHARINDEX(N'{SEQ:', @NumberFormat);
        IF @SPos > 0
        BEGIN
            DECLARE @EPos INT = CHARINDEX(N'}', @NumberFormat, @SPos);
            IF @EPos > 0
            BEGIN
                DECLARE @Pad NVARCHAR(10) = SUBSTRING(@NumberFormat, @SPos + 5, @EPos - @SPos - 5);
                IF TRY_CAST(@Pad AS INT) IS NOT NULL SET @Padding = CAST(@Pad AS INT);
            END
        END

        IF @Padding > 0
            SET @Num = REPLACE(@Num, N'{SEQ:' + CAST(@Padding AS NVARCHAR(3)) + N'}',
                RIGHT(REPLICATE(N'0', @Padding) + CAST(@Serial AS NVARCHAR(20)), @Padding));
        SET @Num = REPLACE(@Num, N'{SEQ}', CAST(@Serial AS NVARCHAR(20)));

        SET @MessageNumber = @Num;
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @NewMessageID;

        -- ---------- گیرنده ----------
        INSERT INTO dbo.MessageDetails
            (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        VALUES
            (NEWID(), @NewMessageID, @SenderUserID, @ToUserID, @DefaultStatusID, GETDATE(), @CreateUser);

        -- ---------- اعلان ----------
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@ToUserID, @NewMessageID, GETDATE(), 0);

        -- ---------- رونوشت خودکار برای مدیر واحد (اگر خودِ گیرنده مدیر واحد نباشد) ----------
        DECLARE @IsRecipientManager BIT = 0;
        SELECT @IsRecipientManager = 1
        FROM dbo.UserPositions UP
        JOIN dbo.Positions P ON P.PositionID = UP.PositionID
        WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
          AND P.IsUnitManager = 1 AND P.IsActive = 1;

        IF @IsRecipientManager = 0
        BEGIN
            DECLARE @RecipientUnitID INT;
            SELECT TOP 1 @RecipientUnitID = UP.UnitID
            FROM dbo.UserPositions UP
            WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
            ORDER BY UP.CreateDate DESC;

            DECLARE @ManagerUserID INT = NULL;
            IF @RecipientUnitID IS NOT NULL
            BEGIN
                SELECT TOP 1 @ManagerUserID = UP2.UserID
                FROM dbo.UserPositions UP2
                JOIN dbo.Positions P2 ON P2.PositionID = UP2.PositionID
                WHERE UP2.UnitID = @RecipientUnitID AND UP2.IsActive = 1
                  AND P2.IsUnitManager = 1 AND P2.IsActive = 1
                ORDER BY UP2.CreateDate;
            END

            IF @ManagerUserID IS NOT NULL AND @ManagerUserID <> @ToUserID AND @ManagerUserID <> @SenderUserID
            BEGIN
                INSERT INTO dbo.MessageCopies (RowGuid, MessageID, UserID, Description, CreateDate, CreateUser)
                VALUES (NEWID(), @NewMessageID, @ManagerUserID, N'رونوشت خودکار (مدیر واحد عضو پروژه)', GETDATE(), @CreateUser);

                INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
                VALUES (@ManagerUserID, @NewMessageID, GETDATE(), 0);
            END
        END

        -- ---------- اتصال به پروژه از طریق جدول واسط ----------
        INSERT INTO dbo.ProjectMessages
            (RowGuid, ProjectID, MessageID, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        SELECT
            NEWID(), @ProjectID, @NewMessageID,
            ISNULL((SELECT MAX(SortOrder) FROM dbo.ProjectMessages WHERE ProjectID = @ProjectID), 0) + 1,
            SYSDATETIME(), @CreateUser;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'تسک با موفقیت ایجاد شد. شماره: ' + @MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ایجاد تسک: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewMessageID AS NewMessageID, @MessageNumber AS MessageNumber;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
DROP PROCEDURE dbo.sp_GetProjectTasks
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
CREATE PROCEDURE [dbo].[sp_GetProjectTasks]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        pms.ProjectMessageID,
        pms.ProjectID,
        m.MessageID,
        m.MessageNumber,
        m.Subject,
        m.CreateDate,
        m.SenderUserID,
        u.FullName AS SenderName,
        LastD.ToUserID,
        ru.FullName AS RecipientName,
        LastD.MessageStatusID,
        ms.MessageStatusName
    FROM dbo.ProjectMessages pms
    JOIN dbo.Messages m ON m.MessageID = pms.MessageID
    LEFT JOIN dbo.Users u ON u.UserID = m.SenderUserID
    OUTER APPLY (
        SELECT TOP 1 MD.ToUserID, MD.MessageStatusID
        FROM dbo.MessageDetails MD
        WHERE MD.MessageID = m.MessageID
        ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
    ) LastD
    LEFT JOIN dbo.Users ru ON ru.UserID = LastD.ToUserID
    LEFT JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = LastD.MessageStatusID
    WHERE pms.ProjectID = @ProjectID
    ORDER BY pms.SortOrder DESC, m.CreateDate DESC;
END
GO

-- [CREATE_TABLE] روی TABLE: ProjectPriorities
CREATE TABLE dbo.ProjectPriorities
    (
        ProjectPriorityID  INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        Code                INT NOT NULL,
        Name                NVARCHAR(100) NOT NULL,
        ColorHex            VARCHAR(7) NOT NULL DEFAULT '#667eea',
        Description         NVARCHAR(500) NULL,
        SortOrder           INT NOT NULL DEFAULT 0,
        IsActive            BIT NOT NULL DEFAULT 1,
        Date_InsertFirst    DATETIME2(3) NOT NULL DEFAULT SYSDATETIME(),
        UserID_InsertFirst  INT NULL,
        Date_LastUpdate     DATETIME2(3) NULL,
        UserID_LastUpdate   INT NULL
    )
GO

-- [ALTER_TABLE] روی TABLE: Projects
ALTER TABLE dbo.Projects ADD ProjectPriorityID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: Projects
ALTER TABLE dbo.Projects
        ADD CONSTRAINT FK_Projects_ProjectPriorities
        FOREIGN KEY (ProjectPriorityID) REFERENCES dbo.ProjectPriorities(ProjectPriorityID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectPriorities
CREATE PROCEDURE [dbo].[sp_GetProjectPriorities]
    @IsActive BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        ProjectPriorityID,
        Code,
        Name,
        ColorHex,
        Description,
        SortOrder,
        IsActive
    FROM dbo.ProjectPriorities
    WHERE (@IsActive IS NULL OR IsActive = @IsActive)
    ORDER BY SortOrder;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjects
DROP PROCEDURE dbo.sp_GetProjects
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjects
CREATE PROCEDURE [dbo].[sp_GetProjects]
    @SearchText      NVARCHAR(250) = NULL,
    @IsActive        INT = NULL,
    @ProjectStatusID INT = NULL,
    @UserID          INT = NULL
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
    @ProjectCode        NVARCHAR(50),
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
    @CreateUser         INT = NULL
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
             ProjectStatusID, ProjectPriorityID, ProgressPercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectCode, @ProjectTitle, @Description, @StartDate, @PlannedEndDate, @ActualEndDate,
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
    @ModifyUser         INT = NULL
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

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
DROP PROCEDURE dbo.sp_InsertProjectTask
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
CREATE PROCEDURE [dbo].[sp_InsertProjectTask]
    @ProjectID     BIGINT,
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX) = NULL,
    @ToUserID      INT,
    @msgPriorityID INT = NULL,
    @SenderUserID  INT,
    @Year          SMALLINT,
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success        BIT           = 0;
    DECLARE @Message        NVARCHAR(500) = N'';
    DECLARE @NewMessageID   INT           = NULL;
    DECLARE @MessageNumber  NVARCHAR(100) = N'';
    DECLARE @MessageTypeID  INT;
    DECLARE @DefaultStatusID INT;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- فقط مسئول فعال پروژه اجازه‌ی ایجاد وظیفه دارد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @SenderUserID AND IsResponsible = 1 AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط مسئول پروژه می‌تواند وظیفه ایجاد کند.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- گیرنده باید عضو فعال همین پروژه باشد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @ToUserID AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'گیرنده باید عضو فعال این پروژه باشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @ToUserID = @SenderUserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید برای خودتان وظیفه ایجاد کنید.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @MessageTypeID = MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1;
        IF @MessageTypeID IS NULL
        BEGIN
            SELECT 0 AS Success, N'نوع پیام «وظیفه» یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- اعتبارسنجی اولویت؛ اگر ارسال نشده یا نامعتبر بود، پایین‌ترین اولویت فعال پیش‌فرض می‌شود
        IF @msgPriorityID IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM dbo.msgPriorities WHERE msgPriorityID = @msgPriorityID AND IsActive = 1
        )
        BEGIN
            SET @msgPriorityID = NULL;
        END

        IF @msgPriorityID IS NULL
        BEGIN
            SELECT TOP 1 @msgPriorityID = msgPriorityID
            FROM dbo.msgPriorities
            WHERE IsActive = 1
            ORDER BY SortOrder ASC;
        END

        SELECT @DefaultStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1;
        IF @DefaultStatusID IS NULL
            SELECT @DefaultStatusID = MIN(MessageStatusID) FROM dbo.MessageStatuses;

        BEGIN TRAN;

        INSERT INTO dbo.Messages
            (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser)
        VALUES
            (NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser);

        SET @NewMessageID = SCOPE_IDENTITY();

        -- ---------- شماره‌گذاری خودکار (همانند sp_InsertMessage) ----------
        DECLARE @NumberFormat NVARCHAR(100) = N'MSG-{YEAR}-{SEQ:6}';
        SELECT @NumberFormat = NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;
        IF NULLIF(@NumberFormat, N'') IS NULL SET @NumberFormat = N'MSG-{YEAR}-{SEQ:6}';

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@MessageTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN
            UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN
            INSERT (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 1);

        DECLARE @Serial INT;
        SELECT @Serial = LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year;

        DECLARE @Num NVARCHAR(100) = @NumberFormat;
        SET @Num = REPLACE(@Num, N'{YEAR}', CAST(@Year AS NVARCHAR(4)));

        DECLARE @Padding INT = 0;
        DECLARE @SPos INT = CHARINDEX(N'{SEQ:', @NumberFormat);
        IF @SPos > 0
        BEGIN
            DECLARE @EPos INT = CHARINDEX(N'}', @NumberFormat, @SPos);
            IF @EPos > 0
            BEGIN
                DECLARE @Pad NVARCHAR(10) = SUBSTRING(@NumberFormat, @SPos + 5, @EPos - @SPos - 5);
                IF TRY_CAST(@Pad AS INT) IS NOT NULL SET @Padding = CAST(@Pad AS INT);
            END
        END

        IF @Padding > 0
            SET @Num = REPLACE(@Num, N'{SEQ:' + CAST(@Padding AS NVARCHAR(3)) + N'}',
                RIGHT(REPLICATE(N'0', @Padding) + CAST(@Serial AS NVARCHAR(20)), @Padding));
        SET @Num = REPLACE(@Num, N'{SEQ}', CAST(@Serial AS NVARCHAR(20)));

        SET @MessageNumber = @Num;
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @NewMessageID;

        -- ---------- گیرنده ----------
        INSERT INTO dbo.MessageDetails
            (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        VALUES
            (NEWID(), @NewMessageID, @SenderUserID, @ToUserID, @DefaultStatusID, GETDATE(), @CreateUser);

        -- ---------- اعلان ----------
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@ToUserID, @NewMessageID, GETDATE(), 0);

        -- ---------- رونوشت خودکار برای مدیر واحد (اگر خودِ گیرنده مدیر واحد نباشد) ----------
        DECLARE @IsRecipientManager BIT = 0;
        SELECT @IsRecipientManager = 1
        FROM dbo.UserPositions UP
        JOIN dbo.Positions P ON P.PositionID = UP.PositionID
        WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
          AND P.IsUnitManager = 1 AND P.IsActive = 1;

        IF @IsRecipientManager = 0
        BEGIN
            DECLARE @RecipientUnitID INT;
            SELECT TOP 1 @RecipientUnitID = UP.UnitID
            FROM dbo.UserPositions UP
            WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
            ORDER BY UP.CreateDate DESC;

            DECLARE @ManagerUserID INT = NULL;
            IF @RecipientUnitID IS NOT NULL
            BEGIN
                SELECT TOP 1 @ManagerUserID = UP2.UserID
                FROM dbo.UserPositions UP2
                JOIN dbo.Positions P2 ON P2.PositionID = UP2.PositionID
                WHERE UP2.UnitID = @RecipientUnitID AND UP2.IsActive = 1
                  AND P2.IsUnitManager = 1 AND P2.IsActive = 1
                ORDER BY UP2.CreateDate;
            END

            IF @ManagerUserID IS NOT NULL AND @ManagerUserID <> @ToUserID AND @ManagerUserID <> @SenderUserID
            BEGIN
                INSERT INTO dbo.MessageCopies (RowGuid, MessageID, UserID, Description, CreateDate, CreateUser)
                VALUES (NEWID(), @NewMessageID, @ManagerUserID, N'رونوشت خودکار (مدیر واحد عضو پروژه)', GETDATE(), @CreateUser);

                INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
                VALUES (@ManagerUserID, @NewMessageID, GETDATE(), 0);
            END
        END

        -- ---------- اتصال به پروژه از طریق جدول واسط ----------
        INSERT INTO dbo.ProjectMessages
            (RowGuid, ProjectID, MessageID, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        SELECT
            NEWID(), @ProjectID, @NewMessageID,
            ISNULL((SELECT MAX(SortOrder) FROM dbo.ProjectMessages WHERE ProjectID = @ProjectID), 0) + 1,
            SYSDATETIME(), @CreateUser;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'وظیفه با موفقیت ایجاد شد. شماره: ' + @MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ایجاد وظیفه: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewMessageID AS NewMessageID, @MessageNumber AS MessageNumber;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
DROP PROCEDURE dbo.sp_GetProjectTasks
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
CREATE PROCEDURE [dbo].[sp_GetProjectTasks]
    @ProjectID BIGINT,
    @UserID    INT = NULL   -- اگر پر شود، فقط وظیفه‌هایی که این کاربر فرستنده/گیرنده/رونوشت‌گیرنده آن است برمی‌گردد
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        pms.ProjectMessageID,
        pms.ProjectID,
        m.MessageID,
        m.MessageNumber,
        m.Subject,
        m.CreateDate,
        m.SenderUserID,
        u.FullName AS SenderName,
        LastD.ToUserID,
        ru.FullName AS RecipientName,
        LastD.MessageStatusID,
        ms.MessageStatusName,
        mp.msgPriorityID AS PriorityID,
        mp.Name AS PriorityName
    FROM dbo.ProjectMessages pms
    JOIN dbo.Messages m ON m.MessageID = pms.MessageID
    LEFT JOIN dbo.Users u ON u.UserID = m.SenderUserID
    LEFT JOIN dbo.msgPriorities mp ON mp.msgPriorityID = m.msgPriorityID
    OUTER APPLY (
        SELECT TOP 1 MD.ToUserID, MD.MessageStatusID
        FROM dbo.MessageDetails MD
        WHERE MD.MessageID = m.MessageID
        ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
    ) LastD
    LEFT JOIN dbo.Users ru ON ru.UserID = LastD.ToUserID
    LEFT JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = LastD.MessageStatusID
    WHERE pms.ProjectID = @ProjectID
      AND (
            @UserID IS NULL
            OR m.SenderUserID = @UserID           -- مسئول پروژه (فرستنده وظیفه)
            OR LastD.ToUserID = @UserID            -- گیرنده وظیفه
            OR EXISTS (                            -- رونوشت‌گیرنده (مثلاً مدیر واحد گیرنده، اگر خودش هم عضو پروژه باشد)
                SELECT 1 FROM dbo.MessageCopies MC
                WHERE MC.MessageID = m.MessageID AND MC.UserID = @UserID
            )
          )
    ORDER BY pms.SortOrder DESC, m.CreateDate DESC;
END
GO

-- [CREATE_TABLE] روی TABLE: ProjectComments
CREATE TABLE dbo.ProjectComments
    (
        ProjectCommentID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        RowGuid          UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        ProjectID        BIGINT NOT NULL,
        UserID           INT NOT NULL,
        Comment          NVARCHAR(MAX) NOT NULL,
        CreateDate       DATETIME NOT NULL DEFAULT GETDATE()
    )
GO

-- [CREATE_TABLE] روی TABLE: ProjectAttachments
CREATE TABLE dbo.ProjectAttachments
    (
        ProjectAttachmentID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        RowGuid             UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        ProjectID           BIGINT NOT NULL,
        FileName            NVARCHAR(255) NOT NULL,
        FileExtension       NVARCHAR(20) NULL,
        FileSize            BIGINT NOT NULL,
        FilePath            NVARCHAR(1000) NOT NULL,
        CreateDate          DATETIME NOT NULL DEFAULT GETDATE(),
        CreateUser          INT NULL
    )
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectComments
CREATE PROCEDURE [dbo].[sp_GetProjectComments]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        PC.ProjectCommentID,
        PC.ProjectID,
        PC.UserID,
        U.FullName,
        PC.Comment,
        PC.CreateDate
    FROM dbo.ProjectComments PC
    LEFT JOIN dbo.Users U ON U.UserID = PC.UserID
    WHERE PC.ProjectID = @ProjectID
    ORDER BY PC.CreateDate ASC, PC.ProjectCommentID ASC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectComment
CREATE PROCEDURE [dbo].[sp_InsertProjectComment]
    @ProjectID INT,
    @UserID    INT,
    @Comment   NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @NewCommentID INT = NULL;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message, NULL AS NewCommentID;
            RETURN;
        END

        -- فقط عضو فعال پروژه اجازه‌ی ثبت کامنت دارد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @UserID AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط اعضای پروژه می‌توانند نظر ثبت کنند.' AS Message, NULL AS NewCommentID;
            RETURN;
        END

        INSERT INTO dbo.ProjectComments (RowGuid, ProjectID, UserID, Comment, CreateDate)
        VALUES (NEWID(), @ProjectID, @UserID, @Comment, GETDATE());

        SET @NewCommentID = SCOPE_IDENTITY();
        SET @Success = 1;
        SET @Message = N'نظر با موفقیت ثبت شد.';
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در ثبت نظر: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewCommentID AS NewCommentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectAttachmentsList
CREATE PROCEDURE [dbo].[sp_GetProjectAttachmentsList]
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        PA.ProjectAttachmentID,
        PA.FileName,
        PA.FileExtension,
        PA.FileSize,
        PA.FilePath,
        PA.CreateDate,
        PA.CreateUser,
        U.FullName AS CreateUserName
    FROM dbo.ProjectAttachments PA
    LEFT JOIN dbo.Users U ON U.UserID = PA.CreateUser
    WHERE PA.ProjectID = @ProjectID
    ORDER BY PA.ProjectAttachmentID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectAttachment
CREATE PROCEDURE [dbo].[sp_InsertProjectAttachment]
    @ProjectID     BIGINT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20) = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @CreateUser AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط اعضای پروژه می‌توانند ضمیمه اضافه کنند.' AS Message;
            RETURN;
        END

        INSERT INTO dbo.ProjectAttachments
            (RowGuid, ProjectID, FileName, FileExtension, FileSize, FilePath, CreateDate, CreateUser)
        VALUES
            (NEWID(), @ProjectID, @FileName, @FileExtension, @FileSize, @FilePath, GETDATE(), @CreateUser);

        SET @Success = 1;
        SET @Message = N'ضمیمه با موفقیت ثبت شد.';
    END TRY
    BEGIN CATCH
        SET @Message = N'خطا در ثبت ضمیمه: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
DROP PROCEDURE dbo.sp_InsertProjectTask
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
CREATE PROCEDURE [dbo].[sp_InsertProjectTask]
    @ProjectID     BIGINT,
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX) = NULL,
    @ToUserID      INT,
    @msgPriorityID INT = NULL,
    @SenderUserID  INT,
    @Year          SMALLINT,
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success        BIT           = 0;
    DECLARE @Message        NVARCHAR(500) = N'';
    DECLARE @NewMessageID   INT           = NULL;
    DECLARE @MessageNumber  NVARCHAR(100) = N'';
    DECLARE @MessageTypeID  INT;
    DECLARE @DefaultStatusID INT;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- فقط مسئول فعال پروژه اجازه‌ی ایجاد وظیفه دارد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @SenderUserID AND IsResponsible = 1 AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'فقط مسئول پروژه می‌تواند وظیفه ایجاد کند.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- گیرنده باید عضو فعال همین پروژه باشد
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectMembers
            WHERE ProjectID = @ProjectID AND UserID = @ToUserID AND IsActive = 1
        )
        BEGIN
            SELECT 0 AS Success, N'گیرنده باید عضو فعال این پروژه باشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @ToUserID = @SenderUserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید برای خودتان وظیفه ایجاد کنید.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @MessageTypeID = MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1;
        IF @MessageTypeID IS NULL
        BEGIN
            SELECT 0 AS Success, N'نوع پیام «وظیفه» یافت نشد.' AS Message, NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- اعتبارسنجی اولویت؛ اگر ارسال نشده یا نامعتبر بود، پایین‌ترین اولویت فعال پیش‌فرض می‌شود
        IF @msgPriorityID IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM dbo.msgPriorities WHERE msgPriorityID = @msgPriorityID AND IsActive = 1
        )
        BEGIN
            SET @msgPriorityID = NULL;
        END

        IF @msgPriorityID IS NULL
        BEGIN
            SELECT TOP 1 @msgPriorityID = msgPriorityID
            FROM dbo.msgPriorities
            WHERE IsActive = 1
            ORDER BY SortOrder ASC;
        END

        SELECT @DefaultStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1;
        IF @DefaultStatusID IS NULL
            SELECT @DefaultStatusID = MIN(MessageStatusID) FROM dbo.MessageStatuses;

        BEGIN TRAN;

        INSERT INTO dbo.Messages
            (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser)
        VALUES
            (NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser);

        SET @NewMessageID = SCOPE_IDENTITY();

        -- ---------- شماره‌گذاری خودکار (همانند sp_InsertMessage) ----------
        DECLARE @NumberFormat NVARCHAR(100) = N'MSG-{YEAR}-{SEQ:6}';
        SELECT @NumberFormat = NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;
        IF NULLIF(@NumberFormat, N'') IS NULL SET @NumberFormat = N'MSG-{YEAR}-{SEQ:6}';

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@MessageTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN
            UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN
            INSERT (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 1);

        DECLARE @Serial INT;
        SELECT @Serial = LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @MessageTypeID AND Year = @Year;

        DECLARE @Num NVARCHAR(100) = @NumberFormat;
        SET @Num = REPLACE(@Num, N'{YEAR}', CAST(@Year AS NVARCHAR(4)));

        DECLARE @Padding INT = 0;
        DECLARE @SPos INT = CHARINDEX(N'{SEQ:', @NumberFormat);
        IF @SPos > 0
        BEGIN
            DECLARE @EPos INT = CHARINDEX(N'}', @NumberFormat, @SPos);
            IF @EPos > 0
            BEGIN
                DECLARE @Pad NVARCHAR(10) = SUBSTRING(@NumberFormat, @SPos + 5, @EPos - @SPos - 5);
                IF TRY_CAST(@Pad AS INT) IS NOT NULL SET @Padding = CAST(@Pad AS INT);
            END
        END

        IF @Padding > 0
            SET @Num = REPLACE(@Num, N'{SEQ:' + CAST(@Padding AS NVARCHAR(3)) + N'}',
                RIGHT(REPLICATE(N'0', @Padding) + CAST(@Serial AS NVARCHAR(20)), @Padding));
        SET @Num = REPLACE(@Num, N'{SEQ}', CAST(@Serial AS NVARCHAR(20)));

        SET @MessageNumber = @Num;
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @NewMessageID;

        -- ---------- گیرنده ----------
        INSERT INTO dbo.MessageDetails
            (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        VALUES
            (NEWID(), @NewMessageID, @SenderUserID, @ToUserID, @DefaultStatusID, GETDATE(), @CreateUser);

        -- ---------- اعلان ----------
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@ToUserID, @NewMessageID, GETDATE(), 0);

        -- ---------- رونوشت خودکار برای مدیر واحد (اگر خودِ گیرنده مدیر واحد نباشد) ----------
        DECLARE @IsRecipientManager BIT = 0;
        SELECT @IsRecipientManager = 1
        FROM dbo.UserPositions UP
        JOIN dbo.Positions P ON P.PositionID = UP.PositionID
        WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
          AND P.IsUnitManager = 1 AND P.IsActive = 1;

        IF @IsRecipientManager = 0
        BEGIN
            DECLARE @RecipientUnitID INT;
            SELECT TOP 1 @RecipientUnitID = UP.UnitID
            FROM dbo.UserPositions UP
            WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
            ORDER BY UP.CreateDate DESC;

            DECLARE @ManagerUserID INT = NULL;
            IF @RecipientUnitID IS NOT NULL
            BEGIN
                SELECT TOP 1 @ManagerUserID = UP2.UserID
                FROM dbo.UserPositions UP2
                JOIN dbo.Positions P2 ON P2.PositionID = UP2.PositionID
                WHERE UP2.UnitID = @RecipientUnitID AND UP2.IsActive = 1
                  AND P2.IsUnitManager = 1 AND P2.IsActive = 1
                ORDER BY UP2.CreateDate;
            END

            IF @ManagerUserID IS NOT NULL AND @ManagerUserID <> @ToUserID AND @ManagerUserID <> @SenderUserID
            BEGIN
                INSERT INTO dbo.MessageCopies (RowGuid, MessageID, UserID, Description, CreateDate, CreateUser)
                VALUES (NEWID(), @NewMessageID, @ManagerUserID, N'رونوشت خودکار (مدیر واحد عضو پروژه)', GETDATE(), @CreateUser);

                INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
                VALUES (@ManagerUserID, @NewMessageID, GETDATE(), 0);
            END
        END

        -- ---------- اتصال به پروژه از طریق جدول واسط ----------
        INSERT INTO dbo.ProjectMessages
            (RowGuid, ProjectID, MessageID, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        SELECT
            NEWID(), @ProjectID, @NewMessageID,
            ISNULL((SELECT MAX(SortOrder) FROM dbo.ProjectMessages WHERE ProjectID = @ProjectID), 0) + 1,
            SYSDATETIME(), @CreateUser;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'وظیفه با موفقیت ایجاد شد. شماره: ' + @MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ایجاد وظیفه: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewMessageID AS NewMessageID, @MessageNumber AS MessageNumber;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
DROP PROCEDURE dbo.sp_GetProjectTasks
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
CREATE PROCEDURE [dbo].[sp_GetProjectTasks]
    @ProjectID BIGINT,
    @UserID    INT = NULL   -- اگر پر شود، فقط وظیفه‌هایی که این کاربر فرستنده/گیرنده/رونوشت‌گیرنده آن است برمی‌گردد
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        pms.ProjectMessageID,
        pms.ProjectID,
        m.MessageID,
        m.MessageNumber,
        m.Subject,
        m.MessageText,
        m.CreateDate,
        m.SenderUserID,
        u.FullName AS SenderName,
        LastD.ToUserID,
        ru.FullName AS RecipientName,
        LastD.MessageStatusID,
        ms.MessageStatusName,
        mp.msgPriorityID AS PriorityID,
        mp.Name AS PriorityName
    FROM dbo.ProjectMessages pms
    JOIN dbo.Messages m ON m.MessageID = pms.MessageID
    LEFT JOIN dbo.Users u ON u.UserID = m.SenderUserID
    LEFT JOIN dbo.msgPriorities mp ON mp.msgPriorityID = m.msgPriorityID
    OUTER APPLY (
        SELECT TOP 1 MD.ToUserID, MD.MessageStatusID
        FROM dbo.MessageDetails MD
        WHERE MD.MessageID = m.MessageID
        ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
    ) LastD
    LEFT JOIN dbo.Users ru ON ru.UserID = LastD.ToUserID
    LEFT JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = LastD.MessageStatusID
    WHERE pms.ProjectID = @ProjectID
      AND (
            @UserID IS NULL
            OR m.SenderUserID = @UserID           -- مسئول پروژه (فرستنده وظیفه)
            OR LastD.ToUserID = @UserID            -- گیرنده وظیفه
            OR EXISTS (                            -- رونوشت‌گیرنده (مثلاً مدیر واحد گیرنده، اگر خودش هم عضو پروژه باشد)
                SELECT 1 FROM dbo.MessageCopies MC
                WHERE MC.MessageID = m.MessageID AND MC.UserID = @UserID
            )
          )
    ORDER BY pms.SortOrder DESC, m.CreateDate DESC;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetUsers
DROP PROCEDURE dbo.sp_GetUsers
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUsers
CREATE PROCEDURE [dbo].[sp_GetUsers]
    @SearchText NVARCHAR(100) = NULL,
    @IsActive   INT = 1
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        u.UserID,
        u.UserCode,
        u.UserName,
        u.FirstName,
        u.LastName,
        u.FullName,
        u.Email,
        u.Mobile,
        u.LastLoginDate,
        u.LastLoginIP,
        u.FailedLoginCount,
        u.IsLocked,
        u.IsActive,
        u.Description,
        u.CreateDate,
        u.ModifyDate,
        ISNULL((
            SELECT COUNT(*)
            FROM dbo.UserRoles ur
            WHERE ur.UserID = u.UserID AND ur.IsActive = 1
        ), 0) AS RolesCount
    FROM dbo.Users u
    WHERE (@IsActive IS NULL OR u.IsActive = @IsActive)
      AND (@SearchText IS NULL OR @SearchText = ''
           OR u.UserName  LIKE N'%' + @SearchText + N'%'
           OR u.FirstName LIKE N'%' + @SearchText + N'%'
           OR u.LastName  LIKE N'%' + @SearchText + N'%'
           OR u.FullName  LIKE N'%' + @SearchText + N'%'
           OR u.Email     LIKE N'%' + @SearchText + N'%'
           OR u.Mobile    LIKE N'%' + @SearchText + N'%'
           OR u.UserCode  LIKE N'%' + @SearchText + N'%')
    ORDER BY u.FullName;
END
GO

-- [ALTER_TABLE] روی TABLE: sysCompanies
ALTER TABLE dbo.sysCompanies ADD SiteTitle NVARCHAR(100) NULL
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetCompany
DROP PROCEDURE dbo.sp_GetCompany
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCompany
CREATE PROCEDURE dbo.sp_GetCompany
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1
        sysCompanyID,
        Code,
        Name,
        Description,
        SiteTitle,
        LogoMimeType,
        FaviconMimeType,
        IsActive,
        Date_InsertFirst,
        UserID_InsertFirst
    FROM dbo.sysCompanies
    WHERE IsActive = 1
    ORDER BY sysCompanyID;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_SaveCompany
DROP PROCEDURE dbo.sp_SaveCompany
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveCompany
CREATE PROCEDURE dbo.sp_SaveCompany
    @Code            NVARCHAR(50),
    @Name            NVARCHAR(200),
    @Description     NVARCHAR(1000) = NULL,
    @SiteTitle       NVARCHAR(100)  = NULL,
    @LogoBase64      NVARCHAR(MAX)  = NULL,
    @LogoMimeType    VARCHAR(100)   = NULL,
    @FaviconBase64   NVARCHAR(MAX)  = NULL,
    @FaviconMimeType VARCHAR(100)   = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success      BIT           = 0;
    DECLARE @Message      NVARCHAR(500) = N'';

    DECLARE @LogoBin      VARBINARY(MAX) = NULL;
    DECLARE @FaviconBin   VARBINARY(MAX) = NULL;

    BEGIN TRY
        IF NULLIF(@Name, N'') IS NULL
        BEGIN
            SELECT 0 AS Success, N'نام شرکت الزامی است.' AS Message;
            RETURN;
        END

        IF NULLIF(@Code, N'') IS NULL
        BEGIN
            SELECT 0 AS Success, N'کد شرکت الزامی است.' AS Message;
            RETURN;
        END

        IF NULLIF(@LogoBase64, N'') IS NOT NULL
        BEGIN
            SET @LogoBin = CAST(N'' AS XML).value(
                'xs:base64Binary(sql:variable("@LogoBase64"))',
                'VARBINARY(MAX)'
            );
        END

        IF NULLIF(@FaviconBase64, N'') IS NOT NULL
        BEGIN
            SET @FaviconBin = CAST(N'' AS XML).value(
                'xs:base64Binary(sql:variable("@FaviconBase64"))',
                'VARBINARY(MAX)'
            );
        END

        BEGIN TRAN;

        DECLARE @CompanyID INT =
            (SELECT TOP 1 sysCompanyID FROM dbo.sysCompanies
             WHERE IsActive = 1 ORDER BY sysCompanyID);

        IF @CompanyID IS NULL
        BEGIN
            INSERT INTO dbo.sysCompanies
            (
                Code, Name, Description, SiteTitle,
                Logo, LogoMimeType,
                Favicon, FaviconMimeType,
                IsActive, Date_InsertFirst, UserID_InsertFirst
            )
            VALUES
            (
                @Code, @Name, @Description, @SiteTitle,
                @LogoBin, @LogoMimeType,
                @FaviconBin, @FaviconMimeType,
                1, SYSDATETIME(), @UserID
            );

            SET @Message = N'اطلاعات شرکت با موفقیت ثبت شد.';
        END
        ELSE
        BEGIN
            UPDATE dbo.sysCompanies
            SET Code        = @Code,
                Name        = @Name,
                Description = @Description,
                SiteTitle   = @SiteTitle,
                Logo        = CASE WHEN @LogoBin IS NOT NULL    THEN @LogoBin    ELSE Logo END,
                LogoMimeType = CASE WHEN @LogoMimeType IS NOT NULL THEN @LogoMimeType ELSE LogoMimeType END,
                Favicon     = CASE WHEN @FaviconBin IS NOT NULL THEN @FaviconBin ELSE Favicon END,
                FaviconMimeType = CASE WHEN @FaviconMimeType IS NOT NULL THEN @FaviconMimeType ELSE FaviconMimeType END
            WHERE sysCompanyID = @CompanyID;

            SET @Message = N'اطلاعات شرکت با موفقیت به‌روزرسانی شد.';
        END

        COMMIT;

        SET @Success = 1;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ذخیره اطلاعات شرکت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

