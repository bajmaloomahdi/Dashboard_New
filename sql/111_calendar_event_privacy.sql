/* ==========================================================================
   پچ خودکار شماره: 111 | نام: calendar_event_privacy
   تاریخ: 2026-10-19 12:32:20 | شامل 7 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE dbo.CalendarEvents ADD IsPrivate BIT NOT NULL CONSTRAINT DF_CalendarEvents_IsPrivate DEFAULT (0)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE dbo.CalendarEvents WITH CHECK ADD CONSTRAINT CK_CalendarEvents_PrivateNoUnit CHECK (IsPrivate = 0 OR OrganizationalUnitID IS NULL)
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
    @CreateUser           INT,
    @IsPrivate            BIT = 0          -- رویدادِ خصوصی (پیش‌فرض: عمومی)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        /* رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد (قیدِ CK_CalendarEvents_PrivateNoUnit هم همین را تضمین می‌کند) */
        IF ISNULL(@IsPrivate, 0) = 1 AND @OrganizationalUnitID IS NOT NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.' AS Message;
            RETURN;
        END

        /* رویدادِ تقویمِ واحد فقط توسطِ عضوِ مستقیمِ همان واحد با مجوزِ CALENDAR_UNIT_MANAGE */
        IF @OrganizationalUnitID IS NOT NULL AND NOT (@OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@CreateUser) uu WHERE uu.UnitID = @OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@CreateUser, N'CALENDAR_UNIT_MANAGE')))
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'اجازهٔ ثبتِ رویداد در تقویمِ این واحد را ندارید.' AS Message;
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
             CreatedByUserID, OwnerUserID, OrganizationalUnitID, IsPrivate, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Title, @Description, @StartDateTime, @EndDateTime, @IsAllDay, @Color, @Status,
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate,
             @CreateUser, @OwnerUserID, @OrganizationalUnitID, ISNULL(@IsPrivate, 0), SYSDATETIME(), @CreateUser);

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
    @ModifyUser           INT,
    @IsPrivate            BIT = NULL       -- NULL = همان مقدارِ فعلی (جابه‌جایی و فراخوانی‌هایِ قدیمی تغییرش نمی‌دهند)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT, @WasPrivate BIT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID, @WasPrivate = IsPrivate
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvents
ALTER PROCEDURE [dbo].[sp_GetCalendarEvents]
    @UserID    INT,               -- صاحب تقویمی که رویدادهایش را می‌خواهیم (خود کاربر یا کاربر دیگر با مجوز)
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0),
    @ViewerID  INT = NULL         -- بیننده (NULL = خودِ صاحبِ تقویم)؛ رویدادِ خصوصی برایِ غیرِ شرکت‌کننده پنهان می‌شود
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Viewer INT = ISNULL(@ViewerID, @UserID);

    SELECT
        e.EventID,
        CASE WHEN vp.HideDetails = 1 THEN N'مشغول' ELSE e.Title END AS Title,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.Description END AS Description,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.Color,
        e.Status,
        e.RecurrenceType,
        e.RecurrenceInterval,
        e.RecurrenceEndDate,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.CreatedByUserID END AS CreatedByUserID,
        e.OwnerUserID,
        e.OrganizationalUnitID,
        e.IsPrivate,
        vp.IsParticipant AS ViewerIsParticipant,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) END AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID THEN 1 ELSE 0 END AS BIT) AS CanManage,
        CASE
            WHEN e.CreatedByUserID = @UserID THEN N'CREATOR'
            WHEN e.OwnerUserID = @UserID THEN N'OWNER'
            ELSE N'ATTENDEE'
        END AS MyRelation,
        att.ResponseStatus AS MyResponseStatus,
        rem.OffsetMinutes  AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att
        ON att.EventID = e.EventID AND att.UserID = @UserID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem
        ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID
       AND rem.UserID = @UserID AND rem.IsActive = 1
    CROSS APPLY (SELECT CAST(CASE WHEN e.CreatedByUserID = @Viewer OR e.OwnerUserID = @Viewer
                         OR EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees va WHERE va.EventID = e.EventID AND va.UserID = @Viewer AND va.IsActive = 1)
                    THEN 1 ELSE 0 END AS BIT) AS IsParticipant) vpp
    CROSS APPLY (SELECT vpp.IsParticipant, CAST(CASE WHEN e.IsPrivate = 1 AND vpp.IsParticipant = 0 THEN 1 ELSE 0 END AS BIT) AS HideDetails) vp
    WHERE e.IsActive = 1
      AND (e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR att.CalendarEventAttendeeID IS NOT NULL)
      AND (
            (e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
            OR
            (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate
             AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE)))
          )
    ORDER BY e.StartDateTime;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvent
