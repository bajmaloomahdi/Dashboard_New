/* ==========================================================================
   پچ خودکار شماره: 010 | نام: task_due_date
   تاریخ: 2026-09-07 18:35:40 | شامل 5 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: Messages
ALTER TABLE dbo.Messages ADD DueDate DATE NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertMessage
ALTER PROCEDURE [dbo].[sp_InsertMessage]
    @MessageTypeID    INT,                  -- از جدول MessageTypes
    @msgPriorityID    INT,                  -- << جدید: از جدول msgPriorities
    @Subject          NVARCHAR(500),
    @MessageText      NVARCHAR(MAX) = NULL,
    @RecipientType    TINYINT,              -- 1=کاربر/های مشخص  2=همه کاربران  3=همه مدیرها
    @RecipientUserIDs NVARCHAR(MAX) = NULL,
    @CopyUserIDs      NVARCHAR(MAX) = NULL,
    @CopyDescription  NVARCHAR(1000) = NULL,
    @SenderUserID     INT,
    @Year             SMALLINT,
    @CreateUser       INT,
    @DueDate          DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success          BIT           = 0;
    DECLARE @Message          NVARCHAR(500) = N'';
    DECLARE @NewMessageID     INT           = NULL;
    DECLARE @MessageNumber    NVARCHAR(100) = N'';
    DECLARE @IsTask           BIT           = 0;
    DECLARE @DefaultStatusID  INT;

    BEGIN TRY
        -- ---------- اعتبارسنجی ----------
        IF NULLIF(@Subject, N'') IS NULL
        BEGIN
            SELECT 0 AS Success, N'موضوع پیام الزامی است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageTypes
                       WHERE MessageTypeID = @MessageTypeID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'نوع پیام نامعتبر است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        -- ========== اعتبارسنجی اولویت (جدید) ==========
        IF @msgPriorityID IS NULL
        BEGIN
            SELECT 0 AS Success, N'انتخاب اولویت پیام الزامی است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.msgPriorities
                       WHERE msgPriorityID = @msgPriorityID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'اولویت انتخاب‌شده نامعتبر یا غیرفعال است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @Year IS NULL OR @Year < 1300 OR @Year > 1600
        BEGIN
            SELECT 0 AS Success, N'سال نامعتبر است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @IsTask = CASE WHEN MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END
        FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;

        IF @RecipientType NOT IN (1, 2, 3)
        BEGIN
            SELECT 0 AS Success, N'نوع گیرنده نامعتبر است.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @IsTask = 1 AND (@RecipientType <> 1 OR NULLIF(@RecipientUserIDs, N'') IS NULL)
        BEGIN
            SELECT 0 AS Success, N'برای وظیفه باید یک گیرنده مشخص انتخاب شود.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        IF @RecipientType = 1 AND NULLIF(@RecipientUserIDs, N'') IS NULL
        BEGIN
            SELECT 0 AS Success, N'حداقل یک گیرنده انتخاب کنید.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        SELECT @DefaultStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1;

        IF @DefaultStatusID IS NULL
            SELECT @DefaultStatusID = MIN(MessageStatusID) FROM dbo.MessageStatuses;

        BEGIN TRAN;

        -- ---------- ثبت پیام ----------
        INSERT INTO dbo.Messages
        (
            RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText,
            CreateDate, CreateUser, DueDate
        )
        VALUES
        (
            NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText,
            GETDATE(), @CreateUser, @DueDate
        );

        SET @NewMessageID = SCOPE_IDENTITY();

        -- ========== شماره‌گذاری خودکار (شروع از 000001) ==========
        DECLARE @NumberFormat NVARCHAR(100) = N'MSG-{YEAR}-{SEQ:6}';
        SELECT @NumberFormat = NumberFormat
        FROM dbo.MessageTypes WHERE MessageTypeID = @MessageTypeID;
        IF NULLIF(@NumberFormat, N'') IS NULL
            SET @NumberFormat = N'MSG-{YEAR}-{SEQ:6}';

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters
                       WHERE MessageTypeID = @MessageTypeID AND Year = @Year)
        BEGIN
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber)
            VALUES (@MessageTypeID, @Year, 0);
        END

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@MessageTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN
            UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN
            INSERT (MessageTypeID, Year, LastNumber) VALUES (@MessageTypeID, @Year, 1);

        DECLARE @Serial INT;
        SELECT @Serial = LastNumber
        FROM dbo.MessageNumberCounters
        WHERE MessageTypeID = @MessageTypeID AND Year = @Year;

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

        -- ---------- گیرندگان ----------
        CREATE TABLE #Recipients (UserID INT NOT NULL PRIMARY KEY);

        IF @RecipientType = 1
        BEGIN
            INSERT INTO #Recipients (UserID)
            SELECT DISTINCT TRY_CAST(value AS INT)
            FROM STRING_SPLIT(@RecipientUserIDs, N',')
            WHERE TRY_CAST(value AS INT) IS NOT NULL
              AND EXISTS (SELECT 1 FROM dbo.Users
                          WHERE UserID = TRY_CAST(value AS INT) AND IsActive = 1);

            IF @IsTask = 1
            BEGIN
                IF (SELECT COUNT(*) FROM #Recipients) <> 1
                BEGIN
                    ROLLBACK;
                    SELECT 0 AS Success, N'برای وظیفه باید دقیقاً یک گیرنده (مدیر واحد) انتخاب شود.' AS Message,
                           NULL AS NewMessageID, N'' AS MessageNumber;
                    RETURN;
                END

                DECLARE @ToUserID INT = (SELECT TOP 1 UserID FROM #Recipients);

                IF NOT EXISTS (
                    SELECT 1 FROM dbo.UserPositions UP
                    JOIN dbo.Positions P ON P.PositionID = UP.PositionID
                    WHERE UP.UserID = @ToUserID AND UP.IsActive = 1
                      AND P.IsUnitManager = 1 AND P.IsActive = 1
                )
                BEGIN
                    ROLLBACK;
                    SELECT 0 AS Success, N'گیرنده باید مدیر واحد باشد.' AS Message,
                           NULL AS NewMessageID, N'' AS MessageNumber;
                    RETURN;
                END

                DECLARE @IsSenderManager BIT = 0;
                SELECT @IsSenderManager = 1
                FROM dbo.UserPositions UP
                JOIN dbo.Positions P ON P.PositionID = UP.PositionID
                WHERE UP.UserID = @SenderUserID AND UP.IsActive = 1
                  AND P.IsUnitManager = 1 AND P.IsActive = 1;

                IF @IsSenderManager = 0
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM dbo.UserPositions UP_Target
                        JOIN dbo.Positions P_Target ON P_Target.PositionID = UP_Target.PositionID
                        JOIN dbo.UserPositions UP_Sender ON UP_Sender.UnitID = UP_Target.UnitID
                        WHERE UP_Target.UserID = @ToUserID AND UP_Target.IsActive = 1
                          AND P_Target.IsUnitManager = 1 AND P_Target.IsActive = 1
                          AND UP_Sender.UserID = @SenderUserID AND UP_Sender.IsActive = 1
                    )
                    BEGIN
                        ROLLBACK;
                        SELECT 0 AS Success, N'کاربر عادی فقط می‌تواند به مدیر واحد خود ارسال کند.' AS Message,
                               NULL AS NewMessageID, N'' AS MessageNumber;
                        RETURN;
                    END
                END
            END
        END
        ELSE IF @RecipientType = 2
        BEGIN
            IF @IsTask = 1
            BEGIN
                ROLLBACK;
                SELECT 0 AS Success, N'برای وظیفه نمی‌توان به همه ارسال کرد.' AS Message,
                       NULL AS NewMessageID, N'' AS MessageNumber;
                RETURN;
            END
            INSERT INTO #Recipients (UserID)
            SELECT UserID FROM dbo.Users WHERE IsActive = 1;
        END
        ELSE IF @RecipientType = 3
        BEGIN
            IF @IsTask = 1
            BEGIN
                ROLLBACK;
                SELECT 0 AS Success, N'برای وظیفه نمی‌توان به همه مدیران ارسال کرد.' AS Message,
                       NULL AS NewMessageID, N'' AS MessageNumber;
                RETURN;
            END
            INSERT INTO #Recipients (UserID)
            SELECT DISTINCT UP.UserID
            FROM dbo.UserPositions UP
            JOIN dbo.Positions P ON P.PositionID = UP.PositionID
            WHERE UP.IsActive = 1 AND P.IsUnitManager = 1 AND P.IsActive = 1
              AND EXISTS (SELECT 1 FROM dbo.Users U WHERE U.UserID = UP.UserID AND U.IsActive = 1);
        END

        DELETE FROM #Recipients WHERE UserID = @SenderUserID;

        IF NOT EXISTS (SELECT 1 FROM #Recipients)
        BEGIN
            ROLLBACK;
            SELECT 0 AS Success, N'گیرنده‌ای برای ارسال یافت نشد.' AS Message,
                   NULL AS NewMessageID, N'' AS MessageNumber;
            RETURN;
        END

        INSERT INTO dbo.MessageDetails
        (
            RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID,
            CreateDate, CreateUser
        )
        SELECT
            NEWID(), @NewMessageID, @SenderUserID, R.UserID, @DefaultStatusID,
            GETDATE(), @CreateUser
        FROM #Recipients R;

        -- ========== اعلان برای گیرنده‌ها ==========
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        SELECT R.UserID, @NewMessageID, GETDATE(), 0
        FROM #Recipients R
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.UserNotifications UN
            WHERE UN.UserID = R.UserID AND UN.MessageID = @NewMessageID AND UN.IsRead = 0
        );

        -- ---------- رونوشت‌ها (با توضیحات) ----------
        IF NULLIF(@CopyUserIDs, N'') IS NOT NULL
        BEGIN
            INSERT INTO dbo.MessageCopies
            (
                RowGuid, MessageID, UserID, Description, CreateDate, CreateUser
            )
            SELECT DISTINCT
                NEWID(), @NewMessageID, TRY_CAST(value AS INT), @CopyDescription, GETDATE(), @CreateUser
            FROM STRING_SPLIT(@CopyUserIDs, N',')
            WHERE TRY_CAST(value AS INT) IS NOT NULL
              AND TRY_CAST(value AS INT) <> @SenderUserID
              AND EXISTS (SELECT 1 FROM dbo.Users
                          WHERE UserID = TRY_CAST(value AS INT) AND IsActive = 1);

            -- ========== اعلان برای رونوشت‌ها ==========
            INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
            SELECT DISTINCT TRY_CAST(value AS INT), @NewMessageID, GETDATE(), 0
            FROM STRING_SPLIT(@CopyUserIDs, N',')
            WHERE TRY_CAST(value AS INT) IS NOT NULL
              AND TRY_CAST(value AS INT) <> @SenderUserID
              AND NOT EXISTS (
                SELECT 1 FROM dbo.UserNotifications UN
                WHERE UN.UserID = TRY_CAST(value AS INT) AND UN.MessageID = @NewMessageID AND UN.IsRead = 0
              );
        END

        COMMIT;

        SET @Success = 1;
        SET @Message = N'پیام با موفقیت ارسال شد. شماره: ' + @MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ارسال پیام: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message,
           @NewMessageID AS NewMessageID, @MessageNumber AS MessageNumber;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertProjectTask
ALTER PROCEDURE [dbo].[sp_InsertProjectTask]
    @ProjectID     BIGINT,
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX) = NULL,
    @ToUserID      INT,
    @msgPriorityID INT = NULL,
    @SenderUserID  INT,
    @Year          SMALLINT,
    @CreateUser    INT,
    @DueDate       DATE = NULL
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
            (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser, DueDate)
        VALUES
            (NEWID(), @MessageTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser, @DueDate);

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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetMessageHeader
ALTER PROCEDURE [dbo].[sp_GetMessageHeader]
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        M.MessageID,
        M.Subject,
        M.MessageText,
        M.DueDate,
        M.MessageTypeID,
        MT.MessageTypeName,
        M.SenderUserID,
        LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
        M.CreateDate,
        M.CreateUser
    FROM dbo.Messages M
    JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
    LEFT JOIN dbo.Users SU   ON SU.UserID = M.SenderUserID
    WHERE M.MessageID = @MessageID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetProjectTasks
ALTER PROCEDURE [dbo].[sp_GetProjectTasks]
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
        m.DueDate,
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

