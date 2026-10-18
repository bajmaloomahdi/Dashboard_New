/* ==========================================================================
   پچ خودکار شماره: 105 | نام: push_broadcast
   تاریخ: 2026-10-18 12:28:18 | شامل 12 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: PushBroadcasts
CREATE TABLE dbo.PushBroadcasts (
    PushBroadcastID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PushBroadcasts PRIMARY KEY,
    RowGuid          UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_PushBroadcasts_RowGuid DEFAULT (NEWID()),
    IdempotencyKey   UNIQUEIDENTIFIER  NOT NULL,   -- از فرمِ تأیید؛ ارسالِ دوبارهٔ همان فرم، اعلانِ تازه نمی‌سازد
    Title            NVARCHAR(60)      NOT NULL,
    Body             NVARCHAR(240)     NOT NULL,
    TargetMode       VARCHAR(10)       NOT NULL CONSTRAINT CK_PushBroadcasts_TargetMode CHECK (TargetMode IN ('ALL', 'USERS')),
    SelectedUserCount INT              NOT NULL CONSTRAINT DF_PushBroadcasts_SelectedUserCount DEFAULT (0),  -- کاربرانِ فعالِ انتخاب‌شده
    RecipientCount   INT               NOT NULL CONSTRAINT DF_PushBroadcasts_RecipientCount DEFAULT (0),     -- از میانِ آن‌ها، دارایِ اشتراکِ فعال
    CreatedByUserID  INT               NOT NULL CONSTRAINT FK_PushBroadcasts_CreatedBy REFERENCES dbo.Users (UserID),
    Date_InsertFirst DATETIME2(0)      NOT NULL CONSTRAINT DF_PushBroadcasts_Date_InsertFirst DEFAULT (SYSDATETIME()),
    CONSTRAINT UQ_PushBroadcasts_IdempotencyKey UNIQUE (IdempotencyKey),
    CONSTRAINT CK_PushBroadcasts_Text CHECK (LEN(Title) > 0 AND LEN(Body) > 0)
)
GO

-- [CREATE_TABLE] روی TABLE: PushBroadcastRecipients
CREATE TABLE dbo.PushBroadcastRecipients (
    PushBroadcastRecipientID BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PushBroadcastRecipients PRIMARY KEY,
    PushBroadcastID          INT    NOT NULL CONSTRAINT FK_PushBroadcastRecipients_Broadcast REFERENCES dbo.PushBroadcasts (PushBroadcastID),
    UserID                   INT    NOT NULL CONSTRAINT FK_PushBroadcastRecipients_User REFERENCES dbo.Users (UserID),
    CONSTRAINT UQ_PushBroadcastRecipients_Broadcast_User UNIQUE (PushBroadcastID, UserID)
)
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_SourceType
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_SourceType CHECK (SourceType IN ('NOTIFICATION', 'REMINDER', 'TEST', 'BROADCAST'))
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_Kind
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_Kind CHECK (Kind IN ('MESSAGE', 'TASK', 'REMINDER', 'TEST', 'BROADCAST'))
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetBroadcastAudience
/* کاربرانِ فعالِ هدف (همه یا انتخاب‌شده) + اینکه اشتراکِ فعال دارند یا نه. @UserIDs: شناسه‌ها با «,» */
CREATE PROCEDURE dbo.sp_Push_GetBroadcastAudience
    @TargetMode VARCHAR(10),
    @UserIDs    NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT U.UserID, LTRIM(CONCAT(U.FirstName, N' ', U.LastName)) AS FullName,
           CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = U.UserID AND PS.IsActive = 1) THEN 1 ELSE 0 END AS BIT) AS HasSubscription,
           (SELECT COUNT(*) FROM dbo.PushSubscriptions PS WHERE PS.UserID = U.UserID AND PS.IsActive = 1) AS DeviceCount
    FROM dbo.Users U
    WHERE U.IsActive = 1
      AND (@TargetMode = 'ALL'
           OR (@TargetMode = 'USERS' AND U.UserID IN (SELECT TRY_CAST(value AS INT) FROM STRING_SPLIT(ISNULL(@UserIDs, N''), N','))))
    ORDER BY HasSubscription DESC, FullName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_CreateBroadcast
