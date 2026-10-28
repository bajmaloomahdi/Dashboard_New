/* ==========================================================================
   پچ خودکار شماره: 132 | نام: notification_snooze
   تاریخ: 2026-10-28 20:31:11 | شامل 9 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: NotificationSnoozes
CREATE TABLE dbo.NotificationSnoozes (
    SnoozeID         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_NotificationSnoozes PRIMARY KEY,
    UserID           INT           NOT NULL CONSTRAINT FK_NotificationSnoozes_User REFERENCES dbo.Users (UserID),
    SourceKind       VARCHAR(20)   NOT NULL CONSTRAINT CK_NotificationSnoozes_Kind CHECK (SourceKind IN ('MESSAGE', 'INVITE', 'CANCEL', 'CALEVENT', 'BROADCAST')),
    SourceID         BIGINT        NOT NULL,
    SnoozedUntil     DATETIME2(0)  NOT NULL,
    ShownAt          DATETIME2(0)  NULL,   -- نمایشِ دوباره در برنامه (پس از پایانِ تعویق)
    IsActive         BIT           NOT NULL CONSTRAINT DF_NotificationSnoozes_IsActive DEFAULT (1),
    Date_InsertFirst DATETIME2(0)  NOT NULL CONSTRAINT DF_NotificationSnoozes_Date_InsertFirst DEFAULT (SYSDATETIME()),
    Date_LastUpdate  DATETIME2(0)  NULL,
    CONSTRAINT UQ_NotificationSnoozes_Source UNIQUE (UserID, SourceKind, SourceID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_NotificationSnoozes_Due
CREATE NONCLUSTERED INDEX IX_NotificationSnoozes_Due ON dbo.NotificationSnoozes (IsActive, SnoozedUntil) INCLUDE (UserID, ShownAt)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Notification_Snooze
-- پچِ ۱۳۲: ثبتِ تعویق (۵ تا ۶۰ دقیقه، گامِ ۵) — فقط گیرندهٔ همان اعلان. منبع یا مستقیم (Kind + SourceID از اعلانِ داخلِ برنامه)
-- یا از ردیفِ Pushِ همین کاربر (دکمهٔ «… دقیقه بعد» رویِ اعلانِ گوشی).
CREATE PROCEDURE dbo.sp_Notification_Snooze
    @UserID       INT,
    @Minutes      INT,
    @SourceKind   VARCHAR(20) = NULL,
    @SourceID     BIGINT = NULL,
    @PushOutboxID BIGINT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @Minutes IS NULL OR @Minutes < 5 OR @Minutes > 60 OR @Minutes % 5 <> 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'زمانِ تعویق باید ۵ تا ۶۰ دقیقه (مضربِ ۵) باشد.' AS Message; RETURN; END

    IF @PushOutboxID IS NOT NULL
    BEGIN
        DECLARE @Type VARCHAR(20), @Src BIGINT;
        SELECT @Type = SourceType, @Src = SourceID FROM dbo.PushOutbox WHERE PushOutboxID = @PushOutboxID AND UserID = @UserID;
        SELECT @SourceKind = NULL, @SourceID = NULL;
        IF @Type = 'NOTIFICATION'
            SELECT @SourceKind = 'MESSAGE', @SourceID = MessageID FROM dbo.UserNotifications WHERE UserNotificationID = @Src AND UserID = @UserID;
        ELSE IF @Type IN ('INVITE', 'CANCEL', 'BROADCAST')
            SELECT @SourceKind = @Type, @SourceID = @Src;
        ELSE IF @Type = 'CALEVENT'
            SELECT @SourceKind = 'CALEVENT', @SourceID = EventID FROM dbo.CalendarEventNotices WHERE NoticeID = @Src AND UserID = @UserID;
        ELSE IF @Type = 'SNOOZE'
            SELECT @SourceKind = SourceKind, @SourceID = SourceID FROM dbo.NotificationSnoozes WHERE SnoozeID = @Src AND UserID = @UserID;
    END

    -- دسترسی: کاربر باید گیرندهٔ همان اعلان باشد (و برایِ تقویم مجوزِ مشاهده داشته باشد)
    DECLARE @Ok BIT = 0;
    IF @SourceKind = 'MESSAGE' AND EXISTS (SELECT 1 FROM dbo.UserNotifications WHERE UserID = @UserID AND MessageID = @SourceID)
        SET @Ok = 1;
    ELSE IF @SourceKind IN ('INVITE', 'CANCEL') AND EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees WHERE CalendarEventAttendeeID = @SourceID AND UserID = @UserID)
         AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
        SET @Ok = 1;
    ELSE IF @SourceKind = 'CALEVENT' AND EXISTS (SELECT 1 FROM dbo.CalendarEventNotices WHERE EventID = @SourceID AND UserID = @UserID)
         AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
        SET @Ok = 1;
    ELSE IF @SourceKind = 'BROADCAST' AND EXISTS (SELECT 1 FROM dbo.PushBroadcastRecipients WHERE PushBroadcastRecipientID = @SourceID AND UserID = @UserID)
        SET @Ok = 1;
    IF @Ok = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'اعلان یافت نشد یا به آن دسترسی ندارید.' AS Message; RETURN; END

    DECLARE @Until DATETIME2(0) = DATEADD(MINUTE, @Minutes, CAST(SYSDATETIME() AS DATETIME2(0)));
    DECLARE @Id INT;
    BEGIN TRAN;
    UPDATE dbo.NotificationSnoozes WITH (UPDLOCK, SERIALIZABLE)
    SET @Id = SnoozeID, SnoozedUntil = @Until, ShownAt = NULL, IsActive = 1, Date_LastUpdate = SYSDATETIME()
    WHERE UserID = @UserID AND SourceKind = @SourceKind AND SourceID = @SourceID;
    IF @@ROWCOUNT = 0
    BEGIN
        INSERT INTO dbo.NotificationSnoozes (UserID, SourceKind, SourceID, SnoozedUntil) VALUES (@UserID, @SourceKind, @SourceID, @Until);
        SET @Id = SCOPE_IDENTITY();
    END
    COMMIT;
    SELECT CAST(1 AS BIT) AS Success, N'این اعلان ' + CAST(@Minutes AS NVARCHAR(5)) + N' دقیقهٔ دیگر دوباره یادآوری می‌شود.' AS Message,
           @Id AS SnoozeID, @SourceKind AS SourceKind, @SourceID AS SourceID, CONVERT(VARCHAR(19), @Until, 120) AS SnoozedUntil;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Notification_GetDueSnoozes
-- پچِ ۱۳۲: تعویق‌هایِ سررسیدشدهٔ کاربر برایِ نمایشِ دوباره در برنامه — عنوان از خودِ منبع و فقط اگر هنوز به آن دسترسی دارد
CREATE PROCEDURE dbo.sp_Notification_GetDueSnoozes
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Cal BIT = CASE WHEN EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW')) THEN 1 ELSE 0 END;
    SELECT Z.SnoozeID, Z.SourceKind, Z.SourceID, CONVERT(VARCHAR(19), Z.SnoozedUntil, 120) AS SnoozedUntil,
           T.Title, T.Subtitle, CONVERT(VARCHAR(19), T.StartDateTime, 120) AS StartDateTime, T.TargetUrl
    FROM dbo.NotificationSnoozes Z
    CROSS APPLY (
        SELECT M.Subject AS Title,
               CONCAT(MT.MessageTypeName, CASE WHEN M.MessageNumber IS NOT NULL THEN N' — شمارهٔ ' + M.MessageNumber END) AS Subtitle,
               CAST(NULL AS DATETIME2(0)) AS StartDateTime, N'/messages/' + CAST(M.MessageID AS NVARCHAR(20)) AS TargetUrl
        FROM dbo.Messages M
        INNER JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
        WHERE Z.SourceKind = 'MESSAGE' AND M.MessageID = Z.SourceID
          AND EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = Z.UserID AND UN.MessageID = M.MessageID)
        UNION ALL
        SELECT E.Title, CASE Z.SourceKind WHEN 'INVITE' THEN N'دعوت به جلسه' ELSE N'لغو یا حذفِ جلسه' END, E.StartDateTime,
               CASE Z.SourceKind WHEN 'INVITE' THEN N'/calendar?invites=1' ELSE N'/calendar' END
        FROM dbo.CalendarEventAttendees A
        INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID
        WHERE Z.SourceKind IN ('INVITE', 'CANCEL') AND @Cal = 1 AND A.CalendarEventAttendeeID = Z.SourceID AND A.UserID = Z.UserID
        UNION ALL
        SELECT E.Title, N'توضیح یا تغییر رویِ رویداد', E.StartDateTime, N'/calendar'
        FROM dbo.CalendarEvents E
        WHERE Z.SourceKind = 'CALEVENT' AND @Cal = 1 AND E.EventID = Z.SourceID AND E.IsActive = 1
        UNION ALL
        SELECT B.Title, LEFT(B.Body, 200), CAST(NULL AS DATETIME2(0)), N'/dashboard'
        FROM dbo.PushBroadcastRecipients R
        INNER JOIN dbo.PushBroadcasts B ON B.PushBroadcastID = R.PushBroadcastID
        WHERE Z.SourceKind = 'BROADCAST' AND R.PushBroadcastRecipientID = Z.SourceID AND R.UserID = Z.UserID
    ) T
    WHERE Z.UserID = @UserID AND Z.IsActive = 1 AND Z.ShownAt IS NULL AND Z.SnoozedUntil <= SYSDATETIME()
    ORDER BY Z.SnoozedUntil, Z.SnoozeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Notification_MarkSnoozeShown
-- پچِ ۱۳۲: تعویقِ سررسیدشده در برنامه نمایش داده شد (زبانه‌ها/دستگاه‌هایِ دیگر دوباره نشانش نمی‌دهند؛ Push جدا ارسال می‌شود)
CREATE PROCEDURE dbo.sp_Notification_MarkSnoozeShown
    @SnoozeID INT,
    @UserID   INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.NotificationSnoozes SET ShownAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME()
    WHERE SnoozeID = @SnoozeID AND UserID = @UserID AND ShownAt IS NULL AND SnoozedUntil <= SYSDATETIME();
    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Success;
END
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_SourceType
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox ADD CONSTRAINT CK_PushOutbox_SourceType CHECK ([SourceType]='CANCEL' OR [SourceType]='INVITE' OR [SourceType]='BROADCAST' OR [SourceType]='TEST' OR [SourceType]='REMINDER' OR [SourceType]='NOTIFICATION' OR [SourceType]='CALEVENT' OR [SourceType]='SNOOZE')
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
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان فعال نیست.' AS Message, 0 AS NotificationsQueued, 0 AS RemindersQueued, 0 AS InvitesQueued, 0 AS CancelsQueued, 0 AS EventNoticesQueued, 0 AS SnoozesQueued; RETURN; END

    DECLARE @Stale DATETIME2(0) = CASE WHEN @MaxAgeMinutes IS NULL THEN NULL ELSE DATEADD(MINUTE, -@MaxAgeMinutes, @Now) END;
    DECLARE @NoVersion DATETIME2(0) = '19000101';
    DECLARE @N INT = 0, @R INT = 0, @I INT = 0, @C INT = 0, @E INT = 0, @Z INT = 0;

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
    SELECT 'REMINDER', R.ReminderID, ISNULL(R.SnoozedUntil, R.RemindAt), R.UserID, ISNULL(R.SnoozedUntil, R.RemindAt), 'REMINDER', N'/calendar', /* پچِ ۱۳۱: تعویق */
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
      AND ISNULL(R.SnoozedUntil, R.RemindAt) <= @Now AND R.RemindAt >= @ActivatedAt
      AND (R.EntityType <> N'CALENDAR_EVENT' OR (E.EventID IS NOT NULL AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */)) /* رویدادِ لغوشده یادآوری ندارد */
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = R.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(R.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE R.EntityType = N'CALENDAR_EVENT' AND RA.EventID = R.EntityID
                      AND RA.UserID = R.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'REMINDER' AND O.SourceID = R.ReminderID AND O.SourceVersion = ISNULL(R.SnoozedUntil, R.RemindAt));
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

    /* پچِ ۱۳۲ — «بعداً یادآوری کن» رویِ نامه/دعوت/لغو/فعالیتِ رویداد/اعلانِ عمومی: هر پایانِ تعویق یک‌بار (تعویقِ دوباره = نسخهٔ تازه) */
    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    SELECT 'SNOOZE', Z.SnoozeID, Z.SnoozedUntil, Z.UserID, Z.SnoozedUntil, K.Kind, K.Url, 'snz-' + CAST(Z.SnoozeID AS VARCHAR(20)),
           S.Status, CASE WHEN S.Status = 'PENDING' THEN NULL ELSE @Now END
    FROM dbo.NotificationSnoozes Z
    LEFT JOIN dbo.Messages M ON Z.SourceKind = 'MESSAGE' AND M.MessageID = Z.SourceID
    LEFT JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
    CROSS APPLY (SELECT CASE WHEN Z.SourceKind = 'MESSAGE' THEN CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 'TASK' ELSE 'MESSAGE' END ELSE Z.SourceKind END AS Kind,
                        CASE Z.SourceKind WHEN 'MESSAGE' THEN N'/messages/' + CAST(Z.SourceID AS NVARCHAR(20)) WHEN 'INVITE' THEN N'/calendar?invites=1'
                                          WHEN 'BROADCAST' THEN N'/dashboard' ELSE N'/calendar' END AS Url) K
    CROSS APPLY (SELECT CASE
        WHEN @Stale IS NOT NULL AND Z.SnoozedUntil < @Stale THEN 'SKIPPED_STALE'
        WHEN NOT EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = Z.UserID AND PS.IsActive = 1) THEN 'SKIPPED_NO_SUBSCRIPTION'
        ELSE 'PENDING' END AS Status) S
    WHERE Z.IsActive = 1 AND Z.SnoozedUntil <= @Now AND Z.SnoozedUntil >= @ActivatedAt
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = Z.UserID AND AU.IsActive = 1)
      AND NOT EXISTS (SELECT 1 FROM dbo.PushOutbox O
                      WHERE O.SourceType = 'SNOOZE' AND O.SourceID = Z.SnoozeID AND O.SourceVersion = Z.SnoozedUntil);
    SET @Z = @@ROWCOUNT;
    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued, @I AS InvitesQueued, @C AS CancelsQueued, @E AS EventNoticesQueued, @Z AS SnoozesQueued;
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
        IF NOT EXISTS (SELECT 1 FROM dbo.Reminders WHERE ReminderID = @SourceID AND IsActive = 1 AND IsSent = 0 AND ISNULL(SnoozedUntil, RemindAt) = @Version) /* پچِ ۱۳۱ */
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
    ELSE IF @Type = 'SNOOZE'
    BEGIN
        /* پچِ ۱۳۲ — دوباره تعویق شده (نسخهٔ تازه‌تر) یا گیرنده در این فاصله دسترسی‌اش را از دست داده → این نسخه ارسال نمی‌شود */
        DECLARE @ZKind VARCHAR(20), @ZSrc BIGINT;
        SELECT @ZKind = SourceKind, @ZSrc = SourceID FROM dbo.NotificationSnoozes
        WHERE SnoozeID = @SourceID AND UserID = @UserID AND IsActive = 1 AND SnoozedUntil = @Version;
        IF @ZKind IS NULL
        BEGIN SELECT 'SKIPPED_ACKNOWLEDGED' AS Validity; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR (@ZKind = 'MESSAGE' AND NOT EXISTS (SELECT 1 FROM dbo.UserNotifications WHERE UserID = @UserID AND MessageID = @ZSrc))
           OR (@ZKind IN ('INVITE', 'CANCEL', 'CALEVENT') AND NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW')))
           OR (@ZKind = 'CALEVENT' AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @ZSrc AND IsActive = 1))
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