ALTER PROCEDURE [dbo].[sp_GetCalendarEvent]
    @EventID BIGINT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        e.EventID,
        e.Title,
        e.Description,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.Color,
        e.Status,
        e.RecurrenceType,
        e.RecurrenceInterval,
        e.RecurrenceEndDate,
        e.CreatedByUserID,
        e.OwnerUserID,
        e.OrganizationalUnitID,
        e.IsPrivate,
        dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        e.Date_InsertFirst,
        e.Date_LastUpdate,
        e.UserID_LastUpdate,
        CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE'))) THEN 1 ELSE 0 END AS BIT) AS CanManage, /* + مدیرِ رویدادِ واحد */
        CASE
            WHEN e.CreatedByUserID = @UserID THEN N'CREATOR'
            WHEN e.OwnerUserID = @UserID THEN N'OWNER'
            WHEN EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) THEN N'ATTENDEE'
            WHEN (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID)) THEN N'UNIT' /* عضوِ مستقیمِ واحدِ رویداد */
            ELSE N'NONE'
        END AS MyRelation,
        (SELECT a.ResponseStatus FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) AS MyResponseStatus
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    WHERE e.EventID = @EventID AND e.IsActive = 1
      /* خصوصی → فقط سازنده، مالک و شرکت‌کنندهٔ فعال (حتی CALENDAR_VIEW_OTHERS/اشتراکِ READ/عضوِ واحد نه) */
      AND (e.IsPrivate = 0 OR e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID
           OR EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees pa WHERE pa.EventID = e.EventID AND pa.UserID = @UserID AND pa.IsActive = 1));
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUnitCalendarEvents
/* رویدادهایِ تقویمِ یک واحد در بازه. فقط برایِ عضوِ مستقیمِ فعالِ واحد یا دارندهٔ CALENDAR_VIEW_OTHERS (با CALENDAR_VIEW).
   MyRelation = CREATOR | OWNER | ATTENDEE | UNIT ؛ CanManage = سازنده/مالک یا عضوِ دارایِ CALENDAR_UNIT_MANAGE. */
ALTER PROCEDURE dbo.sp_GetUnitCalendarEvents
    @UnitID   INT,
    @UserID   INT,
    @FromDate DATETIME2(0),
    @ToDate   DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
       OR NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
       OR NOT ((@UnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @UnitID)) OR EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW_OTHERS')))
        RETURN;
    SELECT e.EventID, e.Title, e.Description, e.StartDateTime, e.EndDateTime, e.IsAllDay, e.Color, e.Status,
           e.RecurrenceType, e.RecurrenceInterval, e.RecurrenceEndDate, e.CreatedByUserID, e.OwnerUserID, e.OrganizationalUnitID, e.IsPrivate,
           dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS CreatorName,
           dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
           CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE'))) THEN 1 ELSE 0 END AS BIT) AS CanManage,
           CASE WHEN e.CreatedByUserID = @UserID THEN N'CREATOR' WHEN e.OwnerUserID = @UserID THEN N'OWNER'
                WHEN att.CalendarEventAttendeeID IS NOT NULL THEN N'ATTENDEE' ELSE N'UNIT' END AS MyRelation,
           att.ResponseStatus AS MyResponseStatus, rem.OffsetMinutes AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att ON att.EventID = e.EventID AND att.UserID = @UserID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID AND rem.UserID = @UserID AND rem.IsActive = 1
    WHERE e.IsActive = 1 AND e.OrganizationalUnitID = @UnitID AND e.IsPrivate = 0
      AND ((e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
        OR (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE))))
    ORDER BY e.StartDateTime;
END
GO