/* ساختِ اعلانِ عمومی و صف‌کردنِ آن برایِ گیرندگانِ دارایِ اشتراکِ فعال. کلیدِ تکرار → همان اعلانِ قبلی برگردانده می‌شود. */
CREATE PROCEDURE dbo.sp_Push_CreateBroadcast
    @CreatedByUserID INT,
    @Title           NVARCHAR(60),
    @Body            NVARCHAR(240),
    @TargetMode      VARCHAR(10),
    @UserIDs         NVARCHAR(MAX) = NULL,
    @IdempotencyKey  UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @Title = LTRIM(RTRIM(@Title));
    SET @Body = LTRIM(RTRIM(@Body));
    IF NULLIF(@Title, N'') IS NULL OR NULLIF(@Body, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'عنوان و متنِ اعلان الزامی است.' AS Message, NULL AS PushBroadcastID, 0 AS IsDuplicate; RETURN; END
    IF @TargetMode NOT IN ('ALL', 'USERS')
    BEGIN SELECT 0 AS Success, N'گیرندگانِ اعلان نامعتبر است.' AS Message, NULL AS PushBroadcastID, 0 AS IsDuplicate; RETURN; END
    IF @IdempotencyKey IS NULL
    BEGIN SELECT 0 AS Success, N'شناسهٔ تأیید لازم است.' AS Message, NULL AS PushBroadcastID, 0 AS IsDuplicate; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.PushState WHERE PushStateID = 1 AND IsEnabled = 1 AND ActivatedAt IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان رویِ سرور فعال نیست.' AS Message, NULL AS PushBroadcastID, 0 AS IsDuplicate; RETURN; END

    DECLARE @Existing INT = (SELECT PushBroadcastID FROM dbo.PushBroadcasts WHERE IdempotencyKey = @IdempotencyKey);
    IF @Existing IS NOT NULL
    BEGIN SELECT 1 AS Success, N'این اعلان قبلاً ثبت شده بود و دوباره ارسال نشد.' AS Message, @Existing AS PushBroadcastID, 1 AS IsDuplicate; RETURN; END

    DECLARE @Audience TABLE (UserID INT PRIMARY KEY, HasSubscription BIT);
    INSERT INTO @Audience (UserID, HasSubscription)
    SELECT U.UserID, CASE WHEN EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = U.UserID AND PS.IsActive = 1) THEN 1 ELSE 0 END
    FROM dbo.Users U
    WHERE U.IsActive = 1
      AND (@TargetMode = 'ALL'
           OR U.UserID IN (SELECT TRY_CAST(value AS INT) FROM STRING_SPLIT(ISNULL(@UserIDs, N''), N',')));

    IF NOT EXISTS (SELECT 1 FROM @Audience)
    BEGIN SELECT 0 AS Success, N'هیچ کاربرِ فعالی انتخاب نشده است.' AS Message, NULL AS PushBroadcastID, 0 AS IsDuplicate; RETURN; END

    DECLARE @ID INT;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    BEGIN TRAN;
    EXEC sp_getapplock @Resource = N'PushBroadcastCreate', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 15000;
    SET @Existing = (SELECT PushBroadcastID FROM dbo.PushBroadcasts WHERE IdempotencyKey = @IdempotencyKey);
    IF @Existing IS NOT NULL
    BEGIN
        COMMIT;
        SELECT 1 AS Success, N'این اعلان قبلاً ثبت شده بود و دوباره ارسال نشد.' AS Message, @Existing AS PushBroadcastID, 1 AS IsDuplicate;
        RETURN;
    END

    INSERT INTO dbo.PushBroadcasts (IdempotencyKey, Title, Body, TargetMode, SelectedUserCount, RecipientCount, CreatedByUserID)
    VALUES (@IdempotencyKey, @Title, @Body, @TargetMode, (SELECT COUNT(*) FROM @Audience), (SELECT COUNT(*) FROM @Audience WHERE HasSubscription = 1), @CreatedByUserID);
    SET @ID = CAST(SCOPE_IDENTITY() AS INT);

    INSERT INTO dbo.PushBroadcastRecipients (PushBroadcastID, UserID)
    SELECT @ID, UserID FROM @Audience WHERE HasSubscription = 1;

    INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag, Status)
    SELECT 'BROADCAST', R.PushBroadcastRecipientID, '19000101', R.UserID, @Now, 'BROADCAST', N'/dashboard',
           'bc-' + CAST(@ID AS VARCHAR(20)), 'PENDING'
    FROM dbo.PushBroadcastRecipients R
    WHERE R.PushBroadcastID = @ID;
    COMMIT;

    SELECT 1 AS Success,
           CASE WHEN EXISTS (SELECT 1 FROM @Audience WHERE HasSubscription = 1)
                THEN N'اعلان در صف قرار گرفت و طیِ چند دقیقه ارسال می‌شود.'
                ELSE N'اعلان ثبت شد، اما هیچ‌یک از کاربرانِ انتخاب‌شده اعلان‌ها را فعال نکرده‌اند.' END AS Message,
           @ID AS PushBroadcastID, 0 AS IsDuplicate;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetBroadcasts
/* فهرستِ اعلان‌هایِ عمومی با وضعیتِ ارسال (هر گیرنده یک ردیفِ Outbox؛ «موفق» = دستِ‌کم یک دستگاهِ او پذیرفت) */
CREATE PROCEDURE dbo.sp_Push_GetBroadcasts
    @Top              INT = 50,
    @PushBroadcastID  INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@Top)
        B.PushBroadcastID, B.Title, B.Body, B.TargetMode, B.SelectedUserCount, B.RecipientCount, B.Date_InsertFirst,
        B.CreatedByUserID, LTRIM(CONCAT(U.FirstName, N' ', U.LastName)) AS CreatedByName,
        ISNULL(S.Pending, 0) AS PendingCount, ISNULL(S.Sent, 0) AS SentCount,
        ISNULL(S.Failed, 0) AS FailedCount, ISNULL(S.Skipped, 0) AS SkippedCount,
        ISNULL(D.DevicesSent, 0) AS DevicesSent, ISNULL(D.DevicesFailed, 0) AS DevicesFailed
    FROM dbo.PushBroadcasts B
    LEFT JOIN dbo.Users U ON U.UserID = B.CreatedByUserID
    OUTER APPLY (
        SELECT SUM(CASE WHEN O.Status IN ('PENDING', 'SENDING') THEN 1 ELSE 0 END) AS Pending,
               SUM(CASE WHEN O.Status = 'SENT' THEN 1 ELSE 0 END) AS Sent,
               SUM(CASE WHEN O.Status = 'FAILED' THEN 1 ELSE 0 END) AS Failed,
               SUM(CASE WHEN O.Status LIKE 'SKIPPED[_]%' THEN 1 ELSE 0 END) AS Skipped
        FROM dbo.PushBroadcastRecipients R
        JOIN dbo.PushOutbox O ON O.SourceType = 'BROADCAST' AND O.SourceID = R.PushBroadcastRecipientID AND O.SourceVersion = '19000101'
        WHERE R.PushBroadcastID = B.PushBroadcastID
    ) S
    OUTER APPLY (
        SELECT SUM(CASE WHEN PD.Status = 'SENT' THEN 1 ELSE 0 END) AS DevicesSent,
               SUM(CASE WHEN PD.Status IN ('FAILED', 'EXPIRED') THEN 1 ELSE 0 END) AS DevicesFailed
        FROM dbo.PushBroadcastRecipients R
        JOIN dbo.PushOutbox O ON O.SourceType = 'BROADCAST' AND O.SourceID = R.PushBroadcastRecipientID AND O.SourceVersion = '19000101'
        JOIN dbo.PushDeliveries PD ON PD.PushOutboxID = O.PushOutboxID
        WHERE R.PushBroadcastID = B.PushBroadcastID
    ) D
    WHERE @PushBroadcastID IS NULL OR B.PushBroadcastID = @PushBroadcastID
    ORDER BY B.PushBroadcastID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Push_GetBroadcastContent
