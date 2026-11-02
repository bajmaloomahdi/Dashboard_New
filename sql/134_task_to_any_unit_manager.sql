/* ==========================================================================
   پچ خودکار شماره: 134 | نام: task_to_any_unit_manager
   تاریخ: 2026-11-02 10:52:36 | شامل 2 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetSendTargets
ALTER PROCEDURE dbo.sp_GetSendTargets
    @UserID INT,
    @IsTask BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    -- پچِ ۱۳۴: وظیفه به مدیرِ هر واحد — فهرست به واحدِ فرستنده محدود نیست (ساختارِ سازمانی فقط برایِ جریان‌هایِ کاری)

    IF @IsTask = 1
    BEGIN
        -- واحدها + مدیر خودکار (فقط یک result set)
        SELECT
            U.UnitID,
            U.UnitCode,
            U.UnitName,
            (SELECT TOP 1 UP2.UserID
             FROM dbo.UserPositions UP2
             JOIN dbo.Positions P2 ON P2.PositionID = UP2.PositionID
             WHERE UP2.UnitID = U.UnitID AND UP2.IsActive = 1
               AND P2.IsUnitManager = 1 AND P2.IsActive = 1
             ORDER BY UP2.CreateDate) AS ManagerUserID,
            (SELECT TOP 1 LTRIM(CONCAT(Us.FirstName, N' ', Us.LastName))
             FROM dbo.UserPositions UP2
             JOIN dbo.Positions P2 ON P2.PositionID = UP2.PositionID
             LEFT JOIN dbo.Users Us ON Us.UserID = UP2.UserID
             WHERE UP2.UnitID = U.UnitID AND UP2.IsActive = 1
               AND P2.IsUnitManager = 1 AND P2.IsActive = 1
             ORDER BY UP2.CreateDate) AS ManagerName
        FROM dbo.OrganizationalUnits U
        WHERE U.IsActive = 1
        ORDER BY U.UnitCode;
    END
    ELSE
    BEGIN
        -- کاربران فعال (فقط یک result set) — آمار در PHP محاسبه می‌شود
        SELECT
            U.UserID,
            LTRIM(CONCAT(U.FirstName, N' ', U.LastName)) AS FullName,
            U.UserName,
            (SELECT TOP 1 OU.UnitName
             FROM dbo.UserPositions UP
             JOIN dbo.OrganizationalUnits OU ON OU.UnitID = UP.UnitID
             WHERE UP.UserID = U.UserID AND UP.IsActive = 1
             ORDER BY UP.CreateDate DESC) AS UnitName,
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.UserPositions UP
                JOIN dbo.Positions P ON P.PositionID = UP.PositionID
                WHERE UP.UserID = U.UserID AND UP.IsActive = 1
                  AND P.IsUnitManager = 1 AND P.IsActive = 1
            ) THEN 1 ELSE 0 END AS IsManager
        FROM dbo.Users U
        WHERE U.IsActive = 1
        ORDER BY FullName;
    END
END
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

                -- پچِ ۱۳۴: هر کاربری می‌تواند به مدیرِ هر واحدی وظیفه بفرستد (قیدِ «فقط مدیرِ واحدِ خود» برداشته شد)
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

