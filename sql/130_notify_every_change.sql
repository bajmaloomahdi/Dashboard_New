/* ==========================================================================
   پچ خودکار شماره: 130 | نام: notify_every_change
   تاریخ: 2026-10-28 18:21:33 | شامل 16 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_NotifyMessageParticipants
-- اعلانِ «تغییرِ تازه» برایِ همهٔ طرف‌هایِ یک پیام (فرستنده، گیرندگان/ارجاع‌دهندگان، رونوشت‌ها) به‌جز انجام‌دهنده.
-- پچِ ۱۳۰: هر تغییر (توضیح، وضعیت، ارجاع، رونوشت) اعلانِ خودش را دارد — دیگر به‌خاطرِ اعلانِ خوانده‌نشدهٔ قبلی جا نمی‌افتد
-- (شمارنده تعدادِ پیام‌ها را می‌شمارد و فهرستِ اعلان‌ها هر پیام را یک‌بار نشان می‌دهد). @AlsoExceptUserID: کسی که همین حالا اعلانِ خودش را گرفته.
ALTER PROCEDURE dbo.sp_NotifyMessageParticipants
    @MessageID        INT,
    @ExceptUserID     INT = NULL,
    @AlsoExceptUserID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
    SELECT P.UserID, @MessageID, GETDATE(), 0
    FROM (
        SELECT SenderUserID AS UserID FROM dbo.Messages WHERE MessageID = @MessageID
        UNION SELECT FromUserID FROM dbo.MessageDetails WHERE MessageID = @MessageID
        UNION SELECT ToUserID FROM dbo.MessageDetails WHERE MessageID = @MessageID
        UNION SELECT UserID FROM dbo.MessageCopies WHERE MessageID = @MessageID
    ) P
    WHERE P.UserID IS NOT NULL
      AND P.UserID <> ISNULL(@ExceptUserID, 0)
      AND P.UserID <> ISNULL(@AlsoExceptUserID, 0);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_UpdateMessageDetailStatus
ALTER PROCEDURE dbo.sp_UpdateMessageDetailStatus
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_ForwardMessage
ALTER PROCEDURE dbo.sp_ForwardMessage
    @MessageID    INT,
    @FromUserID   INT,
    @ToUserID     INT,
    @Description  NVARCHAR(1000) = NULL,
    @CreateUser   INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @ReferralStatusID INT;
    DECLARE @IsTask BIT = 0;
    DECLARE @LastToUserID INT;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
        BEGIN
            SELECT 0 AS Success, N'پیام مورد نظر یافت نشد.' AS Message;
            RETURN;
        END

        -- فقط وظیفه قابل ارجاع است
        SELECT @IsTask = CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END
        FROM dbo.Messages M
        JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
        WHERE M.MessageID = @MessageID;

        IF @IsTask = 0
        BEGIN
            SELECT 0 AS Success, N'فقط پیام‌های وظیفه قابل ارجاع هستند.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @ToUserID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'گیرنده جدید نامعتبر است.' AS Message;
            RETURN;
        END

        IF @ToUserID = @FromUserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید وظیفه را به خودتان ارجاع دهید.' AS Message;
            RETURN;
        END

        -- فقط آخرین گیرنده فعلی می‌تواند ارجاع دهد
        SELECT TOP 1 @LastToUserID = MD.ToUserID
        FROM dbo.MessageDetails MD
        WHERE MD.MessageID = @MessageID
        ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC;

        IF @LastToUserID IS NOT NULL AND @LastToUserID <> @FromUserID
        BEGIN
            SELECT 0 AS Success, N'فقط آخرین گیرنده وظیفه می‌تواند آن را ارجاع دهد.' AS Message;
            RETURN;
        END

        -- ========== مجوز ارجاع ==========
        DECLARE @IsFromManager BIT = 0;
        SELECT @IsFromManager = 1
        FROM dbo.UserPositions UP
        JOIN dbo.Positions P ON P.PositionID = UP.PositionID
        WHERE UP.UserID = @FromUserID AND UP.IsActive = 1
          AND P.IsUnitManager = 1 AND P.IsActive = 1;

        IF @IsFromManager = 0
        BEGIN
            -- کاربر عادی: گیرنده باید هم‌واحدی باشد (کارمند یا مدیر همون واحد)
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.UserPositions UP_From
                JOIN dbo.UserPositions UP_To ON UP_To.UnitID = UP_From.UnitID
                WHERE UP_From.UserID = @FromUserID AND UP_From.IsActive = 1
                  AND UP_To.UserID = @ToUserID AND UP_To.IsActive = 1
            )
            BEGIN
                SELECT 0 AS Success, N'کاربر عادی فقط می‌تواند به هم‌واحدی‌ها یا مدیر واحد خود ارجاع دهد.' AS Message;
                RETURN;
            END
        END

        -- وضعیت «ارجاع»
        SELECT @ReferralStatusID = MessageStatusID
        FROM dbo.MessageStatuses
        WHERE MessageStatusName = N'ارجاع' AND IsActive = 1;
        IF @ReferralStatusID IS NULL
            SET @ReferralStatusID = 2;

        BEGIN TRAN;

        INSERT INTO dbo.MessageDetails
        (
            RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID,
            Description, CreateDate, CreateUser
        )
        VALUES
        (
            NEWID(), @MessageID, @FromUserID, @ToUserID, @ReferralStatusID,
            @Description, GETDATE(), @CreateUser
        );

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@ToUserID, @MessageID, GETDATE(), 0);
        -- پچِ ۱۳۰: بقیهٔ طرف‌هایِ نامه هم از ارجاع باخبر می‌شوند
        EXEC dbo.sp_NotifyMessageParticipants @MessageID = @MessageID, @ExceptUserID = @FromUserID, @AlsoExceptUserID = @ToUserID;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'وظیفه با موفقیت ارجاع شد.';
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ارجاع وظیفه: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_AddMessageCopy
ALTER PROCEDURE dbo.sp_AddMessageCopy
    @MessageID    INT,
    @UserID       INT,                      -- کاربری که رونوشت اضافه می‌کند
    @CopyUserID   INT,                      -- کاربر جدید رونوشت
    @Description  NVARCHAR(1000) = NULL,
    @CreateUser   INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success BIT           = 0;
    DECLARE @Message NVARCHAR(500) = N'';

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
        BEGIN
            SELECT 0 AS Success, N'پیام مورد نظر یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @CopyUserID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'کاربر رونوشت نامعتبر است.' AS Message;
            RETURN;
        END

        IF @CopyUserID = @UserID
        BEGIN
            SELECT 0 AS Success, N'نمی‌توانید به خودتان رونوشت بدهید.' AS Message;
            RETURN;
        END

        -- تکراری نباشد
        IF EXISTS (SELECT 1 FROM dbo.MessageCopies
                   WHERE MessageID = @MessageID AND UserID = @CopyUserID)
        BEGIN
            SELECT 0 AS Success, N'این کاربر قبلاً رونوشت این پیام است.' AS Message;
            RETURN;
        END

        BEGIN TRAN;

        INSERT INTO dbo.MessageCopies
        (
            RowGuid, MessageID, UserID, Description, CreateDate, CreateUser
        )
        VALUES
        (
            NEWID(), @MessageID, @CopyUserID, @Description, GETDATE(), @CreateUser
        );

        -- اعلان برای رونوشت جدید
        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@CopyUserID, @MessageID, GETDATE(), 0);
        -- پچِ ۱۳۰: بقیهٔ طرف‌هایِ نامه هم از رونوشتِ تازه باخبر می‌شوند
        EXEC dbo.sp_NotifyMessageParticipants @MessageID = @MessageID, @ExceptUserID = @UserID, @AlsoExceptUserID = @CopyUserID;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'رونوشت با موفقیت اضافه شد.';
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در افزودن رونوشت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUserNotifications
-- پچِ ۱۳۰: هر پیام یک‌بار (آخرین تغییر)؛ «خوانده‌نشده» اگر هر تغییرِ خوانده‌نشده‌ای داشته باشد
ALTER PROCEDURE dbo.sp_GetUserNotifications
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    ;WITH L AS (
        SELECT UN.UserNotificationID, UN.MessageID, UN.CreateDate,
               ROW_NUMBER() OVER (PARTITION BY UN.MessageID ORDER BY UN.UserNotificationID DESC) AS rn,
               MIN(CAST(UN.IsRead AS INT)) OVER (PARTITION BY UN.MessageID) AS AllRead
        FROM dbo.UserNotifications UN
        WHERE UN.UserID = @UserID
    )
    SELECT
        L.UserNotificationID,
        L.MessageID,
        M.MessageNumber,
        M.Subject,
        MT.MessageTypeName,
        LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
        L.CreateDate,
        CAST(L.AllRead AS BIT) AS IsRead
    FROM L
    JOIN dbo.Messages M       ON M.MessageID = L.MessageID
    JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
    LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
    WHERE L.rn = 1
    ORDER BY L.CreateDate DESC;
END
GO

-- [CREATE_TABLE] روی TABLE: CalendarEventNotices
CREATE TABLE dbo.CalendarEventNotices (
    NoticeID    BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarEventNotices PRIMARY KEY,
    EventID     BIGINT        NOT NULL CONSTRAINT FK_CalendarEventNotices_Event REFERENCES dbo.CalendarEvents (EventID),
    UserID      INT           NOT NULL,
    Kind        NVARCHAR(20)  NOT NULL CONSTRAINT CK_CalendarEventNotices_Kind CHECK (Kind IN (N'COMMENT', N'ATTACHMENT', N'STATUS', N'RESPONSE')),
    ActorUserID INT           NULL,
    Detail      NVARCHAR(100) NULL,
    CreateDate  DATETIME2(0)  NOT NULL CONSTRAINT DF_CalendarEventNotices_Date DEFAULT SYSDATETIME()
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventNotices_User
CREATE INDEX IX_CalendarEventNotices_User ON dbo.CalendarEventNotices (UserID, NoticeID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventNotices_Date
CREATE INDEX IX_CalendarEventNotices_Date ON dbo.CalendarEventNotices (CreateDate)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_NotifyEventActivity
-- پچِ ۱۳۰: ساختِ اطلاعِ فعالیت برایِ طرف‌هایِ رویداد به‌جز انجام‌دهنده —
-- سازنده، مالک و شرکت‌کنندگانِ فعالِ ردنکرده (پاسخ به دعوت: فقط سازنده و مالک)؛ کاربرِ فعال با مجوزِ تقویم؛
-- توضیح فقط برایِ کسی که اجازهٔ دیدنِ توضیحات دارد.
CREATE PROCEDURE dbo.sp_Calendar_NotifyEventActivity
    @EventID     BIGINT,
    @Kind        NVARCHAR(20),
    @ActorUserID INT,
    @Detail      NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @Kind NOT IN (N'COMMENT', N'ATTACHMENT', N'STATUS', N'RESPONSE')
    BEGIN SELECT 0 AS Notified; RETURN; END

    INSERT INTO dbo.CalendarEventNotices (EventID, UserID, Kind, ActorUserID, Detail)
    SELECT @EventID, P.UserID, @Kind, @ActorUserID, NULLIF(LTRIM(RTRIM(@Detail)), N'')
    FROM (
        SELECT CreatedByUserID AS UserID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1
        UNION SELECT OwnerUserID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1
        UNION SELECT A.UserID FROM dbo.CalendarEventAttendees A
              INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1
              WHERE A.EventID = @EventID AND A.IsActive = 1 AND A.ResponseStatus <> N'REJECTED' AND @Kind <> N'RESPONSE'
    ) P
    WHERE P.UserID IS NOT NULL AND P.UserID <> ISNULL(@ActorUserID, 0)
      AND EXISTS (SELECT 1 FROM dbo.Users U WHERE U.UserID = P.UserID AND U.IsActive = 1)
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(P.UserID, N'CALENDAR_VIEW'))
      AND (@Kind <> N'COMMENT' OR EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(P.UserID, N'CALENDAR_COMMENT_VIEW')));

    SELECT @@ROWCOUNT AS Notified;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventNotices
-- پچِ ۱۳۰: اطلاع‌هایِ فعالیتِ رویداد برایِ خودِ کاربر (۷ روزِ اخیر) — اعلانِ داخلِ برنامه
CREATE PROCEDURE dbo.sp_Calendar_GetEventNotices
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (50) N.NoticeID, N.EventID, N.Kind, N.Detail, CONVERT(VARCHAR(19), N.CreateDate, 120) AS CreateDate,
           E.Title, E.StartDateTime, E.IsAllDay, E.IsPrivate,
           dbo.fn_GetUserFullName(AU.FirstName, AU.LastName, AU.UserName) AS ActorName
    FROM dbo.CalendarEventNotices N
    INNER JOIN dbo.CalendarEvents E ON E.EventID = N.EventID AND E.IsActive = 1
    LEFT JOIN dbo.Users AU ON AU.UserID = N.ActorUserID
    WHERE N.UserID = @UserID AND N.CreateDate >= DATEADD(DAY, -7, SYSDATETIME())
    ORDER BY N.NoticeID DESC;
END
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_SourceType
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox ADD CONSTRAINT CK_PushOutbox_SourceType CHECK ([SourceType]='CANCEL' OR [SourceType]='INVITE' OR [SourceType]='BROADCAST' OR [SourceType]='TEST' OR [SourceType]='REMINDER' OR [SourceType]='NOTIFICATION' OR [SourceType]='CALEVENT')
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_Kind
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox ADD CONSTRAINT CK_PushOutbox_Kind CHECK ([Kind]='CANCEL' OR [Kind]='INVITE' OR [Kind]='BROADCAST' OR [Kind]='TEST' OR [Kind]='REMINDER' OR [Kind]='TASK' OR [Kind]='MESSAGE' OR [Kind]='CALEVENT')
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_Enqueue
/* صف‌بندی (با قفلِ برنامه سریالی می‌شود؛ کلیدِ یکتایِ UQ_PushOutbox_Source مانعِ صف‌بندیِ تکراری است).
   پیام/وظیفه: UserNotificationID > کفِ اسکن (≥ Watermarkِ فعال‌سازی). یادآوری: سررسیدشده، IsSent = 0 و RemindAt ≥ ActivatedAt.
   @MaxAgeMinutes = NULL یعنی بدونِ محدودیتِ سن (تصمیمِ مرحلهٔ اول). */
