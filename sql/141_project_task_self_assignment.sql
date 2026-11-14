-- Allow active project leads to assign tasks to themselves.
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
