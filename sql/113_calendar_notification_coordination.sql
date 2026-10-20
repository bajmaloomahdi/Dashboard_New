/* ==========================================================================
   پچ خودکار شماره: 113 | نام: calendar_notification_coordination
   تاریخ: 2026-10-19 13:55:46 | شامل 14 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE dbo.CalendarEventAttendees ADD CancelNoticeAt DATETIME2(0) NULL
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_SourceType
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_SourceType CHECK ([SourceType] IN ('NOTIFICATION', 'REMINDER', 'TEST', 'BROADCAST', 'INVITE', 'CANCEL'))
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox DROP CONSTRAINT CK_PushOutbox_Kind
GO

-- [ALTER_TABLE] روی TABLE: PushOutbox
ALTER TABLE dbo.PushOutbox WITH CHECK ADD CONSTRAINT CK_PushOutbox_Kind CHECK ([Kind] IN ('MESSAGE', 'TASK', 'REMINDER', 'TEST', 'BROADCAST', 'INVITE', 'CANCEL'))
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
    @ModifyUser           INT,
    @IsPrivate            BIT = NULL       -- NULL = همان مقدارِ فعلی (جابه‌جایی و فراخوانی‌هایِ قدیمی تغییرش نمی‌دهند)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT, @WasPrivate BIT;
        DECLARE @OldStart DATETIME2(0), @OldEnd DATETIME2(0), @OldAllDay BIT, @OldRecType NVARCHAR(20), @OldRecInterval INT, @OldRecEnd DATE, @OldStatus NVARCHAR(20); /* زمان‌بندیِ پیش از ویرایش */
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID, @WasPrivate = IsPrivate,
               @OldStart = StartDateTime, @OldEnd = EndDateTime, @OldAllDay = IsAllDay, @OldRecType = RecurrenceType,
               @OldRecInterval = RecurrenceInterval, @OldRecEnd = RecurrenceEndDate, @OldStatus = Status
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @Unit) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE'))) /* یا مدیرِ رویدادِ واحد */
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را ویرایش کند.' AS Message;
            RETURN;
        END

        /* انتقال به (یا ماندن در) تقویمِ واحدِ دیگر فقط برایِ مدیرِ رویدادِ همان واحد */
        IF @OrganizationalUnitID IS NOT NULL AND ISNULL(@Unit, 0) <> @OrganizationalUnitID AND NOT (@OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE')))
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'اجازهٔ ثبتِ رویداد در تقویمِ این واحد را ندارید.' AS Message;
            RETURN;
        END

        /* خصوصی: ارسال‌نشده = مقدارِ فعلی؛ رویدادِ تقویمِ واحد خصوصی نمی‌شود */
        SET @IsPrivate = ISNULL(@IsPrivate, @WasPrivate);
        IF @IsPrivate = 1 AND @OrganizationalUnitID IS NOT NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.' AS Message;
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
            IsPrivate            = @IsPrivate,
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
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt WHEN t.InvitedAt IS NOT NULL AND t.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, t.InvitedAt) /* همان ثانیه → نسخهٔ تازه */ ELSE SYSDATETIME() END
                     , InviteIsUpdate = CASE WHEN s.WasActive = 1 THEN t.InviteIsUpdate ELSE 0 END, PreviousResponseStatus = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseStatus ELSE NULL END, PreviousResponseDate = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseDate ELSE NULL END /* دعوتِ تازه */
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
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt WHEN t.InvitedAt IS NOT NULL AND t.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, t.InvitedAt) /* همان ثانیه → نسخهٔ تازه */ ELSE SYSDATETIME() END
                     , InviteIsUpdate = CASE WHEN s.WasActive = 1 THEN t.InviteIsUpdate ELSE 0 END, PreviousResponseStatus = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseStatus ELSE NULL END, PreviousResponseDate = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseDate ELSE NULL END /* دعوتِ تازه */
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, s.UserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        /* تغییرِ زمان، تاریخ، مدت، تمام‌روز یا قاعدهٔ تکرار → شرکت‌کنندگانِ قبلی (به‌جز ویرایشگر، سازنده و برگزارکننده) دوباره پاسخ می‌دهند:
           پاسخِ قبلی و زمانش در PreviousResponseStatus/PreviousResponseDate می‌ماند، پاسخ = PENDING، نسخهٔ دعوت (InvitedAt) تازه و InviteIsUpdate = 1
           → یک اعلانِ «تغییرِ جلسه» با همان صف و Pushِ دعوت (نسخه‌هایِ قدیمیِ ارسال‌نشده خودکار کنار می‌روند).
           تغییرِ عنوان/توضیح/رنگ/وضعیت یا فهرستِ شرکت‌کنندگان پاسخ‌ها را دست نمی‌زند؛ رویدادِ لغوشده بازنشانی نمی‌شود.
           شرکت‌کنندهٔ تازه یا دوباره‌اضافه‌شده در همین ویرایش همان «دعوتِ تازه» را دارد (در @Prev نیست). */
        DECLARE @ResponsesReset INT = 0;
        /* پچِ ۱۱۳: برگرداندنِ جلسه از «لغو» هم تغییرِ جلسه است (بازنشانی و یک اطلاعِ «تغییرِ جلسه») */
        IF @Status <> N'CANCELLED' AND (ISNULL(@OldStatus, N'') = N'CANCELLED' OR EXISTS (
            SELECT @OldStart, @OldEnd, @OldAllDay, @OldRecType,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecInterval END,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecEnd END
            EXCEPT
            SELECT @StartDateTime, @EndDateTime, @IsAllDay, @RecurrenceType,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceInterval END,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceEndDate END))
        BEGIN
            UPDATE A SET
                /* پاسخِ واقعی (غیرِ PENDING) با زمانش نگه داشته می‌شود؛ اگر پس از بازنشانیِ قبلی هنوز پاسخ نداده، همان پاسخِ قبلی می‌ماند */
                PreviousResponseStatus = CASE WHEN A.ResponseStatus <> N'PENDING' THEN A.ResponseStatus ELSE A.PreviousResponseStatus END,
                PreviousResponseDate = CASE WHEN A.ResponseStatus <> N'PENDING' THEN A.ResponseDate ELSE A.PreviousResponseDate END,
                ResponseStatus = N'PENDING',
                ResponseDate = NULL,
                InviteIsUpdate = 1,
                InvitedAt = CASE WHEN A.InvitedAt IS NOT NULL AND A.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, A.InvitedAt) ELSE SYSDATETIME() END,
                Date_LastUpdate = SYSDATETIME(),
                UserID_LastUpdate = @ModifyUser
            FROM dbo.CalendarEventAttendees A
            INNER JOIN @Prev P ON P.UserID = A.UserID
            WHERE A.EventID = @EventID AND A.IsActive = 1 AND A.RelationType <> N'ORGANIZER'
              AND A.UserID <> @ModifyUser AND A.UserID <> @Creator;
            SET @ResponsesReset = @@ROWCOUNT;
        END

        /* پچِ ۱۱۳ — لغوِ جلسهٔ تمام‌نشده: شرکت‌کنندگانِ فعال (به‌جز ویرایشگر، سازنده، برگزارکننده و ردکرده‌ها) یک «اطلاعِ لغو»
           می‌گیرند (CancelNoticeAt = نسخه). برگرداندن از لغو این اطلاع را باطل می‌کند (Pushِ ارسال‌نشده کنار می‌رود). */
        IF @Status = N'CANCELLED' AND ISNULL(@OldStatus, N'') <> N'CANCELLED' AND (@RecurrenceType = N'NONE' AND @EndDateTime >= SYSDATETIME() OR @RecurrenceType <> N'NONE' AND (@RecurrenceEndDate IS NULL OR @RecurrenceEndDate >= CAST(SYSDATETIME() AS DATE)))
            UPDATE dbo.CalendarEventAttendees
            SET CancelNoticeAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHERE EventID = @EventID AND IsActive = 1 AND RelationType <> N'ORGANIZER'
              AND UserID <> @ModifyUser AND UserID <> @Creator AND ResponseStatus <> N'REJECTED';
        ELSE IF @Status <> N'CANCELLED' AND @OldStatus = N'CANCELLED'
            UPDATE dbo.CalendarEventAttendees
            SET CancelNoticeAt = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHERE EventID = @EventID AND CancelNoticeAt IS NOT NULL;
        -- پاکسازی یادآوری کاربرانی که دیگر عضو رویداد نیستند
        UPDATE rem SET IsActive = 0
        FROM dbo.Reminders rem
        WHERE rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = @EventID AND rem.IsActive = 1
          AND NOT EXISTS (
              SELECT 1 FROM dbo.CalendarEventAttendees a
              WHERE a.EventID = @EventID AND a.UserID = rem.UserID AND a.IsActive = 1
          )
          AND rem.UserID NOT IN (@Creator, @OwnerUserID);

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ویرایش شد.' AS Message, @ResponsesReset AS ResponsesReset;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_DeleteCalendarEvent
ALTER PROCEDURE [dbo].[sp_DeleteCalendarEvent]
    @EventID    BIGINT,
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT;
        DECLARE @Status NVARCHAR(20), @End DATETIME2(0), @RecType NVARCHAR(20), @RecEnd DATE; /* پچِ ۱۱۳ */
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID,
               @Status = Status, @End = EndDateTime, @RecType = RecurrenceType, @RecEnd = RecurrenceEndDate
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @Unit) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE'))) /* یا مدیرِ رویدادِ واحد */
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را حذف کند.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID;

        /* پچِ ۱۱۳ — حذفِ جلسهٔ تمام‌نشده که قبلاً لغو نشده: همان «اطلاعِ لغو» برایِ شرکت‌کنندگانِ فعالِ غیرِ ردکننده */
        IF @Status <> N'CANCELLED' AND (@RecType = N'NONE' AND @End >= SYSDATETIME() OR @RecType <> N'NONE' AND (@RecEnd IS NULL OR @RecEnd >= CAST(SYSDATETIME() AS DATE)))
            UPDATE dbo.CalendarEventAttendees
            SET CancelNoticeAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHERE EventID = @EventID AND IsActive = 1 AND RelationType <> N'ORGANIZER'
              AND UserID <> @ModifyUser AND UserID <> @Creator AND ResponseStatus <> N'REJECTED';

        UPDATE dbo.CalendarEventAttendees SET IsActive = 0 WHERE EventID = @EventID;
        UPDATE dbo.Reminders SET IsActive = 0 WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = @EventID;

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف رویداد: ' + ERROR_MESSAGE() AS Message;
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
    BEGIN SELECT 0 AS Success, N'ارسالِ اعلان فعال نیست.' AS Message, 0 AS NotificationsQueued, 0 AS RemindersQueued, 0 AS InvitesQueued, 0 AS CancelsQueued; RETURN; END

    DECLARE @Stale DATETIME2(0) = CASE WHEN @MaxAgeMinutes IS NULL THEN NULL ELSE DATEADD(MINUTE, -@MaxAgeMinutes, @Now) END;
    DECLARE @NoVersion DATETIME2(0) = '19000101';
    DECLARE @N INT = 0, @R INT = 0, @I INT = 0, @C INT = 0;

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
        WHEN E.EventID IS NOT NULL AND DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt)) < @Now THEN 'SKIPPED_STALE' /* پچِ ۱۱۳: وقوعِ جلسه تمام شده */
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
           CASE WHEN A.InviteIsUpdate = 1 THEN 'invu-' ELSE 'inv-' END + CAST(A.CalendarEventAttendeeID AS VARCHAR(20)) + '-' + FORMAT(A.InvitedAt, 'yyMMddHHmmss'),
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
    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued, @I AS InvitesQueued, @C AS CancelsQueued;
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_ClaimBatch
/* Claimِ اتمیک: UPDLOCK + READPAST (اعلانِ عمومی در اولویتِ آخر) — دو پردازشگر هرگز یک رکورد را با هم برنمی‌دارند؛ رکوردِ SENDING با Leaseِ منقضی دوباره برداشته می‌شود. */
ALTER PROCEDURE dbo.sp_Push_ClaimBatch
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
        ORDER BY CASE WHEN SourceType = 'BROADCAST' THEN 1 ELSE 0 END, NextAttemptAt, CASE WHEN SourceType IN ('INVITE', 'CANCEL') THEN 0 ELSE 1 END /* پچِ ۱۱۳: دعوت/لغو پیش از یادآوری */, PushOutboxID /* اعلانِ عمومی بعد از اعلان‌هایِ کاری */
    )
    UPDATE C
    SET Status = 'SENDING', LockedBy = @Worker, LockedUntil = DATEADD(SECOND, @LeaseSeconds, @Now),
        Attempts = Attempts + 1, Date_LastUpdate = @Now
    OUTPUT inserted.PushOutboxID, inserted.SourceType, inserted.SourceID, inserted.SourceVersion, inserted.UserID,
           inserted.EventAt, inserted.Kind, inserted.TargetUrl, inserted.Tag, inserted.Attempts;
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
        r.RemindAt
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND r.RemindAt <= SYSDATETIME()
      AND (r.EntityType <> N'CALENDAR_EVENT' OR (e.EventID IS NOT NULL AND e.Status <> N'CANCELLED')) /* رویدادِ لغوشده یادآوری ندارد */
      AND (r.EntityType <> N'CALENDAR_EVENT' OR DATEADD(SECOND, DATEDIFF(SECOND, e.StartDateTime, e.EndDateTime), DATEADD(MINUTE, ISNULL(r.OffsetMinutes, 0), r.RemindAt)) >= SYSDATETIME()) /* پچِ ۱۱۳: وقوعِ تمام‌شده = منقضی */
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = r.UserID AND AU.IsActive = 1) /* کاربرِ غیرفعال یادآوری نمی‌گیرد */
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(r.UserID, N'CALENDAR_VIEW')) /* بدونِ مجوزِ تقویم یادآوری نمی‌گیرد */
      AND NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees RA WHERE r.EntityType = N'CALENDAR_EVENT' AND RA.EventID = r.EntityID
                      AND RA.UserID = r.UserID AND RA.IsActive = 1 AND RA.ResponseStatus = N'REJECTED') /* دعوت را رد کرده */;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarNotices
/* پچِ ۱۱۳ — اطلاع‌هایِ لغو/حذفِ جلسه برایِ خودِ کاربر (۷ روزِ اخیر) — برایِ اعلانِ داخلِ برنامه؛ فقط شرکت‌کنندهٔ همان جلسه */
CREATE PROCEDURE dbo.sp_GetCalendarNotices
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT A.CalendarEventAttendeeID, A.EventID, A.CancelNoticeAt,
           E.Title, E.StartDateTime, E.EndDateTime, E.IsAllDay, E.RecurrenceType,
           CAST(CASE WHEN E.IsActive = 0 THEN 1 ELSE 0 END AS BIT) AS IsDeleted,
           dbo.fn_GetUserFullName(mu.FirstName, mu.LastName, mu.UserName) AS ChangedByName
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND (E.IsActive = 0 OR (E.Status = N'CANCELLED' AND A.IsActive = 1))
    LEFT JOIN dbo.Users mu ON mu.UserID = A.UserID_LastUpdate
    WHERE A.UserID = @UserID AND A.CancelNoticeAt IS NOT NULL AND A.CancelNoticeAt >= DATEADD(DAY, -7, SYSDATETIME())
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
      AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW'))
    ORDER BY A.CancelNoticeAt DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetRemindersToRoll
/* پچِ ۱۱۳ — یادآوری‌هایِ رویدادِ تکرارشونده که وقوعشان تمام شده و هنوز جلو نرفته‌اند (برنامه با تقویمِ شمسی به وقوعِ بعدی می‌برد).
   @UserID = NULL → همهٔ کاربران (زمان‌بند)؛ وگرنه فقط همان کاربر (هنگامِ دریافتِ یادآوری‌هایِ داخلِ برنامه). */
CREATE PROCEDURE dbo.sp_Calendar_GetRemindersToRoll
    @UserID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (500) R.ReminderID, R.UserID, R.OffsetMinutes, R.RemindAt,
           E.StartDateTime, E.EndDateTime, E.RecurrenceType, E.RecurrenceInterval, E.RecurrenceEndDate
    FROM dbo.Reminders R
    INNER JOIN dbo.CalendarEvents E ON R.EntityType = N'CALENDAR_EVENT' AND E.EventID = R.EntityID AND E.IsActive = 1
    WHERE R.IsActive = 1 AND R.IsSent = 0 AND E.RecurrenceType <> N'NONE' AND E.Status <> N'CANCELLED'
      AND (@UserID IS NULL OR R.UserID = @UserID)
      AND DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt)) < SYSDATETIME()
    ORDER BY R.RemindAt;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_RollReminder
/* پچِ ۱۱۳ — جلو بردنِ اتمیکِ یک یادآوری (compare-and-set): فقط اگر هنوز همان RemindAt، ارسال‌نشده و فعال است.
   زمان‌بند و درخواستِ داخلِ برنامه ممکن است هم‌زمان همان ردیف را ببینند؛ فقط یکی موفق می‌شود و اگر کاربر در این فاصله
   «دیدم» زده یا رویداد ویرایش شده (RemindAt عوض شده)، هیچ تغییری داده نمی‌شود. @NewRemindAt = NULL → سری تمام شده (بسته شود). */
CREATE PROCEDURE dbo.sp_Calendar_RollReminder
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
        Date_LastUpdate = SYSDATETIME()
    WHERE ReminderID = @ReminderID AND RemindAt = @ExpectedRemindAt AND IsSent = 0 AND IsActive = 1;
    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Rolled;
END
GO

