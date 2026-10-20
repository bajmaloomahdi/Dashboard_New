/* ==========================================================================
   پچ خودکار شماره: 108 | نام: calendar_reminder_access
   تاریخ: 2026-10-19 08:04:01 | شامل 4 دستور SQL
   ========================================================================== */

-- [CREATE_FUNCTION] روی FUNCTION: fn_UserHasPermission
/* آیا کاربر این مجوز را دارد؟ دقیقاً همان منطقِ sp_CheckUserPermission، ولی قابلِ استفاده داخلِ کوئری‌هایِ مجموعه‌ای
   (رویه را نمی‌شود داخلِ WHERE صدا زد). یک ردیف = دارد؛ هیچ ردیف = ندارد. */
CREATE FUNCTION dbo.fn_UserHasPermission (@UserID INT, @PermissionCode NVARCHAR(100))
RETURNS TABLE
AS
RETURN
    SELECT TOP (1) 1 AS HasPermission
    FROM dbo.RolePermissions rp
    INNER JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
    INNER JOIN dbo.UserRoles ur ON ur.RoleID = rp.RoleID
    INNER JOIN dbo.Roles r ON r.RoleID = ur.RoleID
    WHERE ur.UserID = @UserID
      AND p.PermissionCode = @PermissionCode
      AND p.IsActive = 1
      AND rp.IsActive = 1
      AND rp.CanAccess = 1
      AND ur.IsActive = 1
      AND r.IsActive = 1;
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
        r.RemindAt
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND r.RemindAt <= SYSDATETIME()
      AND (r.EntityType <> N'CALENDAR_EVENT' OR (e.EventID IS NOT NULL AND e.Status <> N'CANCELLED')) /* رویدادِ لغوشده یادآوری ندارد */
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = r.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(r.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE r.EntityType = N'CALENDAR_EVENT' AND RA.EventID = r.EntityID
                      AND RA.UserID = r.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */;
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
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان فعال نیست.' AS Message, 0 AS NotificationsQueued, 0 AS RemindersQueued; RETURN; END

    DECLARE @Stale DATETIME2(0) = CASE WHEN @MaxAgeMinutes IS NULL THEN NULL ELSE DATEADD(MINUTE, -@MaxAgeMinutes, @Now) END;
    DECLARE @NoVersion DATETIME2(0) = '19000101';
    DECLARE @N INT = 0, @R INT = 0;

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

    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued;
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
    ELSE IF @Type = 'BROADCAST'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.PushBroadcastRecipients WHERE PushBroadcastRecipientID = @SourceID AND UserID = @UserID)
        BEGIN SELECT 'SKIPPED_INVALID' AS Validity; RETURN; END
    END
    SELECT 'OK' AS Validity;
END
GO

