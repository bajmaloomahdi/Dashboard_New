/* ==========================================================================
   پچ خودکار شماره: 103 | نام: push_notifications
   تاریخ: 2026-10-18 11:55:24 | شامل 24 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: PushState
CREATE TABLE dbo.PushState (
    PushStateID             TINYINT       NOT NULL CONSTRAINT PK_PushState PRIMARY KEY
                                          CONSTRAINT CK_PushState_Single CHECK (PushStateID = 1),
    IsEnabled               BIT           NOT NULL CONSTRAINT DF_PushState_IsEnabled DEFAULT (0),
    ActivatedAt             DATETIME2(0)  NULL,     -- مرزِ شروع: رویدادهایِ قبل از آن هرگز Push نمی‌شوند
    ActivatedByUserID       INT           NULL,
    NotificationWatermarkID BIGINT        NULL,     -- MAX(UserNotificationID) در لحظهٔ فعال‌سازی (ثابت)
    NotificationScanFromID  BIGINT        NULL,     -- کفِ اسکنِ صف‌بندی؛ فقط جلو می‌رود
    LastRunAt               DATETIME2(0)  NULL,     -- ضربانِ پردازشگرِ پس‌زمینه
    LastRunBy               NVARCHAR(100) NULL,
    Date_InsertFirst        DATETIME2(0)  NOT NULL CONSTRAINT DF_PushState_Date_InsertFirst DEFAULT (SYSDATETIME()),
    Date_LastUpdate         DATETIME2(0)  NULL
)
GO

-- [CREATE_TABLE] روی TABLE: PushSubscriptions
CREATE TABLE dbo.PushSubscriptions (
    PushSubscriptionID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PushSubscriptions PRIMARY KEY,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_PushSubscriptions_RowGuid DEFAULT (NEWID()),
    UserID              INT               NOT NULL CONSTRAINT FK_PushSubscriptions_User REFERENCES dbo.Users (UserID),
    Endpoint            NVARCHAR(2000)    NOT NULL,
    EndpointHash        AS CAST(HASHBYTES('SHA2_256', Endpoint) AS BINARY(32)) PERSISTED,
    P256dh              VARCHAR(200)      NOT NULL,
    AuthSecret          VARCHAR(100)      NOT NULL,
    ContentEncoding     VARCHAR(20)       NOT NULL CONSTRAINT DF_PushSubscriptions_ContentEncoding DEFAULT ('aes128gcm'),
    UserAgent           NVARCHAR(400)     NULL,
    IsActive            BIT               NOT NULL CONSTRAINT DF_PushSubscriptions_IsActive DEFAULT (1),
    SubscribedAt        DATETIME2(0)      NOT NULL CONSTRAINT DF_PushSubscriptions_SubscribedAt DEFAULT (SYSDATETIME()),
    FailureCount        INT               NOT NULL CONSTRAINT DF_PushSubscriptions_FailureCount DEFAULT (0),
    LastSuccessAt       DATETIME2(0)      NULL,
    LastFailureAt       DATETIME2(0)      NULL,
    LastError           NVARCHAR(500)     NULL,
    DeactivatedAt       DATETIME2(0)      NULL,
    DeactivationReason  NVARCHAR(50)      NULL,
    Date_InsertFirst    DATETIME2(0)      NOT NULL CONSTRAINT DF_PushSubscriptions_Date_InsertFirst DEFAULT (SYSDATETIME()),
    Date_LastUpdate     DATETIME2(0)      NULL,
    CONSTRAINT CK_PushSubscriptions_Https CHECK (Endpoint LIKE N'https://%')
)
GO

-- [CREATE_INDEX] روی INDEX: UX_PushSubscriptions_EndpointHash
CREATE UNIQUE NONCLUSTERED INDEX UX_PushSubscriptions_EndpointHash ON dbo.PushSubscriptions (EndpointHash)
GO

-- [CREATE_INDEX] روی INDEX: IX_PushSubscriptions_User
CREATE NONCLUSTERED INDEX IX_PushSubscriptions_User ON dbo.PushSubscriptions (UserID, IsActive) INCLUDE (SubscribedAt)
GO

-- [CREATE_TABLE] روی TABLE: PushOutbox
CREATE TABLE dbo.PushOutbox (
    PushOutboxID     BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PushOutbox PRIMARY KEY,
    SourceType       VARCHAR(20)    NOT NULL CONSTRAINT CK_PushOutbox_SourceType CHECK (SourceType IN ('NOTIFICATION', 'REMINDER', 'TEST')),
    SourceID         BIGINT         NOT NULL,   -- UserNotificationID / ReminderID / شمارهٔ آزمایشی
    SourceVersion    DATETIME2(0)   NOT NULL,   -- یادآوری: RemindAt (نوبتِ بعدیِ تکرارشونده = رکوردِ جدا)؛ بقیه: 1900-01-01
    UserID           INT            NOT NULL CONSTRAINT FK_PushOutbox_User REFERENCES dbo.Users (UserID),
    EventAt          DATETIME2(0)   NOT NULL,   -- زمانِ رویداد؛ دستگاهِ مشترک‌شده پس از آن، این رویداد را نمی‌گیرد
    Kind             VARCHAR(20)    NOT NULL CONSTRAINT CK_PushOutbox_Kind CHECK (Kind IN ('MESSAGE', 'TASK', 'REMINDER', 'TEST')),
    TargetUrl        NVARCHAR(400)  NOT NULL,   -- مسیرِ نسبی؛ دسترسی دوباره در سمتِ سرور بررسی می‌شود
    Tag              VARCHAR(64)    NOT NULL,   -- ثابت به‌ازایِ رویداد (کنترلِ تکرار در دستگاه + Topic)
    Status           VARCHAR(30)    NOT NULL CONSTRAINT DF_PushOutbox_Status DEFAULT ('PENDING'),
    Attempts         INT            NOT NULL CONSTRAINT DF_PushOutbox_Attempts DEFAULT (0),
    NextAttemptAt    DATETIME2(0)   NOT NULL CONSTRAINT DF_PushOutbox_NextAttemptAt DEFAULT (SYSDATETIME()),
    LockedBy         NVARCHAR(100)  NULL,
    LockedUntil      DATETIME2(0)   NULL,
    LastError        NVARCHAR(1000) NULL,
    SentAt           DATETIME2(0)   NULL,   -- پذیرش توسطِ سرویسِ Push (نه مشاهدهٔ کاربر)
    CompletedAt      DATETIME2(0)   NULL,
    Date_InsertFirst DATETIME2(0)   NOT NULL CONSTRAINT DF_PushOutbox_Date_InsertFirst DEFAULT (SYSDATETIME()),
    Date_LastUpdate  DATETIME2(0)   NULL,
    CONSTRAINT UQ_PushOutbox_Source UNIQUE (SourceType, SourceID, SourceVersion),
    CONSTRAINT CK_PushOutbox_Status CHECK (Status IN ('PENDING', 'SENDING', 'SENT', 'FAILED', 'SKIPPED_READ', 'SKIPPED_ACKNOWLEDGED',
                                                     'SKIPPED_NO_SUBSCRIPTION', 'SKIPPED_COALESCED', 'SKIPPED_STALE', 'SKIPPED_INVALID'))
)
GO

-- [CREATE_INDEX] روی INDEX: IX_PushOutbox_Claim
CREATE NONCLUSTERED INDEX IX_PushOutbox_Claim ON dbo.PushOutbox (Status, NextAttemptAt) INCLUDE (LockedUntil)
GO

-- [CREATE_TABLE] روی TABLE: PushDeliveries
CREATE TABLE dbo.PushDeliveries (
    PushDeliveryID     BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PushDeliveries PRIMARY KEY,
    PushOutboxID       BIGINT         NOT NULL CONSTRAINT FK_PushDeliveries_Outbox REFERENCES dbo.PushOutbox (PushOutboxID),
    PushSubscriptionID INT            NOT NULL CONSTRAINT FK_PushDeliveries_Subscription REFERENCES dbo.PushSubscriptions (PushSubscriptionID),
    Status             VARCHAR(20)    NOT NULL CONSTRAINT CK_PushDeliveries_Status CHECK (Status IN ('SENDING', 'SENT', 'RETRY', 'FAILED', 'EXPIRED')),
    Attempts           INT            NOT NULL CONSTRAINT DF_PushDeliveries_Attempts DEFAULT (0),
    HttpStatus         INT            NULL,
    LastError          NVARCHAR(1000) NULL,
    LastAttemptAt      DATETIME2(0)   NULL,
    SentAt             DATETIME2(0)   NULL,
    Date_InsertFirst   DATETIME2(0)   NOT NULL CONSTRAINT DF_PushDeliveries_Date_InsertFirst DEFAULT (SYSDATETIME()),
    Date_LastUpdate    DATETIME2(0)   NULL,
    CONSTRAINT UQ_PushDeliveries_Outbox_Subscription UNIQUE (PushOutboxID, PushSubscriptionID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_PushDeliveries_Subscription
CREATE NONCLUSTERED INDEX IX_PushDeliveries_Subscription ON dbo.PushDeliveries (PushSubscriptionID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_Activate
/* فعال/غیرفعال‌سازی. اولین فعال‌سازی مرزِ شروع را یک‌بار ثبت می‌کند (ActivatedAt + Watermark)؛ فراخوانیِ دوباره آن را عوض نمی‌کند،
   مگر @ResetBoundary = 1 (رویدادهایِ عقب‌افتادهٔ دورهٔ توقف کنار گذاشته می‌شوند). */
