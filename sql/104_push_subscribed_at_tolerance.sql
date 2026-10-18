/* ==========================================================================
   پچ خودکار شماره: 104 | نام: push_subscribed_at_tolerance
   تاریخ: 2026-10-18 12:05:43 | شامل 2 دستور SQL
   ========================================================================== */

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
      AND (R.EntityType <> N'CALENDAR_EVENT' OR E.EventID IS NOT NULL)
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_GetTargets
/* دستگاه‌هایِ هدف: اشتراکِ فعالِ همان کاربر که قبل از رویداد ثبت شده (یادآوری/آزمایش: هر اشتراکِ فعال)
   و هنوز برایِ این Outbox «ارسال‌شده/منقضی/شکست‌خورده» نیست — دستگاهِ موفق دوباره ارسال نمی‌گیرد. */
ALTER PROCEDURE dbo.sp_Push_GetTargets
    @PushOutboxID BIGINT,
    @Worker       NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PS.PushSubscriptionID, PS.Endpoint, PS.P256dh, PS.AuthSecret, PS.ContentEncoding
    FROM dbo.PushOutbox O
    JOIN dbo.PushSubscriptions PS ON PS.UserID = O.UserID AND PS.IsActive = 1
    WHERE O.PushOutboxID = @PushOutboxID AND O.Status = 'SENDING' AND O.LockedBy = @Worker
      AND (O.SourceType IN ('REMINDER', 'TEST') OR PS.SubscribedAt <= DATEADD(SECOND, 1, O.EventAt)) /* SubscribedAt/EventAt ثانیه‌ای گرد می‌شوند */
      AND NOT EXISTS (SELECT 1 FROM dbo.PushDeliveries D
                      WHERE D.PushOutboxID = O.PushOutboxID AND D.PushSubscriptionID = PS.PushSubscriptionID
                        AND D.Status IN ('SENT', 'EXPIRED', 'FAILED'))
    ORDER BY PS.PushSubscriptionID;
END
GO