ALTER PROCEDURE dbo.sp_Push_Enqueue
    @MaxAgeMinutes INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    DECLARE @Enabled BIT, @ActivatedAt DATETIME2(0), @ScanFrom BIGINT;
    SELECT @Enabled = IsEnabled, @ActivatedAt = ActivatedAt, @ScanFrom = NotificationScanFromID FROM dbo.PushState WHERE PushStateID = 1;
    IF ISNULL(@Enabled, 0) = 0 OR @ActivatedAt IS NULL
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان فعال نیست.' AS Message, 0 AS NotificationsQueued, 0 AS RemindersQueued, 0 AS InvitesQueued, 0 AS CancelsQueued, 0 AS EventNoticesQueued; RETURN; END

    DECLARE @Stale DATETIME2(0) = CASE WHEN @MaxAgeMinutes IS NULL THEN NULL ELSE DATEADD(MINUTE, -@MaxAgeMinutes, @Now) END;
    DECLARE @NoVersion DATETIME2(0) = '19000101';
    DECLARE @N INT = 0, @R INT = 0, @I INT = 0, @C INT = 0, @E INT = 0;

    BEGIN TRAN;
    EXEC sp_getapplock @Resource = N'PushEnqueue', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 30000;

    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'NOTIFICATION', UN.UserNotificationID, @NoVersion, UN.UserID, CAST(UN.CreateDate AS DATETIME2(0)), K.Kind,
           N'/messages/' + CAST(UN.MessageID AS NVARCHAR(20)), 'msg-' + CAST(UN.MessageID AS VARCHAR(20)),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.UserNotifications UN
    LEFT JOIN dbo.Messages M ON M.MessageID = UN.MessageID
    LEFT JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
    CROSS APPLY (SELECT CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 'TASK' ELSE 'MESSAGE' END AS Kind) K
    CROSS APPLY (SELECT CASE
        WHEN M.MessageID IS NULL THEN 'SKIPPED_INVALID'
        WHEN UN.IsRead = 1 THEN 'SKIPPED_READ'
        WHEN @Stale IS NOT NULL AND UN.CreateDate < @Stale THEN 'SKIPPED_STALE'
        -- پچِ ۱۳۰: هر تغییرِ نامه Pushِ خودش را دارد (Tagِ msg-… رویِ دستگاه جایگزینِ اعلانِ قبلیِ همان نامه می‌شود)
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS
                         WHERE PS.UserID = UN.UserID AND PS.IsActive = 1 AND PS.SubscribedAt <= DATEADD(SECOND, 1, UN.CreateDate)) /* SubscribedAt ثانیه‌ای گرد می‌شود */ THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE UN.UserNotificationID > ISNULL(@ScanFrom, 0)
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'NOTIFICATION' AND O.SourceID = UN.UserNotificationID AND O.SourceVersion = @NoVersion);
    SET @N = @@ROWCOUNT;

    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'REMINDER', R.ReminderID, R.RemindAt, R.UserID, R.RemindAt, 'REMINDER', N'/calendar',
           'rem-' + CAST(R.ReminderID AS VARCHAR(20)) + '-' + FORMAT(R.RemindAt, 'yyMMddHHmm'),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.Reminders R
    LEFT JOIN dbo.CalendarEvents E ON R.EntityType = N'CALENDAR_EVENT' AND E.EventID = R.EntityID AND E.IsActive = 1
    CROSS APPLY (SELECT CASE
        WHEN @Stale IS NOT NULL AND R.RemindAt < @Stale THEN 'SKIPPED_STALE'
        WHEN E.EventID IS NOT NULL AND DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt)) < @Now THEN 'SKIPPED_STALE' /* پچِ ۱۱۳: وقوعِ جلسه تمام شده */
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = R.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE R.IsActive = 1 AND R.IsSent = 0
      AND R.RemindAt <= @Now AND R.RemindAt >= @ActivatedAt
      AND (R.EntityType <> N'CALENDAR_EVENT' OR (E.EventID IS NOT NULL AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */)) /* رویدادِ لغوشده یادآوری ندارد */
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = R.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(R.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE R.EntityType = N'CALENDAR_EVENT' AND RA.EventID = R.EntityID
                      AND RA.UserID = R.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'REMINDER' AND O.SourceID = R.ReminderID AND O.SourceVersion = R.RemindAt);
    SET @R = @@ROWCOUNT;

    /* دعوت به جلسه: هر نسخهٔ دعوت (InvitedAt) یک‌بار؛ فقط دعوتِ در انتظار، رویدادِ فعال/غیرلغو/تمام‌نشده و گیرندهٔ مجاز */
    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'INVITE', A.CalendarEventAttendeeID, A.InvitedAt, A.UserID, A.InvitedAt, 'INVITE', N'/calendar?invites=1',
           CASE WHEN A.InviteIsUpdate = 1 THEN 'invu-' ELSE 'inv-' END + CAST(A.CalendarEventAttendeeID AS VARCHAR(20)) + '-' + FORMAT(A.InvitedAt, 'yyMMddHHmmss'),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */
    CROSS APPLY (SELECT CASE
        WHEN @Stale IS NOT NULL AND A.InvitedAt < @Stale THEN 'SKIPPED_STALE'
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = A.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE A.IsActive = 1 AND A.ResponseStatus = N'PENDING' AND A.RelationType <> N'ORGANIZER'
      AND A.InvitedAt IS NOT NULL AND A.InvitedAt <= @Now AND A.InvitedAt >= @ActivatedAt
      AND A.UserID <> E.CreatedByUserID
      AND (E.RecurrenceType = N'NONE' AND E.EndDateTime >= @Now OR E.RecurrenceType <> N'NONE' AND (E.RecurrenceEndDate IS NULL OR E.RecurrenceEndDate >= CAST(@Now AS DATE)))
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
          AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW'))
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'INVITE' AND O.SourceID = A.CalendarEventAttendeeID AND O.SourceVersion = A.InvitedAt);
    SET @I = @@ROWCOUNT;

    /* پچِ ۱۱۳ — لغو/حذفِ جلسه: هر نسخهٔ اطلاع (CancelNoticeAt) یک‌بار؛ فقط تا وقتی جلسه هنوز لغو/حذف است و گیرنده مجاز */
    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'CANCEL', A.CalendarEventAttendeeID, A.CancelNoticeAt, A.UserID, A.CancelNoticeAt, 'CANCEL', N'/calendar',
           'cnl-' + CAST(A.CalendarEventAttendeeID AS VARCHAR(20)) + '-' + FORMAT(A.CancelNoticeAt, 'yyMMddHHmmss'),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND (E.IsActive = 0 OR (E.Status = N'CANCELLED' AND A.IsActive = 1))
    CROSS APPLY (SELECT CASE
        WHEN @Stale IS NOT NULL AND A.CancelNoticeAt < @Stale THEN 'SKIPPED_STALE'
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = A.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE A.CancelNoticeAt IS NOT NULL AND A.CancelNoticeAt <= @Now AND A.CancelNoticeAt >= @ActivatedAt
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW'))
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'CANCEL' AND O.SourceID = A.CalendarEventAttendeeID AND O.SourceVersion = A.CancelNoticeAt);
    SET @C = @@ROWCOUNT;

    /* پچِ ۱۳۰ — فعالیت رویِ رویداد (توضیح، ضمیمه، وضعیت، پاسخ): هر اطلاع یک‌بار؛ Tag به‌ازایِ رویداد (رویِ دستگاه جایگزین می‌شود) */
    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'CALEVENT', N.NoticeID, @NoVersion, N.UserID, N.CreateDate, 'CALEVENT', N'/calendar', 'cev-' + CAST(N.EventID AS VARCHAR(20)),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.CalendarEventNotices N
    INNER JOIN dbo.CalendarEvents E ON E.EventID = N.EventID AND E.IsActive = 1
    CROSS APPLY (SELECT CASE
        WHEN @Stale IS NOT NULL AND N.CreateDate < @Stale THEN 'SKIPPED_STALE'
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = N.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE N.CreateDate <= @Now AND N.CreateDate >= @ActivatedAt
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = N.UserID AND AU.IsActive = 1)
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(N.UserID, N'CALENDAR_VIEW'))
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'CALEVENT' AND O.SourceID = N.NoticeID AND O.SourceVersion = @NoVersion);
    SET @E = @@ROWCOUNT;
    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued, @I AS InvitesQueued, @C AS CancelsQueued, @E AS EventNoticesQueued;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_CheckOutbox