CREATE PROCEDURE dbo.sp_Push_Activate
    @Enable        BIT = 1,
    @UserID        INT = NULL,
    @ResetBoundary BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRAN;
    EXEC sp_getapplock @Resource = N'PushState', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 15000;

    IF NOT EXISTS (SELECT 1 FROM dbo.PushState WHERE PushStateID = 1)
        INSERT INTO dbo.PushState (PushStateID, IsEnabled) VALUES (1, 0);

    DECLARE @Watermark BIGINT = (SELECT ISNULL(MAX(UserNotificationID), 0) FROM dbo.UserNotifications);

    UPDATE dbo.PushState
    SET IsEnabled = @Enable,
        ActivatedAt = CASE WHEN ActivatedAt IS NULL OR @ResetBoundary = 1 THEN SYSDATETIME() ELSE ActivatedAt END,
        ActivatedByUserID = CASE WHEN ActivatedAt IS NULL OR @ResetBoundary = 1 THEN @UserID ELSE ActivatedByUserID END,
        NotificationWatermarkID = CASE WHEN ActivatedAt IS NULL OR @ResetBoundary = 1 THEN @Watermark ELSE NotificationWatermarkID END,
        NotificationScanFromID = CASE WHEN ActivatedAt IS NULL OR @ResetBoundary = 1 THEN @Watermark ELSE NotificationScanFromID END,
        Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1;

    COMMIT;
    SELECT 1 AS Success, CASE WHEN @Enable = 1 THEN N'ارسالِ اعلان فعال شد.' ELSE N'ارسالِ اعلان متوقف شد.' END AS Message,
           IsEnabled, ActivatedAt, NotificationWatermarkID, NotificationScanFromID, LastRunAt
    FROM dbo.PushState WHERE PushStateID = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetState
CREATE PROCEDURE dbo.sp_Push_GetState
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CAST(ISNULL(MAX(CAST(IsEnabled AS INT)), 0) AS BIT) AS IsEnabled, MAX(ActivatedAt) AS ActivatedAt,
           MAX(NotificationWatermarkID) AS NotificationWatermarkID, MAX(NotificationScanFromID) AS NotificationScanFromID,
           MAX(LastRunAt) AS LastRunAt, MAX(LastRunBy) AS LastRunBy
    FROM dbo.PushState WHERE PushStateID = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_Heartbeat
CREATE PROCEDURE dbo.sp_Push_Heartbeat
    @Worker NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.PushState SET LastRunAt = SYSDATETIME(), LastRunBy = @Worker WHERE PushStateID = 1;
    EXEC dbo.sp_Push_GetState;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_SaveSubscription
/* ثبت یا به‌روزرسانیِ اشتراکِ یک مرورگر/دستگاه. Endpoint یکتاست: اگر همان مرورگر با کاربرِ دیگری وارد شده باشد،
   اشتراک به کاربرِ فعلی منتقل می‌شود (Endpoint فقط در اختیارِ خودِ همان مرورگر است). اعتبارسنجیِ میزبان در PHP است. */
CREATE PROCEDURE dbo.sp_Push_SaveSubscription
    @UserID          INT,
    @Endpoint        NVARCHAR(2000),
    @P256dh          VARCHAR(200),
    @AuthSecret      VARCHAR(100),
    @ContentEncoding VARCHAR(20)   = 'aes128gcm',
    @UserAgent       NVARCHAR(400) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'کاربر معتبر نیست.' AS Message, NULL AS PushSubscriptionID; RETURN; END
    IF @Endpoint IS NULL OR @Endpoint NOT LIKE N'https://%' OR NULLIF(@P256dh, '') IS NULL OR NULLIF(@AuthSecret, '') IS NULL
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ اشتراک نامعتبر است.' AS Message, NULL AS PushSubscriptionID; RETURN; END

    DECLARE @Hash BINARY(32) = CAST(HASHBYTES('SHA2_256', @Endpoint) AS BINARY(32));
    DECLARE @ID INT;

    BEGIN TRAN;
    SELECT @ID = PushSubscriptionID FROM dbo.PushSubscriptions WITH (UPDLOCK, HOLDLOCK) WHERE EndpointHash = @Hash;
    IF @ID IS NULL
    BEGIN
        INSERT INTO dbo.PushSubscriptions (UserID, Endpoint, P256dh, AuthSecret, ContentEncoding, UserAgent)
        VALUES (@UserID, @Endpoint, @P256dh, @AuthSecret, ISNULL(NULLIF(@ContentEncoding, ''), 'aes128gcm'), @UserAgent);
        SET @ID = CAST(SCOPE_IDENTITY() AS INT);
    END
    ELSE
    BEGIN
        UPDATE dbo.PushSubscriptions
        SET SubscribedAt = CASE WHEN IsActive = 0 OR UserID <> @UserID THEN SYSDATETIME() ELSE SubscribedAt END,
            UserID = @UserID, P256dh = @P256dh, AuthSecret = @AuthSecret,
            ContentEncoding = ISNULL(NULLIF(@ContentEncoding, ''), 'aes128gcm'), UserAgent = @UserAgent,
            IsActive = 1, FailureCount = 0, LastError = NULL, DeactivatedAt = NULL, DeactivationReason = NULL,
            Date_LastUpdate = SYSDATETIME()
        WHERE PushSubscriptionID = @ID;
    END
    COMMIT;
    SELECT 1 AS Success, N'اعلان‌ها برایِ این دستگاه فعال شد.' AS Message, @ID AS PushSubscriptionID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_DeactivateSubscription