/* عنوان/متنِ اعلانِ عمومی برایِ ساختِ Payload (از رویِ شناسهٔ گیرنده = SourceIDِ Outbox) */
CREATE PROCEDURE dbo.sp_Push_GetBroadcastContent
    @PushBroadcastRecipientID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT B.PushBroadcastID, B.Title, B.Body
    FROM dbo.PushBroadcastRecipients R
    JOIN dbo.PushBroadcasts B ON B.PushBroadcastID = R.PushBroadcastID
    WHERE R.PushBroadcastRecipientID = @PushBroadcastRecipientID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_GetTargets
/* اعلانِ عمومی: هر اشتراکِ فعالِ کاربر در لحظهٔ ارسال (مثلِ یادآوری/آزمایش، بدونِ شرطِ SubscribedAt) */
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
      AND (O.SourceType IN ('REMINDER', 'TEST', 'BROADCAST') OR PS.SubscribedAt <= DATEADD(SECOND, 1, O.EventAt)) /* SubscribedAt/EventAt ثانیه‌ای گرد می‌شوند */
      AND NOT EXISTS (SELECT 1 FROM dbo.PushDeliveries D
                      WHERE D.PushOutboxID = O.PushOutboxID AND D.PushSubscriptionID = PS.PushSubscriptionID
                        AND D.Status IN ('SENT', 'EXPIRED', 'FAILED'))
    ORDER BY PS.PushSubscriptionID;
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



-- [DML — دستی، خارج از DDL Trigger] دسترسیِ «ارسالِ اعلانِ عمومی» (در «دسترسی‌های عملیاتی»ِ نقش‌ها قابلِ اعطا)
-- و اعطایِ پیش‌فرض به نقشِ ادمین (RoleID = 1)، هم‌الگو با پچ 083.
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'PUSH_BROADCAST')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'PUSH_BROADCAST', N'ارسالِ اعلانِ عمومی', N'Notifications', N'ارسالِ اعلانِ گوشی/مرورگر به همهٔ کاربران یا کاربرانِ انتخاب‌شده (فقط کسانی که اعلان‌ها را فعال کرده‌اند) و مشاهدهٔ وضعیتِ ارسال', 1, 1);
GO

IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'PUSH_BROADCAST'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

-- منویِ «ارسال اعلان عمومی» زیرِ پوشهٔ «مدیریت سیستم» (والدِ منویِ /users) و نمایش برایِ نقشِ ادمین (RoleID = 1).
-- دیدنِ منو کافی نیست: صفحه و APIها دسترسیِ PUSH_BROADCAST را جداگانه بررسی می‌کنند.
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'PUSH_BROADCAST')
   AND EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/users' AND ParentID IS NOT NULL)
BEGIN
    DECLARE @ParentID INT = (SELECT TOP (1) ParentID FROM dbo.Menu WHERE Url = N'/users' AND ParentID IS NOT NULL ORDER BY MenuID);
    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateDate)
    SELECT @ParentID, N'PUSH_BROADCAST', N'ارسال اعلان عمومی', N'PAGE', N'/push/broadcasts', N'NotificationOutlined',
           ISNULL((SELECT TOP (1) Level FROM dbo.Menu WHERE Url = N'/users' AND ParentID = @ParentID), 2),
           ISNULL((SELECT MAX(SortOrder) FROM dbo.Menu WHERE ParentID = @ParentID), 0) + 1, 0, 1, 1, 0, SYSDATETIME();
END
GO

IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RoleMenus (RoleID, MenuID, CanView, CreateDate, IsActive)
    SELECT 1, m.MenuID, 1, SYSDATETIME(), 1
    FROM dbo.Menu m
    WHERE m.MenuCode = N'PUSH_BROADCAST'
      AND NOT EXISTS (SELECT 1 FROM dbo.RoleMenus rm WHERE rm.RoleID = 1 AND rm.MenuID = m.MenuID);
END
GO
