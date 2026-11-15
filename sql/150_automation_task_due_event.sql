SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
-- پچِ ۱۳۸: رویدادِ خودکارِ مهلتِ وظیفهٔ پروژه — روزِ مهلت ساعتِ ۱۳:۰۰ تا ۱۳:۳۰؛ مالک = گیرندهٔ وظیفه (پذیرفته، بدونِ دعوت)،
-- سازنده = فرستنده؛ به پروژه و همان وظیفه (MESSAGE) وصل می‌شود. برنامه فقط پس از ثبتِ موفقِ وظیفهٔ دارایِ مهلت صدا می‌زند.
CREATE OR ALTER PROCEDURE dbo.sp_Calendar_CreateTaskDueEvent
    @ProjectID      BIGINT,
    @MessageID      INT,
    @Title          NVARCHAR(250),
    @Description    NVARCHAR(MAX) = NULL,
    @DueDate        DATE,
    @CreatorUserID  INT,
    @AssigneeUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @DueDate IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
       OR NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @AssigneeUserID AND IsActive = 1)
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'وظیفه یا گیرنده معتبر نیست.' AS Message, CAST(NULL AS BIGINT) AS NewEventID; RETURN; END
    -- یک رویداد برایِ هر وظیفه
    DECLARE @Existing BIGINT = (SELECT TOP 1 l.EventID FROM dbo.CalendarEventLinks l INNER JOIN dbo.CalendarEvents e ON e.EventID = l.EventID AND e.IsActive = 1
                                WHERE l.LinkType = 'MESSAGE' AND l.EntityID = @MessageID AND l.IsActive = 1);
    IF @Existing IS NOT NULL
    BEGIN SELECT CAST(1 AS BIT) AS Success, N'رویدادِ مهلتِ این وظیفه از قبل وجود دارد.' AS Message, @Existing AS NewEventID; RETURN; END

    DECLARE @Start DATETIME2(0) = DATEADD(HOUR, 13, CAST(@DueDate AS DATETIME2(0)));
    DECLARE @End DATETIME2(0) = DATEADD(MINUTE, 30, @Start);
    DECLARE @EventID BIGINT;
    BEGIN TRAN;
    INSERT INTO dbo.CalendarEvents (Title, Description, StartDateTime, EndDateTime, IsAllDay, Color, Status, RecurrenceType, RecurrenceInterval,
                                    CreatedByUserID, IsActive, Date_InsertFirst, UserID_InsertFirst, OwnerUserID, IsPrivate)
    VALUES (LEFT(@Title, 250), NULLIF(LTRIM(RTRIM(@Description)), N''), @Start, @End, 0, '#fa8c16', N'CONFIRMED', N'NONE', 1,
            @CreatorUserID, 1, SYSDATETIME(), @CreatorUserID, @AssigneeUserID, 0);
    SET @EventID = SCOPE_IDENTITY();
    IF @CreatorUserID <> @AssigneeUserID
    BEGIN
        INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@EventID, @CreatorUserID, N'ORGANIZER', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreatorUserID);
        INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@EventID, @AssigneeUserID, N'PARTICIPANT', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreatorUserID);
    END
    INSERT INTO dbo.CalendarEventLinks (EventID, LinkType, EntityID, UserID_InsertFirst)
    VALUES (@EventID, 'MESSAGE', @MessageID, @CreatorUserID);
    IF @ProjectID IS NOT NULL
        INSERT INTO dbo.CalendarEventLinks (EventID, LinkType, EntityID, UserID_InsertFirst)
        VALUES (@EventID, 'PROJECT', @ProjectID, @CreatorUserID);
    COMMIT;
    SELECT CAST(1 AS BIT) AS Success, N'رویدادِ مهلتِ وظیفه ثبت شد.' AS Message, @EventID AS NewEventID;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_UpdateMessageDetailStatus
    @MessageID       INT,
    @UserID          INT,                  -- آخرین گیرنده (تغییردهنده)
    @MessageStatusID INT,                  -- وضعیت جدید
    @Description     NVARCHAR(1000) = NULL, -- توضیح (اختیاری)
    @CreateUser      INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @TargetDetailID INT;
    DECLARE @SenderUserID INT;

    BEGIN TRY
        -- ---------- اعتبارسنجی ----------
        IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
        BEGIN
            SELECT 0 AS Success, N'پیام مورد نظر یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageStatuses
                       WHERE MessageStatusID = @MessageStatusID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'وضعیت نامعتبر است.' AS Message;
            RETURN;
        END

        -- آخرین ردیف این کاربر برای این پیام
        SELECT TOP 1 @TargetDetailID = MessageDetailID
        FROM dbo.MessageDetails
        WHERE MessageID = @MessageID AND ToUserID = @UserID
        ORDER BY CreateDate DESC, MessageDetailID DESC;

        IF @TargetDetailID IS NULL
        BEGIN
            SELECT 0 AS Success, N'این پیام برای شما ثبت نشده است.' AS Message;
            RETURN;
        END

        BEGIN TRAN;

        -- ---------- به‌روزرسانی وضعیت ردیف آخرین گیرنده ----------
        UPDATE dbo.MessageDetails
        SET MessageStatusID = @MessageStatusID,
            Description     = @Description,
            CreateDate      = GETDATE(),
            CreateUser      = @CreateUser
        WHERE MessageDetailID = @TargetDetailID;

        IF @MessageStatusID = 4
        BEGIN
            UPDATE e SET Status = N'DONE'
            FROM dbo.CalendarEvents AS e
            WHERE e.IsActive = 1 AND e.Status IN (N'CONFIRMED', N'TENTATIVE')
                AND EXISTS (
                    SELECT 1
                    FROM dbo.CalendarEventLinks AS taskLink
                    WHERE taskLink.EventID = e.EventID AND taskLink.LinkType = 'MESSAGE'
                        AND taskLink.EntityID = @MessageID AND taskLink.IsActive = 1
                );
        END

        -- ---------- اعلان برایِ همهٔ طرف‌هایِ نامه به‌جز تغییردهنده (پچِ ۱۳۰؛ قبلاً فقط فرستنده) ----------
        SELECT @SenderUserID = SenderUserID FROM dbo.Messages WHERE MessageID = @MessageID;

        EXEC dbo.sp_NotifyMessageParticipants @MessageID = @MessageID, @ExceptUserID = @UserID;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'وضعیت وظیفه با موفقیت به‌روزرسانی شد.';
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_InsertMessage]
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

        IF @IsTask = 1 AND @DueDate IS NOT NULL
        BEGIN
            DECLARE @EventAssignee INT;
            SELECT @EventAssignee = UserID FROM #Recipients;
            DECLARE @EventResult TABLE (Success BIT, Message NVARCHAR(500), NewEventID BIGINT);
            INSERT INTO @EventResult
                EXEC dbo.sp_Calendar_CreateTaskDueEvent
                    @ProjectID = NULL, @MessageID = @NewMessageID, @Title = @Subject,
                    @Description = @MessageText, @DueDate = @DueDate,
                    @CreatorUserID = @SenderUserID, @AssigneeUserID = @EventAssignee;
            IF NOT EXISTS (SELECT 1 FROM @EventResult WHERE Success = 1 AND NewEventID IS NOT NULL)
                THROW 51000, 'Task calendar event could not be created.', 1;
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
