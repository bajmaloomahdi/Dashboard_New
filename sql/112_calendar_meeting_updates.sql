/* ==========================================================================
   پچ خودکار شماره: 112 | نام: calendar_meeting_updates
   تاریخ: 2026-10-19 13:12:24 | شامل 7 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE dbo.CalendarEventAttendees ADD
    InviteIsUpdate         BIT          NOT NULL CONSTRAINT DF_CalendarEventAttendees_InviteIsUpdate DEFAULT (0),
    PreviousResponseStatus NVARCHAR(20) NULL,
    PreviousResponseDate   DATETIME2(3) NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE dbo.CalendarEventAttendees WITH CHECK ADD CONSTRAINT CK_CalendarEventAttendees_PreviousResponse
    CHECK ((PreviousResponseStatus IS NULL AND PreviousResponseDate IS NULL) OR PreviousResponseStatus IN (N'ACCEPTED', N'REJECTED', N'TENTATIVE'))
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
        DECLARE @OldStart DATETIME2(0), @OldEnd DATETIME2(0), @OldAllDay BIT, @OldRecType NVARCHAR(20), @OldRecInterval INT, @OldRecEnd DATE; /* زمان‌بندیِ پیش از ویرایش */
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID, @WasPrivate = IsPrivate,
               @OldStart = StartDateTime, @OldEnd = EndDateTime, @OldAllDay = IsAllDay, @OldRecType = RecurrenceType,
               @OldRecInterval = RecurrenceInterval, @OldRecEnd = RecurrenceEndDate
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
        IF @Status <> N'CANCELLED' AND EXISTS (
            SELECT @OldStart, @OldEnd, @OldAllDay, @OldRecType,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecInterval END,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecEnd END
            EXCEPT
            SELECT @StartDateTime, @EndDateTime, @IsAllDay, @RecurrenceType,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceInterval END,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceEndDate END)
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_SetCalendarEventResponse
-- ---------- پاسخ کاربر به دعوت رویداد ----------
ALTER PROCEDURE [dbo].[sp_SetCalendarEventResponse]
    @EventID        BIGINT,
    @UserID         INT,
    @ResponseStatus NVARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @ResponseStatus NOT IN (N'PENDING', N'ACCEPTED', N'REJECTED', N'TENTATIVE')
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'وضعیت پاسخ نامعتبر است.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees WHERE EventID = @EventID AND UserID = @UserID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'شما در این رویداد عضو نیستید.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEventAttendees
        SET ResponseStatus = @ResponseStatus, ResponseDate = SYSDATETIME(),
            InviteIsUpdate = 0, PreviousResponseStatus = NULL, PreviousResponseDate = NULL, /* پاسخِ تازه جایِ پاسخِ پیش از تغییر را می‌گیرد */
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE EventID = @EventID AND UserID = @UserID AND IsActive = 1;

        SELECT CAST(1 AS BIT) AS Success, N'پاسخ شما ثبت شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ثبت پاسخ: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetPendingCalendarInvitations
/* دعوت‌هایِ در انتظارِ پاسخِ یک کاربر — فقط رویدادِ فعال، غیرلغو و تمام‌نشده و فقط برایِ کاربرِ فعالِ دارایِ CALENDAR_VIEW.
   InvitedAt = نسخهٔ دعوت (برایِ نمایشِ یک‌بارهٔ اعلانِ داخلِ برنامه). */
ALTER PROCEDURE dbo.sp_GetPendingCalendarInvitations
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    SELECT A.CalendarEventAttendeeID, A.EventID, A.RelationType, ISNULL(A.InvitedAt, A.Date_InsertFirst) AS InvitedAt,
           A.InviteIsUpdate, A.PreviousResponseStatus, A.PreviousResponseDate,
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetCalendarEventAttendees
ALTER PROCEDURE [dbo].[sp_GetCalendarEventAttendees]
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        a.CalendarEventAttendeeID,
        a.EventID,
        a.UserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS FullName,
        a.RelationType,
        a.ResponseStatus,
        a.ResponseDate,
        a.PreviousResponseStatus,
        a.PreviousResponseDate,
        a.InviteIsUpdate,
        rem.OffsetMinutes AS ReminderOffsetMinutes
    FROM dbo.CalendarEventAttendees a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID
    LEFT JOIN dbo.Reminders rem
        ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = a.EventID
       AND rem.UserID = a.UserID AND rem.IsActive = 1
    WHERE a.EventID = @EventID AND a.IsActive = 1
    ORDER BY CASE a.RelationType WHEN N'ORGANIZER' THEN 0 ELSE 1 END, FullName;
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

    -- کفِ اسکن فقط تا جایی جلو می‌رود که رکوردها دستِ‌کم ۱۰ دقیقه پیش صف شده‌اند (تراکنش‌هایِ دیرتر Commitشده جا نمی‌مانند)
    DECLARE @NewScanFrom BIGINT = (SELECT MAX(SourceID) FROM dbo.PushOutbox
                                   WHERE SourceType = 'NOTIFICATION' AND Date_InsertFirst < DATEADD(MINUTE, -10, @Now));
    UPDATE dbo.PushState SET NotificationScanFromID = @NewScanFrom, Date_LastUpdate = SYSDATETIME()
    WHERE PushStateID = 1 AND @NewScanFrom IS NOT NULL AND @NewScanFrom > ISNULL(NotificationScanFromID, 0);

    COMMIT;
    SELECT 1 AS Success, N'صف‌بندی انجام شد.' AS Message, @N AS NotificationsQueued, @R AS RemindersQueued, @I AS InvitesQueued;
END
GO