/* لغوِ اشتراک توسطِ خودِ کاربر (فقط اشتراکِ خودش) — با شناسه یا Endpoint. رکورد حذف نمی‌شود، فقط غیرفعال می‌شود. */
CREATE PROCEDURE dbo.sp_Push_DeactivateSubscription
    @UserID             INT,
    @PushSubscriptionID INT            = NULL,
    @Endpoint           NVARCHAR(2000) = NULL,
    @Reason             NVARCHAR(50)   = N'USER'
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.PushSubscriptions
    SET IsActive = 0, DeactivatedAt = SYSDATETIME(), DeactivationReason = @Reason, Date_LastUpdate = SYSDATETIME()
    WHERE UserID = @UserID
      AND ((@PushSubscriptionID IS NOT NULL AND PushSubscriptionID = @PushSubscriptionID)
        OR (@PushSubscriptionID IS NULL AND @Endpoint IS NOT NULL AND EndpointHash = CAST(HASHBYTES('SHA2_256', @Endpoint) AS BINARY(32))));
    IF @@ROWCOUNT = 0
    BEGIN SELECT 0 AS Success, N'اشتراک یافت نشد یا متعلق به شما نیست.' AS Message; RETURN; END
    SELECT 1 AS Success, N'اعلان‌ها برایِ این دستگاه غیرفعال شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetUserSubscriptions
/* فهرستِ دستگاه‌هایِ خودِ کاربر — Endpoint و کلیدها برگردانده نمی‌شوند؛ اگر @Endpoint داده شود، IsCurrent مشخص می‌شود. */
CREATE PROCEDURE dbo.sp_Push_GetUserSubscriptions
    @UserID   INT,
    @Endpoint NVARCHAR(2000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Hash BINARY(32) = CASE WHEN @Endpoint IS NULL THEN NULL ELSE CAST(HASHBYTES('SHA2_256', @Endpoint) AS BINARY(32)) END;
    SELECT PushSubscriptionID, UserAgent, IsActive, SubscribedAt, LastSuccessAt, LastFailureAt, FailureCount,
           DeactivatedAt, DeactivationReason,
           SUBSTRING(Endpoint, 9, CHARINDEX(N'/', Endpoint + N'/', 9) - 9) AS ServiceHost,
           CAST(CASE WHEN @Hash IS NOT NULL AND EndpointHash = @Hash THEN 1 ELSE 0 END AS BIT) AS IsCurrent
    FROM dbo.PushSubscriptions
    WHERE UserID = @UserID AND (IsActive = 1 OR (@Hash IS NOT NULL AND EndpointHash = @Hash))
    ORDER BY IsActive DESC, SubscribedAt DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_Enqueue
/* صف‌بندی (با قفلِ برنامه سریالی می‌شود؛ کلیدِ یکتایِ UQ_PushOutbox_Source مانعِ صف‌بندیِ تکراری است).
   پیام/وظیفه: UserNotificationID > کفِ اسکن (≥ Watermarkِ فعال‌سازی). یادآوری: سررسیدشده، IsSent = 0 و RemindAt ≥ ActivatedAt.
   @MaxAgeMinutes = NULL یعنی بدونِ محدودیتِ سن (تصمیمِ مرحلهٔ اول). */
CREATE PROCEDURE dbo.sp_Push_Enqueue
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
                         WHERE PS.UserID = UN.UserID AND PS.IsActive = 1 AND PS.SubscribedAt <= UN.CreateDate) THEN 'SKIPPED_NO_SUBSCRIPTION'
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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_EnqueueTest
/* اعلانِ آزمایشی برایِ دستگاه‌هایِ خودِ کاربر (برایِ تستِ واقعی روی دستگاه). */
CREATE PROCEDURE dbo.sp_Push_EnqueueTest
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    BEGIN TRAN;
    EXEC sp_getapplock @Resource = N'PushEnqueue', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 30000;
    DECLARE @Next BIGINT = (SELECT ISNULL(MAX(SourceID), 0) + 1 FROM dbo.PushOutbox WHERE SourceType = 'TEST');
    DECLARE @HasSub BIT = CASE WHEN EXISTS (SELECT 1 FROM dbo.PushSubscriptions WHERE UserID = @UserID AND IsActive = 1) THEN 1 ELSE 0 END;
    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status, CompletedAt)
    VALUES ('TEST', @Next, '19000101', @UserID, @Now, 'TEST', N'/dashboard', 'test-' + CAST(@Next AS VARCHAR(20)),
            CASE WHEN @HasSub = 1 THEN 'PENDING' ELSE 'SKIPPED_NO_SUBSCRIPTION' END, CASE WHEN @HasSub = 1 THEN NULL ELSE @Now END);
    DECLARE @ID BIGINT = SCOPE_IDENTITY();
    COMMIT;
    IF @HasSub = 0
    BEGIN SELECT 0 AS Success, N'هیچ دستگاهِ فعالی برایِ شما ثبت نشده است.' AS Message, @ID AS PushOutboxID; RETURN; END
    SELECT 1 AS Success, N'اعلانِ آزمایشی در صف قرار گرفت و تا حدودِ یک دقیقهٔ دیگر ارسال می‌شود.' AS Message, @ID AS PushOutboxID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_ClaimBatch
