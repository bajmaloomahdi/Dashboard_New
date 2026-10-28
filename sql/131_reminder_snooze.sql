/* ==========================================================================
   پچ خودکار شماره: 131 | نام: reminder_snooze
   تاریخ: 2026-10-28 18:45:34 | شامل 9 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: Reminders
ALTER TABLE dbo.Reminders ADD SnoozedUntil DATETIME2(0) NULL
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_SnoozeReminder
-- پچِ ۱۳۱: «چند دقیقهٔ دیگر دوباره یادآوری کن» — فقط صاحبِ یادآوری؛ ۵ تا ۶۰ دقیقه با گامِ ۵.
-- RemindAt (لنگرِ وقوع و شمارشِ انقضا) ثابت می‌ماند؛ تا SnoozedUntil نه در برنامه نمایش داده می‌شود نه Push.
CREATE PROCEDURE dbo.sp_Calendar_SnoozeReminder
    @ReminderID BIGINT,
    @UserID     INT,
    @Minutes    INT
AS
BEGIN
    SET NOCOUNT ON;
    IF @Minutes IS NULL OR @Minutes < 5 OR @Minutes > 60 OR @Minutes % 5 <> 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'زمانِ تعویق باید ۵ تا ۶۰ دقیقه (مضربِ ۵) باشد.' AS Message; RETURN; END

    DECLARE @Until DATETIME2(0) = DATEADD(MINUTE, @Minutes, CAST(SYSDATETIME() AS DATETIME2(0)));
    UPDATE dbo.Reminders
    SET SnoozedUntil = @Until, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ReminderID = @ReminderID AND UserID = @UserID AND IsActive = 1 AND IsSent = 0;

    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'یادآوری یافت نشد یا قبلاً تأیید شده است.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'یادآوری ' + CAST(@Minutes AS NVARCHAR(5)) + N' دقیقهٔ دیگر دوباره نمایش داده می‌شود.' AS Message,
           CONVERT(VARCHAR(19), @Until, 120) AS SnoozedUntil;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
ALTER PROCEDURE [dbo].[sp_GetDueCalendarReminders]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID AS EventID,
        ISNULL(r.Title, e.Title) AS Title,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.RecurrenceType,
        r.OffsetMinutes,
        r.RemindAt,
        CONVERT(VARCHAR(19), r.SnoozedUntil, 120) AS SnoozedUntil -- پچِ ۱۳۱
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND ISNULL(r.SnoozedUntil, r.RemindAt) <= SYSDATETIME() /* پچِ ۱۳۱: تعویق */
      AND (r.EntityType <> N'CALENDAR_EVENT' OR (e.EventID IS NOT NULL AND e.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */)) /* رویدادِ لغوشده یادآوری ندارد */
      AND (r.EntityType <> N'CALENDAR_EVENT' OR DATEADD(SECOND, DATEDIFF(SECOND, e.StartDateTime, e.EndDateTime), DATEADD(MINUTE, ISNULL(r.OffsetMinutes, 0), r.RemindAt)) >= SYSDATETIME()) /* پچِ ۱۱۳: وقوعِ تمام‌شده = منقضی */
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = r.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(r.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE r.EntityType = N'CALENDAR_EVENT' AND RA.EventID = r.EntityID
                      AND RA.UserID = r.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_SaveReminder
ALTER PROCEDURE [dbo].[sp_SaveReminder]
    @EntityType          NVARCHAR(50),
    @EntityID            BIGINT,
    @UserID              INT,                 -- کاربری که یادآوری برای اوست
    @OffsetMinutes       INT = NULL,          -- NULL = حذف یادآوری
    @EntityStartDateTime DATETIME2(0),
    @CreatedByUserID     INT,                 -- کاربری که یادآوری را ایجاد/تنظیم می‌کند
    @Title               NVARCHAR(250) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @OffsetMinutes IS NULL
        BEGIN
            UPDATE dbo.Reminders SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @CreatedByUserID
            WHERE EntityType = @EntityType AND EntityID = @EntityID AND UserID = @UserID;
            SELECT CAST(1 AS BIT) AS Success, N'یادآوری حذف شد.' AS Message;
            RETURN;
        END

        DECLARE @RemindAt DATETIME2(0) = DATEADD(MINUTE, -1 * @OffsetMinutes, @EntityStartDateTime);

        MERGE dbo.Reminders AS target
        USING (SELECT @EntityType AS EntityType, @EntityID AS EntityID, @UserID AS UserID) AS src
        ON target.EntityType = src.EntityType AND target.EntityID = src.EntityID AND target.UserID = src.UserID
        WHEN MATCHED THEN UPDATE SET
            OffsetMinutes = @OffsetMinutes, RemindAt = @RemindAt, Title = @Title,
            IsSent = 0, SentDate = NULL, IsActive = 1, SnoozedUntil = NULL, /* پچِ ۱۳۱ */
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @CreatedByUserID
        WHEN NOT MATCHED THEN INSERT
            (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, Title, IsActive, CreatedByUserID, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@EntityType, @EntityID, @UserID, @OffsetMinutes, @RemindAt, @Title, 1, @CreatedByUserID, SYSDATETIME(), @CreatedByUserID);

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری ذخیره شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ذخیره یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_RescheduleRecurringReminder
-- ---------- جابه‌جایی زمان یادآوریِ رویدادِ تکرارشونده به رخدادِ بعدی ----------
-- (به‌جای IsSent=1، RemindAt به جلو منتقل می‌شود تا برای رخداد بعدی دوباره فعال شود)
ALTER PROCEDURE [dbo].[sp_RescheduleRecurringReminder]
    @ReminderID  BIGINT,
    @UserID      INT,
    @NewRemindAt DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Reminders
    SET RemindAt          = @NewRemindAt,
        IsSent            = 0,
        SnoozedUntil      = NULL, -- پچِ ۱۳۱
        SentDate          = SYSDATETIME(),   -- زمانِ آخرین نمایش، برای سابقه
        Date_LastUpdate   = SYSDATETIME(),
        UserID_LastUpdate = @UserID
    WHERE ReminderID = @ReminderID
      AND UserID = @UserID;

    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Success;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_MarkReminderSent
ALTER PROCEDURE [dbo].[sp_MarkReminderSent]
    @ReminderID BIGINT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Reminders
    SET IsSent = 1, SentDate = SYSDATETIME(), SnoozedUntil = NULL /* پچِ ۱۳۱ */, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ReminderID = @ReminderID
      AND UserID = @UserID;          -- فقط صاحبِ یادآوری می‌تواند آن را «دیده‌شده» کند

    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Success;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Calendar_RollReminder
/* پچِ ۱۱۳ — جلو بردنِ اتمیکِ یک یادآوری (compare-and-set): فقط اگر هنوز همان RemindAt، ارسال‌نشده و فعال است.
   زمان‌بند و درخواستِ داخلِ برنامه ممکن است هم‌زمان همان ردیف را ببینند؛ فقط یکی موفق می‌شود و اگر کاربر در این فاصله
   «دیدم» زده یا رویداد ویرایش شده (RemindAt عوض شده)، هیچ تغییری داده نمی‌شود. @NewRemindAt = NULL → سری تمام شده (بسته شود). */
ALTER PROCEDURE dbo.sp_Calendar_RollReminder
    @ReminderID       BIGINT,
    @ExpectedRemindAt DATETIME2(0),
    @NewRemindAt      DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.Reminders
    SET RemindAt = ISNULL(@NewRemindAt, RemindAt),
        IsSent = CASE WHEN @NewRemindAt IS NULL THEN 1 ELSE 0 END,
        SentDate = SYSDATETIME(),
        SnoozedUntil = NULL, -- پچِ ۱۳۱
        Date_LastUpdate = SYSDATETIME()
    WHERE ReminderID = @ReminderID AND RemindAt = @ExpectedRemindAt AND IsSent = 0 AND IsActive = 1;
    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Rolled;
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

