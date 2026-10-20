/* ==========================================================================
   پچ خودکار شماره: 109 | نام: calendar_invitations
   تاریخ: 2026-10-19 10:01:36 | شامل 11 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE dbo.CalendarEventAttendees ADD InvitedAt DATETIME2(0) NULL
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_SourceType
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_SourceType CHECK (SourceType IN ('NOTIFICATION', 'REMINDER', 'TEST', 'BROADCAST', 'INVITE'))
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_Kind
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_Kind CHECK (Kind IN ('MESSAGE', 'TASK', 'REMINDER', 'TEST', 'BROADCAST', 'INVITE'))
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertCalendarEvent
ALTER PROCEDURE [dbo].[sp_InsertCalendarEvent]
    @Title                NVARCHAR(250),
    @Description          NVARCHAR(MAX) = NULL,
    @StartDateTime        DATETIME2(0),
    @EndDateTime          DATETIME2(0),
    @IsAllDay             BIT = 0,
    @Color                NVARCHAR(20) = '#1677ff',
    @Status               NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType       NVARCHAR(20) = 'NONE',
    @RecurrenceInterval   INT = 1,
    @RecurrenceEndDate    DATE = NULL,
    @OwnerUserID          INT,
    @OrganizationalUnitID INT = NULL,
    @AttendeeUserIDs      NVARCHAR(MAX) = NULL,
    @CreateUser           INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کاربر مالک رویداد نامعتبر است.' AS Message;
            RETURN;
        END

        DECLARE @NewEventID BIGINT;

        INSERT INTO dbo.CalendarEvents
            (Title, Description, StartDateTime, EndDateTime, IsAllDay, Color, Status,
             RecurrenceType, RecurrenceInterval, RecurrenceEndDate,
             CreatedByUserID, OwnerUserID, OrganizationalUnitID, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Title, @Description, @StartDateTime, @EndDateTime, @IsAllDay, @Color, @Status,
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate,
             @CreateUser, @OwnerUserID, @OrganizationalUnitID, SYSDATETIME(), @CreateUser);

        SET @NewEventID = SCOPE_IDENTITY();

        -- سازنده به‌عنوان ORGANIZER (اگر مالک شخص دیگری است)
        IF @CreateUser <> @OwnerUserID
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@NewEventID, @CreateUser, N'ORGANIZER', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreateUser);

            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
            VALUES (@NewEventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser, SYSDATETIME());
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
            SELECT @NewEventID, CAST(LTRIM(RTRIM(value)) AS INT), N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser, SYSDATETIME() /* InvitedAt */
            FROM STRING_SPLIT(@AttendeeUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) NOT IN (@CreateUser, @OwnerUserID)
              AND NOT EXISTS (
                  SELECT 1 FROM dbo.CalendarEventAttendees x
                  WHERE x.EventID = @NewEventID AND x.UserID = CAST(LTRIM(RTRIM(value)) AS INT)
              );
        END

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ایجاد شد.' AS Message, @NewEventID AS NewEventID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_UpdateCalendarEvent
ALTER PROCEDURE [dbo].[sp_UpdateCalendarEvent]
    @EventID              BIGINT,
    @Title                NVARCHAR(250),
    @Description          NVARCHAR(MAX) = NULL,
    @StartDateTime        DATETIME2(0),
    @EndDateTime          DATETIME2(0),
    @IsAllDay             BIT = 0,
    @Color                NVARCHAR(20) = '#1677ff',
    @Status               NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType       NVARCHAR(20) = 'NONE',
    @RecurrenceInterval   INT = 1,
    @RecurrenceEndDate    DATE = NULL,
    @OwnerUserID          INT,
    @OrganizationalUnitID INT = NULL,
    @AttendeeUserIDs      NVARCHAR(MAX) = NULL,
    @ModifyUser           INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را ویرایش کند.' AS Message;
            RETURN;
        END

        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents SET
            Title                = @Title,
            Description          = @Description,
            StartDateTime        = @StartDateTime,
            EndDateTime          = @EndDateTime,
            IsAllDay             = @IsAllDay,
            Color                = @Color,
            Status               = @Status,
            RecurrenceType       = @RecurrenceType,
            RecurrenceInterval   = @RecurrenceInterval,
            RecurrenceEndDate    = @RecurrenceEndDate,
            OwnerUserID          = @OwnerUserID,
            OrganizationalUnitID = @OrganizationalUnitID,
            Date_LastUpdate      = SYSDATETIME(),
            UserID_LastUpdate    = @ModifyUser
        WHERE EventID = @EventID;

        -- به‌روزرسانی کاربران مرتبط: غیرفعال‌سازی همه به‌جز ORGANIZER، سپس درج/فعال‌سازی لیست جدید
        /* شرکت‌کنندگانِ فعالِ پیش از این ویرایش — برایِ تشخیصِ «تازه یا دوباره اضافه‌شده» */
        DECLARE @Prev TABLE (UserID INT PRIMARY KEY);
        INSERT INTO @Prev (UserID) SELECT DISTINCT UserID FROM dbo.CalendarEventAttendees
        WHERE EventID = @EventID AND IsActive = 1 AND RelationType <> N'ORGANIZER';

        UPDATE dbo.CalendarEventAttendees
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID AND RelationType <> N'ORGANIZER';

        IF @OwnerUserID <> @Creator
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (SELECT @EventID AS EventID, @OwnerUserID AS UserID,
                   CASE WHEN EXISTS (SELECT 1 FROM @Prev p WHERE p.UserID = @OwnerUserID) THEN 1 ELSE 0 END AS WasActive) AS s
            ON t.EventID = s.EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, RelationType = N'PARTICIPANT', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
                     /* تازه یا دوباره اضافه‌شده (در فهرستِ فعالِ قبلی نبود: s.WasActive = 0) → دعوتِ تازه؛ بقیه دست نمی‌خورند */
                     , ResponseStatus = CASE WHEN s.WasActive = 1 THEN t.ResponseStatus ELSE N'PENDING' END
                     , ResponseDate = CASE WHEN s.WasActive = 1 THEN t.ResponseDate ELSE NULL END
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt ELSE SYSDATETIME() END
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (
                SELECT CAST(LTRIM(RTRIM(value)) AS INT) AS UserID,
                       CASE WHEN EXISTS (SELECT 1 FROM @Prev p WHERE p.UserID = CAST(LTRIM(RTRIM(value)) AS INT)) THEN 1 ELSE 0 END AS WasActive
                FROM STRING_SPLIT(@AttendeeUserIDs, ',')
                WHERE LTRIM(RTRIM(value)) <> '' AND CAST(LTRIM(RTRIM(value)) AS INT) NOT IN (@Creator)
            ) AS s
            ON t.EventID = @EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
                     /* تازه یا دوباره اضافه‌شده (در فهرستِ فعالِ قبلی نبود: s.WasActive = 0) → دعوتِ تازه؛ بقیه دست نمی‌خورند */
                     , ResponseStatus = CASE WHEN s.WasActive = 1 THEN t.ResponseStatus ELSE N'PENDING' END
                     , ResponseDate = CASE WHEN s.WasActive = 1 THEN t.ResponseDate ELSE NULL END
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt ELSE SYSDATETIME() END
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, s.UserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        -- پاکسازی یادآوری کاربرانی که دیگر عضو رویداد نیستند
        UPDATE rem SET IsActive = 0
        FROM dbo.Reminders rem
        WHERE rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = @EventID AND rem.IsActive = 1
          AND NOT EXISTS (
              SELECT 1 FROM dbo.CalendarEventAttendees a
              WHERE a.EventID = @EventID AND a.UserID = rem.UserID AND a.IsActive = 1
          )
          AND rem.UserID NOT IN (@Creator, @OwnerUserID);

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
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
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان فعال نیست.' AS Message, 0 AS NotificationsQueued, 0 AS RemindersQueued, 0 AS InvitesQueued; RETURN; END

    DECLARE @Stale DATETIME2(0) = CASE WHEN @MaxAgeMinutes IS NULL THEN NULL ELSE DATEADD(MINUTE, -@MaxAgeMinutes, @Now) END;
    DECLARE @NoVersion DATETIME2(0) = '19000101';
    DECLARE @N INT = 0, @R INT = 0, @I INT = 0;

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
        -- اعلانِ خوانده‌نشدهٔ قبلی برایِ همین پیام هست → اعلانِ جداگانه نمی‌فرستیم
        WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications U2
                     WHERE U2.UserID = UN.UserID AND U2.MessageID = UN.MessageID AND U2.IsRead = 0 AND U2.UserNotificationID < UN.UserNotificationID) THEN 'SKIPPED_COALESCED'
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
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = R.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE R.IsActive = 1 AND R.IsSent = 0
      AND R.RemindAt <= @Now AND R.RemindAt >= @ActivatedAt
      AND (R.EntityType <> N'CALENDAR_EVENT' OR (E.EventID IS NOT NULL AND E.Status <> N'CANCELLED')) /* رویدادِ لغوشده یادآوری ندارد */
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
           'inv-' + CAST(A.CalendarEventAttendeeID AS VARCHAR(20)) + '-' + FORMAT(A.InvitedAt, 'yyMMddHHmmss'),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status <> N'CANCELLED'
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

    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued, @I AS InvitesQueued;
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
        /* رویدادِ یادآوری در این فاصله حذف یا «لغو» شده → ارسال نمی‌شود */
        IF EXISTS (SELECT 1 FROM dbo.Reminders R WHERE R.ReminderID = @SourceID AND R.EntityType = N'CALENDAR_EVENT'
                   AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents E WHERE E.EventID = R.EntityID AND E.IsActive = 1 AND E.Status <> N'CANCELLED'))
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
                       INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status <> N'CANCELLED'
                       WHERE A.CalendarEventAttendeeID = @SourceID
                         AND (E.RecurrenceType = N'NONE' AND E.EndDateTime >= SYSDATETIME() OR E.RecurrenceType <> N'NONE' AND (E.RecurrenceEndDate IS NULL OR E.RecurrenceEndDate >= CAST(SYSDATETIME() AS DATE)))
                         AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
          AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW')))
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    ELSE IF @Type = 'BROADCAST'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.PushBroadcastRecipients WHERE PushBroadcastRecipientID = @SourceID AND UserID = @UserID)
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    SELECT 'OK' AS Validity;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_Cleanup
/* پاک‌سازیِ سوابقِ قدیمیِ پایان‌یافته. رکوردِ در انتظار/در حالِ ارسال هرگز حذف نمی‌شود؛ اعلانِ پیام فقط زیرِ کفِ اسکن حذف می‌شود
   (وگرنه دوباره صف می‌شد) و یادآوری فقط وقتی همان نوبت دیگر در انتظار نیست. */