/* Claimِ اتمیک: UPDLOCK + READPAST — دو پردازشگر هرگز یک رکورد را با هم برنمی‌دارند؛ رکوردِ SENDING با Leaseِ منقضی دوباره برداشته می‌شود. */
CREATE PROCEDURE dbo.sp_Push_ClaimBatch
    @Worker       NVARCHAR(100),
    @BatchSize    INT = 50,
    @LeaseSeconds INT = 300
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    ;WITH C AS (
        SELECT TOP (@BatchSize) *
        FROM dbo.PushOutbox WITH (UPDLOCK, READPAST, ROWLOCK)
        WHERE (Status = 'PENDING' AND NextAttemptAt <= @Now)
           OR (Status = 'SENDING' AND LockedUntil < @Now)
        ORDER BY NextAttemptAt, PushOutboxID
    )
    UPDATE C
    SET Status = 'SENDING', LockedBy = @Worker, LockedUntil = DATEADD(SECOND, @LeaseSeconds, @Now),
        Attempts = Attempts + 1, Date_LastUpdate = @Now
    OUTPUT inserted.PushOutboxID, inserted.SourceType, inserted.SourceID, inserted.SourceVersion, inserted.UserID,
           inserted.EventAt, inserted.Kind, inserted.TargetUrl, inserted.Tag, inserted.Attempts;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_CheckOutbox
/* بررسیِ دوباره پیش از ارسال: پیامِ خوانده‌شده / یادآوریِ تأییدشده یا جابه‌جاشده دیگر ارسال نمی‌شود. */
CREATE PROCEDURE dbo.sp_Push_CheckOutbox
    @PushOutboxID BIGINT,
    @Worker       NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Type VARCHAR(20), @SourceID BIGINT, @Version DATETIME2(0);
    SELECT @Type = SourceType, @SourceID = SourceID, @Version = SourceVersion
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
    END
    SELECT 'OK' AS Validity;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetTargets
/* دستگاه‌هایِ هدف: اشتراکِ فعالِ همان کاربر که قبل از رویداد ثبت شده (یادآوری/آزمایش: هر اشتراکِ فعال)
   و هنوز برایِ این Outbox «ارسال‌شده/منقضی/شکست‌خورده» نیست — دستگاهِ موفق دوباره ارسال نمی‌گیرد. */
CREATE PROCEDURE dbo.sp_Push_GetTargets
    @PushOutboxID BIGINT,
    @Worker       NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PS.PushSubscriptionID, PS.Endpoint, PS.P256dh, PS.AuthSecret, PS.ContentEncoding
    FROM dbo.PushOutbox O
    JOIN dbo.PushSubscriptions PS ON PS.UserID = O.UserID AND PS.IsActive = 1
    WHERE O.PushOutboxID = @PushOutboxID AND O.Status = 'SENDING' AND O.LockedBy = @Worker
      AND (O.SourceType IN ('REMINDER', 'TEST') OR PS.SubscribedAt <= O.EventAt)
      AND NOT EXISTS (SELECT 1 FROM dbo.PushDeliveries D
                      WHERE D.PushOutboxID = O.PushOutboxID AND D.PushSubscriptionID = PS.PushSubscriptionID
                        AND D.Status IN ('SENT', 'EXPIRED', 'FAILED'))
    ORDER BY PS.PushSubscriptionID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_BeginDelivery
/* قبل از ارسال به یک دستگاه: ردیفِ ارسال «SENDING» ثبت (Commit) می‌شود. اگر پردازش بعد از پذیرش توسطِ سرویسِ Push و
   قبل از ثبتِ نتیجه متوقف شود، این ردیف SENDING می‌ماند و پس از انقضایِ Lease دوباره ارسال می‌شود (At-least-once). */