/* بررسیِ دوباره پیش از ارسال: پیامِ خوانده‌شده / یادآوریِ تأییدشده یا جابه‌جاشده دیگر ارسال نمی‌شود؛
   اعلانِ عمومی به کاربری که در این فاصله غیرفعال شده ارسال نمی‌شود. */
ALTER PROCEDURE dbo.sp_Push_CheckOutbox
    @PushOutboxID BIGINT,
    @Worker       NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Type VARCHAR(20), @SourceID BIGINT, @Version DATETIME2(0), @UserID INT;
    SELECT @Type = SourceType, @SourceID = SourceID, @Version = SourceVersion, @UserID = UserID
    FROM dbo.PushOutbox WHERE PushOutboxID = @PushOutboxID AND Status = 'SENDING' AND LockedBy = @Worker;
    IF @Type IS NULL
    BEGIN SELECT 'LEASE_LOST' AS Validity; RETURN; END

    IF @Type = 'NOTIFICATION'
    BEGIN
        IF EXISTS (SELECT 1 FROM dbo.UserNotifications WHERE UserNotificationID = @SourceID AND IsRead = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.UserNotifications WHERE UserNotificationID = @SourceID)
        BEGIN SELECT 'SKIPPED_READ' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'REMINDER'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.Reminders WHERE ReminderID = @SourceID AND IsActive = 1 AND IsSent = 0 AND RemindAt = @Version)
        BEGIN SELECT 'SKIPPED_ACKNOWLEDGED' AS Validity; RETURN; END
        /* پچِ ۱۱۳ — وقوعِ جلسه در این فاصله تمام شده (زمان‌بندِ متوقف/گوشیِ آفلاین) → یادآوریِ منقضی ارسال نمی‌شود */
        IF EXISTS (SELECT 1 FROM dbo.Reminders R INNER JOIN dbo.CalendarEvents E ON R.EntityType = N'CALENDAR_EVENT' AND E.EventID = R.EntityID
                   WHERE R.ReminderID = @SourceID AND DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt)) < SYSDATETIME())
        BEGIN SELECT 'SKIPPED_STALE' AS Validity; RETURN; END
        /* رویدادِ یادآوری در این فاصله حذف یا «لغو» شده → ارسال نمی‌شود */
        IF EXISTS (SELECT 1 FROM dbo.Reminders R WHERE R.ReminderID = @SourceID AND R.EntityType = N'CALENDAR_EVENT'
                   AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents E WHERE E.EventID = R.EntityID AND E.IsActive = 1 AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */))
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
        /* کاربر در این فاصله غیرفعال شده، مجوزِ تقویم را از دست داده یا دعوت را رد کرده → ارسال نمی‌شود */
        IF NOT EXISTS (SELECT 1 FROM dbo.Reminders R WHERE R.ReminderID = @SourceID
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = R.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(R.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE R.EntityType = N'CALENDAR_EVENT' AND RA.EventID = R.EntityID
                      AND RA.UserID = R.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */)
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'INVITE'
    BEGIN
        /* پاسخ داده یا دعوتِ تازه‌تری صادر شده → این نسخه ارسال نمی‌شود */
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees WHERE CalendarEventAttendeeID = @SourceID AND IsActive = 1
                       AND ResponseStatus = N'PENDING' AND InvitedAt = @Version)
        BEGIN SELECT 'SKIPPED_ACKNOWLEDGED' AS Validity; RETURN; END
        /* رویداد حذف/لغو شده یا تمام شده، یا گیرنده غیرفعال/بی‌مجوز شده */
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees A
                       INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */
                       WHERE A.CalendarEventAttendeeID = @SourceID
                         AND (E.RecurrenceType = N'NONE' AND E.EndDateTime >= SYSDATETIME() OR E.RecurrenceType <> N'NONE' AND (E.RecurrenceEndDate IS NULL OR E.RecurrenceEndDate >= CAST(SYSDATETIME() AS DATE)))
                         AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
          AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW')))
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'CANCEL'
    BEGIN
        /* پچِ ۱۱۳ — جلسه دوباره فعال شده یا نسخهٔ تازه‌تری صادر شده → این نسخه ارسال نمی‌شود */
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees A
                       INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND (E.IsActive = 0 OR (E.Status = N'CANCELLED' AND A.IsActive = 1))
                       WHERE A.CalendarEventAttendeeID = @SourceID AND A.CancelNoticeAt = @Version)
        BEGIN SELECT 'SKIPPED_ACKNOWLEDGED' AS Validity; RETURN; END
        /* جلسه در این فاصله تمام شده (مثلاً تلاشِ دوبارهٔ دیرهنگام) → اطلاعِ لغو بی‌معناست */
        IF EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees A INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID
                   WHERE A.CalendarEventAttendeeID = @SourceID
                     AND (E.RecurrenceType = N'NONE' AND E.EndDateTime < SYSDATETIME() OR E.RecurrenceType <> N'NONE' AND E.RecurrenceEndDate < CAST(SYSDATETIME() AS DATE)))
        BEGIN SELECT 'SKIPPED_STALE' AS Validity; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'CALEVENT'
    BEGIN
        /* پچِ ۱۳۰ — رویداد در این فاصله حذف شده، یا گیرنده غیرفعال/بی‌مجوز شده */
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventNotices N INNER JOIN dbo.CalendarEvents E ON E.EventID = N.EventID AND E.IsActive = 1
                       WHERE N.NoticeID = @SourceID AND N.UserID = @UserID)
           OR NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'BROADCAST'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.PushBroadcastRecipients WHERE PushBroadcastRecipientID = @SourceID AND UserID = @UserID)
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    /* پچِ ۱۱۳ — پایانِ اعتبار: Push پس از پایانِ وقوع/جلسه بی‌معناست؛ برنامه TTL را حداکثر تا این لحظه می‌گذارد.
       ثانیه‌هایِ باقی‌مانده همین‌جا با ساعتِ SQL Server حساب می‌شود (Laravel روی UTC است؛ تاریخ‌ها ساعتِ دیواریِ SQL‌اند). NULL = TTLِ پیش‌فرض. */
    DECLARE @ExpiresAt DATETIME2(0) =
           CASE @Type
               WHEN 'REMINDER' THEN (SELECT DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt))
                                     FROM dbo.Reminders R INNER JOIN dbo.CalendarEvents E ON R.EntityType = N'CALENDAR_EVENT' AND E.EventID = R.EntityID
                                     WHERE R.ReminderID = @SourceID)
               WHEN 'INVITE' THEN (SELECT CASE WHEN E.RecurrenceType = N'NONE' THEN E.EndDateTime END
                                   FROM dbo.CalendarEventAttendees A INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID WHERE A.CalendarEventAttendeeID = @SourceID)
               WHEN 'CANCEL' THEN (SELECT CASE WHEN E.RecurrenceType = N'NONE' THEN E.EndDateTime END
                                   FROM dbo.CalendarEventAttendees A INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID WHERE A.CalendarEventAttendeeID = @SourceID)
           END;
    SELECT 'OK' AS Validity, DATEDIFF(SECOND, SYSDATETIME(), @ExpiresAt) AS ExpiresInSeconds;
END
GO