ALTER PROCEDURE dbo.sp_Push_Cleanup
    @RetentionDays INT = 30
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @RetentionDays IS NULL OR @RetentionDays < 1 SET @RetentionDays = 30;
    DECLARE @Cutoff DATETIME2(0) = DATEADD(DAY, -@RetentionDays, SYSDATETIME());
    DECLARE @ScanFrom BIGINT = (SELECT NotificationScanFromID FROM dbo.PushState WHERE PushStateID = 1);
    DECLARE @Ids TABLE (PushOutboxID BIGINT PRIMARY KEY);

    INSERT INTO @Ids (PushOutboxID)
    SELECT O.PushOutboxID FROM dbo.PushOutbox O
    WHERE O.Status NOT IN ('PENDING', 'SENDING') AND O.CompletedAt < @Cutoff
      AND (   (O.SourceType = 'NOTIFICATION' AND O.SourceID <= ISNULL(@ScanFrom, 0))
           OR (O.SourceType = 'REMINDER' AND NOT EXISTS (SELECT 1 FROM dbo.Reminders R
                    WHERE R.ReminderID = O.SourceID AND R.IsActive = 1 AND R.IsSent = 0 AND R.RemindAt = O.SourceVersion))
           OR  O.SourceType = 'TEST'
           OR (O.SourceType = 'INVITE' AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees A
                    WHERE A.CalendarEventAttendeeID = O.SourceID AND A.IsActive = 1 AND A.ResponseStatus = N'PENDING' AND A.InvitedAt = O.SourceVersion)));

    BEGIN TRAN;
    DELETE D FROM dbo.PushDeliveries D JOIN @Ids I ON I.PushOutboxID = D.PushOutboxID;
    DELETE O FROM dbo.PushOutbox O JOIN @Ids I ON I.PushOutboxID = O.PushOutboxID;
    COMMIT;
    SELECT 1 AS Success, N'پاک‌سازی انجام شد.' AS Message, (SELECT COUNT(*) FROM @Ids) AS Removed;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetPendingCalendarInvitations
/* دعوت‌هایِ در انتظارِ پاسخِ یک کاربر — فقط رویدادِ فعال، غیرلغو و تمام‌نشده و فقط برایِ کاربرِ فعالِ دارایِ CALENDAR_VIEW.
   InvitedAt = نسخهٔ دعوت (برایِ نمایشِ یک‌بارهٔ اعلانِ داخلِ برنامه). */
CREATE PROCEDURE dbo.sp_GetPendingCalendarInvitations
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    SELECT A.CalendarEventAttendeeID, A.EventID, A.RelationType, ISNULL(A.InvitedAt, A.Date_InsertFirst) AS InvitedAt,
           E.Title, E.StartDateTime, E.EndDateTime, E.IsAllDay, E.Color, E.Status, E.RecurrenceType, E.RecurrenceInterval, E.RecurrenceEndDate,
           E.CreatedByUserID, dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS OrganizerName,
           E.OwnerUserID, dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status <> N'CANCELLED'
    LEFT JOIN dbo.Users cu ON cu.UserID = E.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = E.OwnerUserID
    WHERE A.UserID = @UserID AND A.IsActive = 1 AND A.ResponseStatus = N'PENDING' AND A.RelationType <> N'ORGANIZER'
      AND A.UserID <> E.CreatedByUserID
      AND (E.RecurrenceType = N'NONE' AND E.EndDateTime >= @Now OR E.RecurrenceType <> N'NONE' AND (E.RecurrenceEndDate IS NULL OR E.RecurrenceEndDate >= CAST(@Now AS DATE)))
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
          AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW'))
    ORDER BY E.StartDateTime;
END
GO