CREATE PROCEDURE dbo.sp_Push_BeginDelivery
    @PushOutboxID       BIGINT,
    @PushSubscriptionID INT,
    @Worker             NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.PushOutbox WHERE PushOutboxID = @PushOutboxID AND Status = 'SENDING' AND LockedBy = @Worker)
    BEGIN SELECT 0 AS Success, N'LEASE_LOST' AS Message; RETURN; END
    BEGIN TRAN;
    UPDATE dbo.PushDeliveries WITH (UPDLOCK, HOLDLOCK)
    SET Status = 'SENDING', Attempts = Attempts + 1, LastAttemptAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME()
    WHERE PushOutboxID = @PushOutboxID AND PushSubscriptionID = @PushSubscriptionID AND Status NOT IN ('SENT', 'EXPIRED', 'FAILED');
    IF @@ROWCOUNT = 0 AND NOT EXISTS (SELECT 1 FROM dbo.PushDeliveries WHERE PushOutboxID = @PushOutboxID AND PushSubscriptionID = @PushSubscriptionID)
        INSERT INTO dbo.PushDeliveries (PushOutboxID, PushSubscriptionID, Status, Attempts, LastAttemptAt)
        VALUES (@PushOutboxID, @PushSubscriptionID, 'SENDING', 1, SYSDATETIME());
    COMMIT;
    IF EXISTS (SELECT 1 FROM dbo.PushDeliveries WHERE PushOutboxID = @PushOutboxID AND PushSubscriptionID = @PushSubscriptionID AND Status = 'SENDING')
        SELECT 1 AS Success, N'OK' AS Message;
    ELSE
        SELECT 0 AS Success, N'ALREADY_FINAL' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_RecordDelivery
/* نتیجهٔ ارسال به یک دستگاه + وضعیتِ اشتراک (404/410 → غیرفعال؛ بقیهٔ دستگاه‌ها دست نمی‌خورند). */
CREATE PROCEDURE dbo.sp_Push_RecordDelivery
    @PushOutboxID       BIGINT,
    @PushSubscriptionID INT,
    @Worker             NVARCHAR(100),
    @Status             VARCHAR(20),   -- SENT / RETRY / FAILED / EXPIRED
    @HttpStatus         INT            = NULL,
    @Error              NVARCHAR(1000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @Status NOT IN ('SENT', 'RETRY', 'FAILED', 'EXPIRED')
    BEGIN SELECT 0 AS Success, N'وضعیتِ نامعتبر.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.PushOutbox WHERE PushOutboxID = @PushOutboxID AND Status = 'SENDING' AND LockedBy = @Worker)
    BEGIN SELECT 0 AS Success, N'LEASE_LOST' AS Message; RETURN; END

    BEGIN TRAN;
    UPDATE dbo.PushDeliveries
    SET Status = @Status, HttpStatus = @HttpStatus, LastError = LEFT(@Error, 1000),
        SentAt = CASE WHEN @Status = 'SENT' THEN SYSDATETIME() ELSE SentAt END, Date_LastUpdate = SYSDATETIME()
    WHERE PushOutboxID = @PushOutboxID AND PushSubscriptionID = @PushSubscriptionID;

    UPDATE dbo.PushSubscriptions
    SET LastSuccessAt = CASE WHEN @Status = 'SENT' THEN SYSDATETIME() ELSE LastSuccessAt END,
        FailureCount = CASE WHEN @Status = 'SENT' THEN 0 ELSE FailureCount + 1 END,
        LastFailureAt = CASE WHEN @Status = 'SENT' THEN LastFailureAt ELSE SYSDATETIME() END,
        LastError = CASE WHEN @Status = 'SENT' THEN NULL ELSE LEFT(@Error, 500) END,
        IsActive = CASE WHEN @Status = 'EXPIRED' THEN 0 ELSE IsActive END,
        DeactivatedAt = CASE WHEN @Status = 'EXPIRED' THEN SYSDATETIME() ELSE DeactivatedAt END,
        DeactivationReason = CASE WHEN @Status = 'EXPIRED' THEN N'EXPIRED' ELSE DeactivationReason END,
        Date_LastUpdate = SYSDATETIME()
    WHERE PushSubscriptionID = @PushSubscriptionID;
    COMMIT;
    SELECT 1 AS Success, N'OK' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_DisableSubscription
/* غیرفعال‌سازیِ سیستمیِ یک اشتراک (مثلاً Endpointی که دیگر در فهرستِ میزبان‌هایِ مجاز نیست). */
CREATE PROCEDURE dbo.sp_Push_DisableSubscription
    @PushSubscriptionID INT,
    @Reason             NVARCHAR(50)
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.PushSubscriptions
    SET IsActive = 0, DeactivatedAt = SYSDATETIME(), DeactivationReason = @Reason, Date_LastUpdate = SYSDATETIME()
    WHERE PushSubscriptionID = @PushSubscriptionID AND IsActive = 1;
    SELECT 1 AS Success, N'OK' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_CompleteOutbox
/* پایانِ پردازشِ یک Outbox. فقط صاحبِ Lease می‌تواند آن را ببندد (نتیجهٔ پردازشگرِ دیرمانده نادیده گرفته می‌شود).
   @Outcome: RETRY (بازگشت به صف با تأخیر) / DONE (SENT اگر دستِ‌کم یک دستگاه پذیرفت، وگرنه FAILED) / یکی از وضعیت‌هایِ SKIPPED_*. */
CREATE PROCEDURE dbo.sp_Push_CompleteOutbox
    @PushOutboxID      BIGINT,
    @Worker            NVARCHAR(100),
    @Outcome           VARCHAR(30),
    @RetryAfterSeconds INT            = NULL,
    @Error             NVARCHAR(1000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    DECLARE @Rows INT = 0;
    BEGIN TRAN;
    IF @Outcome = 'RETRY'
    BEGIN
        UPDATE dbo.PushOutbox
        SET Status = 'PENDING', NextAttemptAt = DATEADD(SECOND, ISNULL(@RetryAfterSeconds, 60), @Now),
            LockedBy = NULL, LockedUntil = NULL, LastError = LEFT(@Error, 1000), Date_LastUpdate = @Now
        WHERE PushOutboxID = @PushOutboxID AND Status = 'SENDING' AND LockedBy = @Worker;
        SET @Rows = @@ROWCOUNT;
    END
    ELSE
    BEGIN
        DECLARE @Final VARCHAR(30) = CASE
            WHEN @Outcome = 'DONE' THEN CASE WHEN EXISTS (SELECT 1 FROM dbo.PushDeliveries WHERE PushOutboxID = @PushOutboxID AND Status = 'SENT')
                                             THEN 'SENT' ELSE 'FAILED' END
            ELSE @Outcome END;
        UPDATE dbo.PushOutbox
        SET Status = @Final, LockedBy = NULL, LockedUntil = NULL, LastError = LEFT(@Error, 1000), CompletedAt = @Now,
            SentAt = CASE WHEN @Final = 'SENT' THEN ISNULL(SentAt, @Now) ELSE SentAt END, Date_LastUpdate = @Now
        WHERE PushOutboxID = @PushOutboxID AND Status = 'SENDING' AND LockedBy = @Worker;
        SET @Rows = @@ROWCOUNT;
        IF @Rows > 0
            UPDATE dbo.PushDeliveries SET Status = 'FAILED', Date_LastUpdate = @Now
            WHERE PushOutboxID = @PushOutboxID AND Status IN ('SENDING', 'RETRY');
    END
    COMMIT;
    IF @Rows = 0
    BEGIN SELECT 0 AS Success, N'LEASE_LOST' AS Message; RETURN; END
    SELECT 1 AS Success, N'OK' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_Cleanup
/* پاک‌سازیِ سوابقِ قدیمیِ پایان‌یافته. رکوردِ در انتظار/در حالِ ارسال هرگز حذف نمی‌شود؛ اعلانِ پیام فقط زیرِ کفِ اسکن حذف می‌شود
   (وگرنه دوباره صف می‌شد) و یادآوری فقط وقتی همان نوبت دیگر در انتظار نیست. */
CREATE PROCEDURE dbo.sp_Push_Cleanup
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
           OR  O.SourceType = 'TEST');

    BEGIN TRAN;
    DELETE D FROM dbo.PushDeliveries D JOIN @Ids I ON I.PushOutboxID = D.PushOutboxID;
    DELETE O FROM dbo.PushOutbox O JOIN @Ids I ON I.PushOutboxID = O.PushOutboxID;
    COMMIT;
    SELECT 1 AS Success, N'پاک‌سازی انجام شد.' AS Message, (SELECT COUNT(*) FROM @Ids) AS Removed;
END
GO

